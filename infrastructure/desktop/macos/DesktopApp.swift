import AppKit
import CryptoKit
import Security
import SwiftUI
import SystemConfiguration
import UniformTypeIdentifiers

private func label(_ key: String) -> String {
    NSLocalizedString(key, comment: "")
}

private struct BackupScheduleSettings: Codable {
    var frequency = "off"
    var directory = ""
    var keep = 7
    var hasPassword = false
    var lastSuccess: Int64?
    var failed = false
}

private struct Settings: Codable {
    var lanEnabled = false
    var lanBind = ""
    var lanHost = ""
    var lanPort = "8443"
    var lanCert = ""
    var lanKey = ""
    var lanFingerprint = ""
    var lanAuthority = ""
    var lanPreviousAuthority = ""
    var lanPreviousFingerprint = ""
    var backupSchedule = BackupScheduleSettings()
    var configured = false
    var port = "8420"
    var host = ""
    var smtpPort = "587"
    var sender = ""
    var senderName = "Canabicultarr"
    var username = ""
}

private enum SettingsSection: String, CaseIterable, Identifiable {
    case general, mail, network, backups
    var id: Self { self }
}

private enum CertificateAction: String, Identifiable {
    case replace, defaults, remove
    var id: Self { self }

    var title: String {
        switch self {
        case .replace: return "replaceCertificate"
        case .defaults: return "defaultCertificate"
        case .remove: return "clearCertificate"
        }
    }
}

private struct NetworkTrustFiles: Identifiable {
    let certificate: URL
    let iphoneProfile: URL
    var id: URL { certificate }
}

private enum NetworkCertificateTrust {
    static func serverCertificate(at path: String) -> SecCertificate? {
        let header = "-----BEGIN CERTIFICATE-----"
        let footer = "-----END CERTIFICATE-----"
        guard let pem = try? String(contentsOfFile: path, encoding: .utf8),
              let body = pem.components(separatedBy: header).dropFirst().first?.components(separatedBy: footer).first,
              let der = Data(base64Encoded: body, options: .ignoreUnknownCharacters) else { return nil }
        return SecCertificateCreateWithData(nil, der as CFData)
    }

    static func evaluate(_ certificate: SecCertificate, hostname: String, anchor: SecCertificate? = nil) -> Bool {
        var trust: SecTrust?
        let policy = SecPolicyCreateSSL(true, hostname as CFString)
        guard !hostname.isEmpty, SecTrustCreateWithCertificates(certificate, policy, &trust) == errSecSuccess,
              let trust else { return false }
        SecTrustSetNetworkFetchAllowed(trust, false)
        if let anchor {
            guard SecTrustSetAnchorCertificates(trust, [anchor] as CFArray) == errSecSuccess else { return false }
        }
        return SecTrustEvaluateWithError(trust, nil)
    }

    static func matchingAuthority(_ url: URL, fingerprint: String) -> SecCertificate? {
        guard let data = try? Data(contentsOf: url) else { return nil }
        let actual = SHA256.hash(data: data).map { String(format: "%02X", $0) }.joined(separator: ":")
        guard actual == fingerprint else { return nil }
        return SecCertificateCreateWithData(nil, data as CFData)
    }

    static func approve(_ authority: SecCertificate, for hostname: String) -> OSStatus {
        let certificate = [kSecClass as String: kSecClassCertificate, kSecValueRef as String: authority] as CFDictionary
        let status = SecItemAdd(certificate, nil)
        guard status == errSecSuccess || status == errSecDuplicateItem else { return status }
        let policy = SecPolicyCreateSSL(true, nil)
        let constraints: [String: Any] = [
            "kSecTrustSettingsPolicy": policy,
            "kSecTrustSettingsPolicyString": hostname,
        ]
        return SecTrustSettingsSetTrustSettings(authority, .user, [constraints] as CFArray)
    }
}

private enum DesktopError: LocalizedError {
    case message(String)

    var errorDescription: String? {
        switch self {
        case .message(let message): return message
        }
    }
}

@MainActor
private final class Desktop: ObservableObject {
    @Published var settings = Settings()
    @Published var password = ""
    @Published var busy = false
    @Published var running = false
    @Published var error = ""
    @Published var needsAdministrator = false
    @Published var activationEmail = ""
    @Published var administratorName = ""
    @Published var administratorEmail = ""
    @Published var administratorPassword = ""
    @Published var passwordConfirmation = ""
    @Published var showConfiguration = false
    @Published var archivePassword = ""
    @Published var archiveStatus = ""
    @Published var schedulePassword = ""
    @Published var trustFiles: NetworkTrustFiles?
    @Published var certificateAction: CertificateAction?
    @Published var settingsSection = SettingsSection.mail
    @Published var networkTrustResult: Bool?
    @Published var networkTrustBusy = false
    @Published var networkTrustMessage = ""

    private let files = FileManager.default
    private var trustDirectory: URL?
    private var server: Process?
    private var setupClient: DesktopSetupClient?
    private var loaded = false

