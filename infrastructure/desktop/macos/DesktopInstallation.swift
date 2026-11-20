import Darwin
import Foundation

enum DesktopInstallation {
    static let bundleID = "com.github.kinouzero.canabicultarr"

    enum Mode {
        case install, update, current, blocked
    }

    static func mode(from source: URL, to destination: URL) -> Mode {
        guard let installed = try? destination.resourceValues(forKeys: [.isDirectoryKey, .isSymbolicLinkKey]) else {
            let files = FileManager.default
            if files.fileExists(atPath: destination.path)
                || (try? files.destinationOfSymbolicLink(atPath: destination.path)) != nil {
                return .blocked
            }
            return .install
        }
        guard installed.isDirectory == true, installed.isSymbolicLink != true,
              let current = revision(at: destination), let candidate = revision(at: source) else { return .blocked }
        return candidate > current ? .update : .current
    }

    private static func revision(at bundle: URL) -> Int? {
        guard let data = try? Data(contentsOf: bundle.appendingPathComponent("Contents/Info.plist")),
              let info = try? PropertyListSerialization.propertyList(from: data, options: [], format: nil) as? [String: Any],
              info["CFBundleIdentifier"] as? String == bundleID,
              let revision = info["CFBundleVersion"] as? String else { return nil }
        return Int(revision)
    }

    static func copy(from source: URL, to destination: URL, profile: URL) throws {
        let files = FileManager.default
        let folder = destination.deletingLastPathComponent()
        try files.createDirectory(at: folder, withIntermediateDirectories: true)
        let lock = open(profile.appendingPathComponent("run.lock").path, O_CREAT | O_RDWR | O_NOFOLLOW, 0o600)
        guard lock >= 0 else { throw POSIXError(.EACCES) }
        defer { close(lock) }
        guard flock(lock, LOCK_EX | LOCK_NB) == 0 else { throw POSIXError(.EBUSY) }

        let target = mode(from: source, to: destination)
        guard target == .install || target == .update else { throw POSIXError(.EEXIST) }
        let staging = folder.appendingPathComponent(".Canabicultarr-\(UUID().uuidString).app")
        defer { try? files.removeItem(at: staging) }
        // The image is on a different volume. Stage beside the installed app before the swap.
        try files.copyItem(at: source, to: staging)
        if target == .update {
            guard renamex_np(staging.path, destination.path, UInt32(RENAME_SWAP)) == 0 else {
                throw POSIXError(POSIXErrorCode(rawValue: errno) ?? .EIO)
            }
        } else {
            try files.moveItem(at: staging, to: destination)
        }
    }
}
