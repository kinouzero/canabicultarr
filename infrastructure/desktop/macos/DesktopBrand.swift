import AppKit
import SwiftUI

struct DesktopBrand {
    private struct Palette: Decodable { let colors: [String: String] }

    private static let colors: [String: String] = {
        guard let url = Bundle.main.url(forResource: "palette", withExtension: "json"),
              let data = try? Data(contentsOf: url),
              let palette = try? JSONDecoder().decode(Palette.self, from: data)
        else { return [:] }
        return palette.colors
    }()

    private static func color(_ name: String, fallback: Color) -> Color {
        guard let hex = colors[name], hex.count == 7, hex.first == "#",
              let rgb = UInt32(hex.dropFirst(), radix: 16)
        else { return fallback }
        return Color(
            red: Double((rgb >> 16) & 255) / 255,
            green: Double((rgb >> 8) & 255) / 255,
            blue: Double(rgb & 255) / 255
        )
    }

    let scheme: ColorScheme
    private var forest: Color { Self.color("forest_green", fallback: .green) }
    var canvas: Color {
        Self.color(scheme == .dark ? "dark_background" : "light_background", fallback: Color(nsColor: .windowBackgroundColor))
    }
    var canvasTint: Color { (scheme == .dark ? forest : markSurface).opacity(0.78) }
    var heading: Color { scheme == .dark ? Self.color("celadon", fallback: .primary) : forest }
    var accent: Color { scheme == .dark ? Self.color("olive", fallback: .accentColor) : forest }
    var markSurface: Color { Self.color("celadon", fallback: Color(nsColor: .controlBackgroundColor)) }

    static let mark: NSImage? = Bundle.main.url(forResource: "brand-mark", withExtension: "png").flatMap(NSImage.init(contentsOf:))
}