    private var resources: URL { Bundle.main.resourceURL! }
    private var data: URL {
        let home = files.homeDirectoryForCurrentUser
        return home.appendingPathComponent("Library/Application Support/Canabicultarr", isDirectory: true)
    }
    private var destination: URL {
        files.homeDirectoryForCurrentUser.appendingPathComponent("Applications/Canabicultarr.app", isDirectory: true)
    }
    var installed: Bool {
        Bundle.main.bundleURL.resolvingSymlinksInPath().path == destination.resolvingSymlinksInPath().path
    }
    var installationMode: DesktopInstallation.Mode {
        DesktopInstallation.mode(from: Bundle.main.bundleURL, to: destination)
    }
    private var origin: URL? {
        guard let port = Int(settings.port), (1024...65535).contains(port) else { return nil }
        return URL(string: "http://127.0.0.1:\(port)")
    }

    private func process(_ command: String, runID: String? = nil) -> Process {
        let process = Process()
        let runtime = resources.appendingPathComponent("runtime")
        process.executableURL = runtime.appendingPathComponent("php")
        process.arguments = [resources.appendingPathComponent("project/infrastructure/desktop/run.php").path, command]
        process.environment = [
            "PATH": "/usr/bin:/bin:/usr/sbin:/sbin",
            "HOME": files.homeDirectoryForCurrentUser.path,
            "CANABICULTARR_DESKTOP_DATA_DIR": data.path,
            "PHPRC": runtime.appendingPathComponent("php.ini").path,
            "PHP_INI_SCAN_DIR": runtime.appendingPathComponent("ini").path,
            "CANABICULTARR_CA_FILE": runtime.appendingPathComponent("cert.pem").path,
        ]
        if let runID { process.environment?["CANABICULTARR_DESKTOP_RUN_ID"] = runID }
        return process
    }

    private func control(_ command: String, input: Data? = nil) throws -> Data {
        try Self.execute(process(command), input: input)
    }

