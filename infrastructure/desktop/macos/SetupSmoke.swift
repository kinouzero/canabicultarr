import Foundation

@main
struct SetupSmoke {
    private struct Fixture: Decodable {
        let token: String
        let email: String
        let password: String
    }

    static func main() async throws {
        let origin = URL(string: CommandLine.arguments[1])!
        let runID = CommandLine.arguments[2]
        let fixture = try JSONDecoder().decode(Fixture.self, from: FileHandle.standardInput.readDataToEndOfFile())
        let incorrectInstance = DesktopSetupClient(origin: origin, runID: UUID().uuidString)
        do {
            _ = try await incorrectInstance.requiresSetup()
            fatalError("Accepted another instance’s response")
        } catch DesktopSetupClient.Failure.unexpectedResponse {}

        let client = DesktopSetupClient(origin: origin, runID: runID)
        guard try await client.requiresSetup() else { fatalError("Expected a new instance") }
        try await client.create(.init(
            token: fixture.token, name: "Desktop smoke test", email: fixture.email,
            password: fixture.password, password_confirmation: fixture.password, locale: "en"
        ))
        guard try await !client.requiresSetup() else { fatalError("Setup stayed open after account creation") }
    }
}
