import Darwin
import Foundation

@main
struct UpgradeSmoke {
    static func main() throws {
        let arguments = CommandLine.arguments
        guard arguments.count == 4 else { throw POSIXError(.EINVAL) }
        let source = URL(fileURLWithPath: arguments[1])
        let install = URL(fileURLWithPath: arguments[2])
        let profile = URL(fileURLWithPath: arguments[3])
        guard DesktopInstallation.mode(from: source, to: install) == .update else { throw POSIXError(.EINVAL) }

        let lock = open(profile.appendingPathComponent("run.lock").path, O_RDWR | O_NOFOLLOW)
        guard lock >= 0 else { throw POSIXError(.EACCES) }
        do {
            defer { close(lock) }
            guard flock(lock, LOCK_EX | LOCK_NB) == 0 else { throw POSIXError(.EBUSY) }
            do {
                try DesktopInstallation.copy(from: source, to: install, profile: profile)
                throw POSIXError(.EIO)
            } catch let error as POSIXError where error.code == .EBUSY {
                // The runtime lock kept the original application installed.
            }
        }
        let unrelated = install.deletingLastPathComponent().appendingPathComponent("Unrelated.app")
        try FileManager.default.createDirectory(at: unrelated, withIntermediateDirectories: false)
        do {
            try DesktopInstallation.copy(from: source, to: unrelated, profile: profile)
            throw POSIXError(.EIO)
        } catch let error as POSIXError where error.code == .EEXIST {}
        guard FileManager.default.fileExists(atPath: unrelated.path) else { throw POSIXError(.EIO) }

        try DesktopInstallation.copy(from: source, to: install, profile: profile)
        guard DesktopInstallation.mode(from: source, to: install) == .current else { throw POSIXError(.EIO) }
        print("macOS update: runtime lock, unrelated destination, and bundle swap passed.")
    }
}