    private nonisolated static func execute(_ subprocess: Process, input: Data?) throws -> Data {
        let stdout = Pipe()
        let stderr = Pipe()
        let stdin = Pipe()
        subprocess.standardOutput = stdout
        subprocess.standardError = stderr
        subprocess.standardInput = stdin
        try subprocess.run()
        if let input {
            try stdin.fileHandleForWriting.write(contentsOf: input)
        }
        try stdin.fileHandleForWriting.close()
        let result = stdout.fileHandleForReading.readDataToEndOfFile()
        let details = stderr.fileHandleForReading.readDataToEndOfFile()
        subprocess.waitUntilExit()
        if subprocess.terminationStatus != 0 {
            let message = String(decoding: details, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
            throw DesktopError.message(message.isEmpty ? label("operationFailed") : message)
        }
        return result
    }

    func load() async {
        guard !loaded else { return }
        loaded = true
        do {
            settings = try JSONDecoder().decode(Settings.self, from: control("settings"))
            if installed && settings.configured {
                await start()
            } else {
                showConfiguration = true
            }
        } catch {
            showConfiguration = true
            self.error = error.localizedDescription
        }
    }

    func transferBackup() async {
        guard !busy, server == nil, (12...256).contains(archivePassword.utf8.count) else { return }
        let restoring = !settings.configured
        let panel: NSSavePanel
        if restoring {
            let picker = NSOpenPanel()
            picker.canChooseDirectories = false
            picker.allowsMultipleSelection = false
            panel = picker
        } else {
            let picker = NSSavePanel()
            picker.nameFieldStringValue = "canabicultarr-desktop-backup.zip"
            panel = picker
        }
        panel.allowedContentTypes = [.zip]
        guard panel.runModal() == .OK, let file = panel.url else { return }
        busy = true
        error = ""
        archiveStatus = ""
        defer { busy = false }
        do {
            let input = try JSONEncoder().encode(["path": file.path, "password": archivePassword])
            archivePassword = ""
            let subprocess = process(restoring ? "restore" : "backup")
            _ = try await Task.detached { try Self.execute(subprocess, input: input) }.value
            settings = try JSONDecoder().decode(Settings.self, from: control("settings"))
            archiveStatus = label(restoring ? "restoreComplete" : "backupComplete")
        } catch {
            self.error = label(restoring ? "restoreFailed" : "backupFailed")
        }
    }

    func setNetworkEnabled(_ enabled: Bool) {
        settings.lanEnabled = enabled
        guard enabled, settings.lanBind.isEmpty || settings.lanHost.isEmpty,
              let localName = SCDynamicStoreCopyLocalHostName(nil) else { return }
        let name = "\(localName).local"
        if settings.lanBind.isEmpty { settings.lanBind = name }
        if settings.lanHost.isEmpty { settings.lanHost = name }
    }

    func manageNetworkCertificate(_ action: CertificateAction? = nil) {
        guard !busy, server == nil, settings.configured else { return }
        error = ""
        archiveStatus = ""
        do {
            let host = settings.lanHost.trimmingCharacters(in: .whitespacesAndNewlines)
            struct Request: Encodable {
                let hostname: String
                let replace: Bool
                let defaults: Bool
                let defaultHostname: String?
                let confirm: Bool
            }
            let input = try JSONEncoder().encode(Request(
                hostname: host.isEmpty ? settings.lanBind : host,
                replace: action == .replace || action == .defaults,
                defaults: action == .defaults,
                defaultHostname: SCDynamicStoreCopyLocalHostName(nil).map { "\($0).local" },
                confirm: action == .remove
            ))
            _ = try control(action == .remove ? "remove-lan-certificate" : "generate-lan-certificate", input: input)
            let result = try JSONDecoder().decode(Settings.self, from: control("settings"))
            settings.lanHost = result.lanHost
            settings.lanCert = result.lanCert
            settings.lanKey = result.lanKey
            settings.lanFingerprint = result.lanFingerprint
            settings.lanAuthority = result.lanAuthority
            settings.lanPreviousAuthority = result.lanPreviousAuthority
            settings.lanPreviousFingerprint = result.lanPreviousFingerprint
            if action == .defaults {
                settings.lanBind = result.lanBind
                settings.lanPort = result.lanPort
            }
            if action == .remove { settings.lanEnabled = false }
            archiveStatus = label(action == .remove ? "certificateCleared" : "lanCertificateGenerated")
        } catch {
            self.error = label(action == .remove ? "clearCertificateError" : "lanCertificateError")
        }
    }

    func checkNetworkTrust(approving url: URL? = nil) {
        guard !networkTrustBusy else { return }
        let path = settings.lanCert
        let hostname = settings.lanHost
        let fingerprint = settings.lanFingerprint
        networkTrustBusy = true
        networkTrustResult = nil
        networkTrustMessage = ""
        Task {
            let outcome = await Task.detached { () -> (trusted: Bool, message: String) in
                guard let leaf = NetworkCertificateTrust.serverCertificate(at: path) else { return (false, "") }
                if NetworkCertificateTrust.evaluate(leaf, hostname: hostname) { return (true, "") }
                if let url {
                    guard let root = NetworkCertificateTrust.matchingAuthority(url, fingerprint: fingerprint),
                          NetworkCertificateTrust.evaluate(leaf, hostname: hostname, anchor: root) else {
                        return (false, "trustMacAddressMismatch")
                    }
                    let status = NetworkCertificateTrust.approve(root, for: hostname)
                    if status != errSecSuccess {
                        return (false, status == errSecUserCanceled ? "trustMacCanceled" : "trustMacApprovalFailed")
                    }
                }
                return (NetworkCertificateTrust.evaluate(leaf, hostname: hostname), "")
            }.value
            networkTrustResult = outcome.trusted
            networkTrustMessage = outcome.message
            networkTrustBusy = false
        }
    }

    func showNetworkTrust() {
        guard !settings.lanFingerprint.isEmpty else { return }
        error = ""
        networkTrustResult = nil
        networkTrustMessage = ""
        do {
            let directory: URL
            if let trustDirectory {
                directory = trustDirectory
            } else {
                directory = files.temporaryDirectory.appendingPathComponent(UUID().uuidString, isDirectory: true)
                try files.createDirectory(at: directory, withIntermediateDirectories: false,
                                          attributes: [.posixPermissions: 0o700])
                trustDirectory = directory
            }
            let certificate = directory.appendingPathComponent("Canabicultarr-Desktop-CA.cer")
            let profile = directory.appendingPathComponent("Canabicultarr-Desktop.mobileconfig")
            try control("export-lan-certificate").write(to: certificate, options: .atomic)
            try control("export-lan-profile").write(to: profile, options: .atomic)
            trustFiles = NetworkTrustFiles(certificate: certificate, iphoneProfile: profile)
        } catch {
            self.error = label("operationFailed")
        }
    }

    func saveNetworkCertificate() {
        let picker = NSSavePanel()
        picker.allowedContentTypes = [UTType(filenameExtension: "cer") ?? .data]
        picker.nameFieldStringValue = "Canabicultarr-Desktop-CA.cer"
        guard picker.runModal() == .OK, let destination = picker.url else { return }
        do {
            try control("export-lan-certificate").write(to: destination, options: .atomic)
        } catch {
            self.error = label("operationFailed")
        }
    }

    func saveRuntimeLog() {
        do {
            let contents = try control("runtime-log")
            let picker = NSSavePanel()
            picker.allowedContentTypes = [UTType(filenameExtension: "log") ?? .plainText]
            picker.nameFieldStringValue = "Canabicultarr-runtime.log"
            guard picker.runModal() == .OK, let destination = picker.url else { return }
            try contents.write(to: destination, options: .atomic)
        } catch {
            self.error = label("operationFailed")
        }
    }

    func chooseNetworkFile(key: Bool) {
        let panel = NSOpenPanel()
        panel.canChooseDirectories = false
        panel.allowsMultipleSelection = false
        guard panel.runModal() == .OK, let file = panel.url else { return }
        if key {
            settings.lanKey = file.path
        } else {
            settings.lanCert = file.path
        }
    }

    var networkURL: URL? {
        guard settings.lanEnabled else { return nil }
        return URL(string: "https://\(settings.lanHost):\(settings.lanPort)")
    }

    func chooseBackupDirectory() {
        let panel = NSOpenPanel()
        panel.canChooseDirectories = true
        panel.canChooseFiles = false
        panel.canCreateDirectories = true
        if panel.runModal() == .OK, let folder = panel.url {
            settings.backupSchedule.directory = folder.path
        }
    }

    func saveBackupSchedule() {
        guard server == nil else { return }
        error = ""
        archiveStatus = ""
        do {
            let schedule = settings.backupSchedule
            let input = try JSONEncoder().encode([
                "frequency": schedule.frequency, "directory": schedule.directory,
                "keep": String(schedule.keep), "password": schedulePassword,
            ])
            _ = try control("configure-backups", input: input)
            schedulePassword = ""
            try reloadBackupSchedule()
            archiveStatus = label("scheduleSaved")
        } catch {
            self.error = label("scheduleError")
        }
    }

    private func reloadBackupSchedule() throws {
        settings.backupSchedule = try JSONDecoder().decode(Settings.self, from: control("settings")).backupSchedule
    }

    func gmail() {
        settings.host = "smtp.gmail.com"
        settings.smtpPort = "587"
        if settings.username.isEmpty { settings.username = settings.sender }
    }

    func installOrSave() async {
        busy = true
        error = ""
        defer { busy = false }
        do {
            if !installed && installationMode != .install {
                throw DesktopError.message(label("alreadyInstalled"))
            }
            let fields = [
                "port": settings.port, "host": settings.host, "smtpPort": settings.smtpPort,
                "sender": settings.sender, "senderName": settings.senderName,
                "username": settings.username.isEmpty && settings.host == "smtp.gmail.com" ? settings.sender : settings.username,
                "password": settings.host == "smtp.gmail.com" ? password.filter { !$0.isWhitespace } : password,
                "lanEnabled": settings.lanEnabled ? "1" : "0",
                "lanBind": settings.lanBind, "lanHost": settings.lanHost, "lanPort": settings.lanPort,
                "lanCert": settings.lanCert, "lanKey": settings.lanKey,
            ]
            _ = try control("configure", input: JSONEncoder().encode(fields))
            settings = try JSONDecoder().decode(Settings.self, from: control("settings"))
            password = ""
            if installed {
                showConfiguration = false
                await start()
                return
            }
            try await copyApplication()
            openInstalledApplication()
        } catch {
            self.error = error.localizedDescription
        }
    }

    func update() async {
        guard !busy, installationMode == .update else { return }
        let running = NSRunningApplication.runningApplications(withBundleIdentifier: DesktopInstallation.bundleID)
        if running.contains(where: { $0.bundleURL?.resolvingSymlinksInPath().path == destination.resolvingSymlinksInPath().path }) {
            error = label("quitBeforeUpdate")
            return
        }
        busy = true
        error = ""
        defer { busy = false }
        do {
            try await copyApplication()
            openInstalledApplication()
        } catch let failure as POSIXError where failure.code == .EBUSY {
            error = label("quitBeforeUpdate")
        } catch {
            self.error = label("updateFailed")
        }
    }

    private func copyApplication() async throws {
        let source = Bundle.main.bundleURL
        let target = destination
        let profile = data
        try await Task.detached {
            try DesktopInstallation.copy(from: source, to: target, profile: profile)
        }.value
    }

    func openInstalledApplication() {
        let configuration = NSWorkspace.OpenConfiguration()
        configuration.createsNewApplicationInstance = true
        NSWorkspace.shared.openApplication(at: destination, configuration: configuration) { _, launchError in
            Task { @MainActor in
                if let launchError {
                    self.error = launchError.localizedDescription
                } else {
                    NSApp.terminate(nil)
                }
            }
        }
    }

    private var startupFailure: String {
        label(settings.lanEnabled ? "lanStartupFailed" : "startupFailed")
    }

    func start() async {
        guard installed, server == nil, let origin else { return }
        busy = true
        error = ""
        defer { busy = false }
        do {
            let runID = UUID().uuidString.lowercased()
            let client = DesktopSetupClient(origin: origin, runID: runID)
            let process = process("serve", runID: runID)
            process.standardOutput = FileHandle.nullDevice
            process.standardError = FileHandle.nullDevice
            process.standardInput = FileHandle.nullDevice
            process.terminationHandler = { [weak self] stopped in
                Task { @MainActor in
                    guard self?.server === stopped else { return }
                    self?.server = nil
                    self?.running = false
                    self?.setupClient = nil
                    self?.needsAdministrator = false
                    if stopped.terminationStatus != 0 { self?.error = self?.startupFailure ?? "" }
                }
            }
            try process.run()
            server = process
            try await Task.sleep(nanoseconds: 450_000_000)
            for _ in 0..<30 {
                guard process.isRunning else { throw DesktopError.message(startupFailure) }
                if let requiresSetup = try? await client.requiresSetup(), process.isRunning {
                    running = true
                    setupClient = client
                    needsAdministrator = requiresSetup
                    return
                }
                try await Task.sleep(nanoseconds: 250_000_000)
            }
            throw DesktopError.message(startupFailure)
        } catch {
            stop()
            self.error = error.localizedDescription
        }
    }

    private func token() throws -> String {
        String(decoding: try control("setup-token"), as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
    }

    var canCreateAdministrator: Bool {
        !administratorName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            && administratorEmail.contains("@")
            && (12...128).contains(administratorPassword.count)
            && administratorPassword == passwordConfirmation
    }

    func createAdministrator() async {
        guard !busy, let setupClient, canCreateAdministrator else { return }
        busy = true
        error = ""
        defer { busy = false }
        do {
            let email = administratorEmail.trimmingCharacters(in: .whitespacesAndNewlines)
            try await setupClient.create(.init(
                token: try token(),
                name: administratorName.trimmingCharacters(in: .whitespacesAndNewlines),
                email: email,
                password: administratorPassword,
                password_confirmation: passwordConfirmation,
                locale: Locale.preferredLanguages.first?.hasPrefix("fr") == true ? "fr" : "en"
            ))
            administratorPassword = ""
            passwordConfirmation = ""
            activationEmail = email
            needsAdministrator = false
        } catch DesktopSetupClient.Failure.validation {
            error = label("administratorInvalid")
        } catch DesktopSetupClient.Failure.activationUnavailable {
            error = label("activationUnavailable")
        } catch {
            self.error = label("administratorFailed")
        }
    }

    func open() {
        guard running, let port = origin?.port, let localURL = URL(string: "http://localhost:\(port)") else { return }
        NSWorkspace.shared.open(networkURL ?? localURL)
    }

    var keepsRunningWithoutWindow: Bool { installed && server != nil }

    func stopServer() async {
        busy = true
        defer { busy = false }
        let active = server
        stop()
        while active?.isRunning == true {
            try? await Task.sleep(nanoseconds: 100_000_000)
        }
    }

    func editConfiguration() async {
        await stopServer()
        try? reloadBackupSchedule()
        showConfiguration = true
    }

    func shutDown() {
        stop()
        if let trustDirectory { try? files.removeItem(at: trustDirectory) }
    }

    func stop() {
        let process = server
        server = nil
        running = false
        setupClient = nil
        needsAdministrator = false
        if process?.isRunning == true { process?.terminate() }
    }
}

private final class LaunchDelegate: NSObject, NSApplicationDelegate {
    var stop: (() -> Void)?
    var keepsRunningWithoutWindow: (() -> Bool)?

    func applicationWillTerminate(_ notification: Notification) { stop?() }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
        keepsRunningWithoutWindow?() != true
    }
}

@main
struct CanabicultarrDesktop: App {
    @NSApplicationDelegateAdaptor(LaunchDelegate.self) private var delegate
    @StateObject private var desktop = Desktop()
    var body: some Scene {
        WindowGroup {
            DesktopWindow(desktop: desktop)
                .onAppear {
                    delegate.stop = desktop.shutDown
                    delegate.keepsRunningWithoutWindow = { [desktop] in desktop.keepsRunningWithoutWindow }
                    Task { await desktop.load() }
                }
        }
        .windowResizability(.contentSize)
    }
}

private struct DesktopWindow: View {
    @ObservedObject var desktop: Desktop
    @Environment(\.colorScheme) private var colorScheme

    private var brand: DesktopBrand { DesktopBrand(scheme: colorScheme) }

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            HStack(spacing: 14) {
                if let mark = DesktopBrand.mark {
                    Image(nsImage: mark)
                        .resizable()
                        .scaledToFit()
                        .padding(5)
                        .frame(width: 56, height: 56)
                        .background(brand.markSurface, in: RoundedRectangle(cornerRadius: 13))
                        .accessibilityHidden(true)
                }
                Text(label(desktop.installed ? "title" : desktop.installationMode == .update ? "updateTitle" : "installTitle"))
                    .font(.largeTitle.weight(.semibold))
                    .foregroundStyle(brand.heading)
                    .accessibilityAddTraits(.isHeader)
            }
            if !desktop.installed && desktop.installationMode != .install {
                installationActions
            } else if desktop.showConfiguration {
                configuration
            } else {
                activation
            }
            if desktop.installed && desktop.settings.configured {
                Button(label("runtimeLog"), action: desktop.saveRuntimeLog)
                    .buttonStyle(.link)
                    .disabled(desktop.busy)
            }
            if !desktop.error.isEmpty {
                Text(desktop.error).foregroundStyle(.red).textSelection(.enabled)
            }
        }
        .padding(28)
        .frame(minWidth: 540)
        .background {
            brand.canvas.overlay {
                LinearGradient(colors: [brand.canvasTint, .clear], startPoint: .topLeading, endPoint: .bottomTrailing)
            }
            .ignoresSafeArea()
        }
        .tint(brand.accent)
        .sheet(item: $desktop.trustFiles) { files in
            networkTrustHelp(files)
        }
        .confirmationDialog(
            label(desktop.certificateAction?.title ?? "replaceCertificate"),
            isPresented: Binding(
                get: { desktop.certificateAction != nil },
                set: { if !$0 { desktop.certificateAction = nil } }
            ),
            titleVisibility: .visible,
            presenting: desktop.certificateAction
        ) { action in
            Button(label(action.title), role: .destructive) { desktop.manageNetworkCertificate(action) }
        } message: { action in
            Text(label(action.title + "Help"))
        }
    }

    private func networkTrustHelp(_ files: NetworkTrustFiles) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text(label("trustCertificate")).font(.title2.weight(.semibold))
                Text(label("trustMacHelp")).fixedSize(horizontal: false, vertical: true)
                HStack {
                    Button(label("trustApproveMac")) { desktop.checkNetworkTrust(approving: files.certificate) }
                        .buttonStyle(.borderedProminent)
                    Button(label("trustCheckMac")) { desktop.checkNetworkTrust() }
                    if desktop.networkTrustBusy { ProgressView().controlSize(.small) }
                }
                .disabled(desktop.networkTrustBusy)
                Button(label("trustOpenMac")) { NSWorkspace.shared.open(files.certificate) }
                    .buttonStyle(.link)
                if let result = desktop.networkTrustResult {
                    let message = desktop.networkTrustMessage
                    Label(label(message.isEmpty ? (result ? "trustMacAccepted" : "trustMacRejected") : message),
                          systemImage: result ? "checkmark.circle" : "exclamationmark.triangle")
                        .foregroundStyle(result ? Color.green : Color.orange)
                }
                Divider()
                iphoneTrustInstructions
                ShareLink(item: files.iphoneProfile) { Label(label("trustShareIphone"), systemImage: "square.and.arrow.up") }
                Divider()
                Text(label("trustAndroidHelp")).fixedSize(horizontal: false, vertical: true)
                HStack {
                    ShareLink(item: files.certificate) { Label(label("trustShareCertificate"), systemImage: "square.and.arrow.up") }
                    Button(label("downloadTrust"), action: desktop.saveNetworkCertificate)
                }
                if !desktop.settings.lanAuthority.isEmpty {
                    Text(desktop.settings.lanAuthority).font(.callout).textSelection(.enabled)
                }
                Text("SHA-256 · " + desktop.settings.lanFingerprint)
                    .font(.caption.monospaced()).textSelection(.enabled)
                DisclosureGroup(label("removeCertificate")) {
                    VStack(alignment: .leading, spacing: 7) {
                        if !desktop.settings.lanPreviousFingerprint.isEmpty {
                            Text(label("previousCertificate")).font(.callout.weight(.semibold))
                            Text(desktop.settings.lanPreviousAuthority).textSelection(.enabled)
                            Text("SHA-256 · " + desktop.settings.lanPreviousFingerprint).font(.caption.monospaced()).textSelection(.enabled)
                        }
                        Text(label("removeMacHelp"))
                        Text(label("removeIphoneHelp"))
                        Text(label("removeAndroidHelp"))
                        Text(label("removePairingHelp"))
                    }
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.top, 6)
                }
            }
            .padding(24)
        }
        .frame(width: 530, height: 515)
        .safeAreaInset(edge: .bottom, alignment: .trailing) {
            Button(label("trustDone")) { desktop.trustFiles = nil }
                .keyboardShortcut(.cancelAction)
                .padding(14)
        }
    }

    @ViewBuilder private var installationActions: some View {
        switch desktop.installationMode {
        case .update:
            Text(label("updateDescription")).foregroundStyle(.secondary)
            Button(label("updateAndOpen")) { Task { await desktop.update() } }
                .buttonStyle(.borderedProminent)
                .disabled(desktop.busy)
        case .current:
            Text(label("alreadyInstalled")).foregroundStyle(.secondary)
            Button(label("openCanabicultarr"), action: desktop.openInstalledApplication)
                .buttonStyle(.borderedProminent)
        default:
            Text(label("unknownInstallation")).foregroundStyle(.secondary)
        }
    }

    @ViewBuilder private var backupControls: some View {
        DisclosureGroup(label(desktop.settings.configured ? "backup" : "restore")) {
            HStack {
                SecureField(label("archivePassword"), text: $desktop.archivePassword)
                    .textFieldStyle(.roundedBorder)
                Button(label(desktop.settings.configured ? "backup" : "restore")) {
                    Task { await desktop.transferBackup() }
                }
                .disabled(!(12...256).contains(desktop.archivePassword.utf8.count))
            }.padding(.top, 8)
        }
        .disabled(desktop.busy)
        if !desktop.archiveStatus.isEmpty {
            Text(desktop.archiveStatus).foregroundStyle(.secondary)
        }
    }

    private var automaticBackupControls: some View {
        DisclosureGroup(label("schedule")) {
            VStack(alignment: .leading, spacing: 10) {
                Picker(label("frequency"), selection: $desktop.settings.backupSchedule.frequency) {
                    ForEach(["off", "daily", "weekly"], id: \.self) { frequency in
                        Text(label(frequency)).tag(frequency)
                    }
                }
                if desktop.settings.backupSchedule.frequency != "off" {
                    HStack {
                        TextField(label("destination"), text: $desktop.settings.backupSchedule.directory)
                            .textFieldStyle(.roundedBorder)
                        Button(label("chooseDirectory"), action: desktop.chooseBackupDirectory)
                    }
                    Stepper(value: $desktop.settings.backupSchedule.keep, in: 1...90) {
                        Text("\(label("retention")) · \(desktop.settings.backupSchedule.keep)")
                    }
                    SecureField(label(desktop.settings.backupSchedule.hasPassword ? "passwordStored" : "archivePassword"),
                                text: $desktop.schedulePassword)
                        .textFieldStyle(.roundedBorder)
                }
                if let last = desktop.settings.backupSchedule.lastSuccess {
                    Text("\(label("lastBackup")) · \(Date(timeIntervalSince1970: TimeInterval(last)).formatted())")
                        .foregroundStyle(.secondary)
                }
                if desktop.settings.backupSchedule.failed {
                    Text(label("scheduleFailed")).foregroundStyle(.secondary)
                }
                Button(label("scheduleSave"), action: desktop.saveBackupSchedule)
            }
            .padding(.top, 8)
        }
        .disabled(desktop.busy)
    }

    private var configuration: some View {
        VStack(alignment: .leading, spacing: 14) {
            Text(label("installDescription")).foregroundStyle(.secondary)
                .fixedSize(horizontal: false, vertical: true)
            Picker(label("settingsSections"), selection: $desktop.settingsSection) {
                ForEach(SettingsSection.allCases) { section in
                    Text(label("settings." + section.rawValue)).tag(section)
                }
            }
            .pickerStyle(.segmented)
            .labelsHidden()
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    switch desktop.settingsSection {
                    case .general:
                        TextField(label("localPort"), text: $desktop.settings.port)
                    case .mail:
                        mailSettings
                    case .network:
                        networkSettings
                    case .backups:
                        backupControls
                        if desktop.settings.configured { automaticBackupControls }
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(2)
            }
            .frame(height: 295)
            Divider()
            HStack {
                if desktop.busy { ProgressView().controlSize(.small) }
                Spacer()
                Button(label(desktop.installed ? "saveAndStart" : "installAndOpen")) { Task { await desktop.installOrSave() } }
                    .buttonStyle(.borderedProminent)
                    .keyboardShortcut(.defaultAction)
            }
        }
        .textFieldStyle(.roundedBorder)
        .disabled(desktop.busy)
    }

    private var mailSettings: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Button(label("useGmail"), action: desktop.gmail)
                Link(label("gmailGuide"), destination: URL(string: "https://support.google.com/accounts/answer/185833")!)
            }
            Grid(alignment: .leading, horizontalSpacing: 14, verticalSpacing: 9) {
                GridRow { Text(label("smtpServer")); TextField("", text: $desktop.settings.host).accessibilityLabel(label("smtpServer")) }
                GridRow { Text(label("smtpPort")); TextField("", text: $desktop.settings.smtpPort).accessibilityLabel(label("smtpPort")) }
                GridRow { Text(label("senderEmail")); TextField("", text: $desktop.settings.sender).accessibilityLabel(label("senderEmail")) }
                GridRow { Text(label("senderName")); TextField("", text: $desktop.settings.senderName).accessibilityLabel(label("senderName")) }
                GridRow { Text(label("username")); TextField("", text: $desktop.settings.username).accessibilityLabel(label("username")) }
                GridRow { Text(label("password")); SecureField("", text: $desktop.password).accessibilityLabel(label("password")) }
            }
            if desktop.settings.host == "smtp.gmail.com" {
                Text(label("gmailInstructions")).font(.callout).foregroundStyle(.secondary)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
    }

    private var iphoneTrustInstructions: some View {
        VStack(alignment: .leading, spacing: 7) {
            Label(label("trustIphoneTitle"), systemImage: "iphone")
                .font(.headline)
                .accessibilityAddTraits(.isHeader)
            Text("1. " + label("trustIphoneInstall"))
            Text("2. " + label("trustIphoneEnable"))
            if !desktop.settings.lanAuthority.isEmpty {
                Text(desktop.settings.lanAuthority).font(.callout.weight(.medium)).textSelection(.enabled)
            }
            Text(label("trustIphoneNote")).font(.callout).foregroundStyle(.secondary)
        }
        .fixedSize(horizontal: false, vertical: true)
    }

    private var networkSettings: some View {
        VStack(alignment: .leading, spacing: 10) {
            Toggle(label("lanEnabled"), isOn: Binding(
                get: { desktop.settings.lanEnabled },
                set: desktop.setNetworkEnabled
            ))
            if desktop.settings.lanEnabled {
                Text(label("lanHelp")).font(.callout).foregroundStyle(.secondary)
                    .fixedSize(horizontal: false, vertical: true)
                Grid(alignment: .leading, horizontalSpacing: 14, verticalSpacing: 9) {
                    GridRow { Text(label("lanBind")); TextField("", text: $desktop.settings.lanBind).accessibilityLabel(label("lanBind")) }
                    GridRow { Text(label("lanHost")); TextField("", text: $desktop.settings.lanHost).accessibilityLabel(label("lanHost")) }
                    GridRow { Text(label("lanPort")); TextField("", text: $desktop.settings.lanPort).accessibilityLabel(label("lanPort")) }
                }
            }
            if desktop.settings.configured {
                HStack {
                    if desktop.settings.lanCert.isEmpty && desktop.settings.lanEnabled {
                        Button(label("generateCertificate")) { desktop.manageNetworkCertificate() }
                    }
                    if !desktop.settings.lanFingerprint.isEmpty {
                        Button(label("trustCertificate"), action: desktop.showNetworkTrust)
                    }
                    Menu(label("certificateOptions")) {
                        Button(label("defaultCertificate")) { desktop.certificateAction = .defaults }
                        if !desktop.settings.lanCert.isEmpty {
                            Button(label("replaceCertificate")) { desktop.certificateAction = .replace }
                            Button(label("clearCertificate"), role: .destructive) { desktop.certificateAction = .remove }
                        }
                    }
                    .fixedSize()
                }
                if !desktop.archiveStatus.isEmpty {
                    Text(desktop.archiveStatus).foregroundStyle(.secondary)
                }
                if !desktop.settings.lanFingerprint.isEmpty {
                    iphoneTrustInstructions
                }
                if desktop.settings.lanFingerprint.isEmpty && !desktop.settings.lanPreviousFingerprint.isEmpty {
                    DisclosureGroup(label("removeCertificate")) {
                        VStack(alignment: .leading, spacing: 6) {
                            Text(desktop.settings.lanPreviousAuthority).textSelection(.enabled)
                            Text("SHA-256 · " + desktop.settings.lanPreviousFingerprint).font(.caption.monospaced()).textSelection(.enabled)
                            Text(label("removeMacHelp"))
                            Text(label("removeIphoneHelp"))
                            Text(label("removeAndroidHelp"))
                        }
                        .fixedSize(horizontal: false, vertical: true)
                    }
                }
            }
            if desktop.settings.lanEnabled {
                DisclosureGroup(label("customCertificate")) {
                    VStack(alignment: .leading, spacing: 8) {
                        Text(label("customCertificateHelp")).font(.callout).foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                        HStack {
                            TextField(label("lanCert"), text: $desktop.settings.lanCert)
                            Button(label("chooseNetworkFile")) { desktop.chooseNetworkFile(key: false) }
                        }
                        HStack {
                            TextField(label("lanKey"), text: $desktop.settings.lanKey)
                            Button(label("chooseNetworkFile")) { desktop.chooseNetworkFile(key: true) }
                        }
                    }.padding(.top, 6)
                }
            }
        }
    }

    private var activation: some View {
        VStack(alignment: .leading, spacing: 12) {
            if desktop.running && desktop.needsAdministrator {
                administratorForm
            } else if desktop.running {
                Text(label("running")).foregroundStyle(.secondary)
                if !desktop.activationEmail.isEmpty {
                    Text(String(format: label("activationRequested"), desktop.activationEmail))
                }
                Button(label("openCanabicultarr"), action: desktop.open).buttonStyle(.borderedProminent)
                    .keyboardShortcut(.defaultAction)
                if let networkURL = desktop.networkURL {
                    Link(label("pairPhone"), destination: networkURL.appendingPathComponent("account").appendingPathComponent("security"))
                    Link(networkURL.absoluteString, destination: networkURL)
                } else {
                    Text(label("pairPhoneHelp")).font(.callout).foregroundStyle(.secondary)
                        .fixedSize(horizontal: false, vertical: true)
                }
                if !desktop.settings.lanFingerprint.isEmpty {
                    Button(label("trustCertificate"), action: desktop.showNetworkTrust)
                }
            } else if desktop.busy {
                ProgressView(label("starting"))
            } else {
                Button(label("start")) { Task { await desktop.start() } }
            }
            HStack {
                if desktop.running {
                    Button(label("stopServer")) { Task { await desktop.stopServer() } }
                }
                Button(label("changeSettings")) { Task { await desktop.editConfiguration() } }
            }
            .disabled(desktop.busy)
        }
    }
    private var administratorForm: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(label("administratorTitle")).font(.title2.weight(.semibold))
            Text(label("administratorHelp")).foregroundStyle(.secondary)
            Grid(alignment: .leading, horizontalSpacing: 14, verticalSpacing: 9) {
                GridRow { Text(label("administratorName")); TextField("", text: $desktop.administratorName).accessibilityLabel(label("administratorName")) }
                GridRow { Text(label("administratorEmail")); TextField("", text: $desktop.administratorEmail).accessibilityLabel(label("administratorEmail")) }
                GridRow { Text(label("administratorPassword")); SecureField("", text: $desktop.administratorPassword).accessibilityLabel(label("administratorPassword")) }
                GridRow { Text(label("passwordConfirmation")); SecureField("", text: $desktop.passwordConfirmation).accessibilityLabel(label("passwordConfirmation")) }
            }
            .textFieldStyle(.roundedBorder)
            HStack {
                if desktop.busy { ProgressView().controlSize(.small) }
                Spacer()
                Button(label("createAdministrator")) { Task { await desktop.createAdministrator() } }
                    .buttonStyle(.borderedProminent)
                    .keyboardShortcut(.defaultAction)
                    .disabled(!desktop.canCreateAdministrator)
            }
        }
        .disabled(desktop.busy)
    }

}
