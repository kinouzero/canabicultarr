import Foundation

struct DesktopSetupClient {
    enum Failure: Error {
        case unexpectedResponse
        case validation
        case activationUnavailable
    }

    private struct SetupStatus: Decodable {
        struct State: Decodable { var requiresSetup: Bool }
        var data: State
    }

    struct Administrator: Encodable {
        var token: String
        var name: String
        var email: String
        var password: String
        var password_confirmation: String
        var locale: String
    }

    let origin: URL
    let runID: String
    private let session = URLSession(configuration: .ephemeral)

    func requiresSetup() async throws -> Bool {
        let (body, response) = try await request("setup")
        guard response.statusCode == 200 else { throw Failure.unexpectedResponse }
        return try JSONDecoder().decode(SetupStatus.self, from: body).data.requiresSetup
    }

    func create(_ administrator: Administrator) async throws {
        let (_, csrf) = try await request("auth/csrf")
        guard csrf.statusCode == 204,
              let encoded = session.configuration.httpCookieStorage?.cookies(for: origin)?.first(where: { $0.name == "XSRF-TOKEN" })?.value,
              let token = encoded.removingPercentEncoding, !token.isEmpty
        else { throw Failure.unexpectedResponse }

        let (_, response) = try await request("setup", body: JSONEncoder().encode(administrator), csrf: token)
        switch response.statusCode {
        case 201: return
        case 422: throw Failure.validation
        case 503: throw Failure.activationUnavailable
        default: throw Failure.unexpectedResponse
        }
    }

    private func request(_ path: String, body: Data? = nil, csrf: String? = nil) async throws -> (Data, HTTPURLResponse) {
        var request = URLRequest(url: origin.appendingPathComponent("api/v1/\(path)"), timeoutInterval: body == nil ? 2 : 15)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let body, let csrf {
            request.httpMethod = "POST"
            request.httpBody = body
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.setValue(csrf, forHTTPHeaderField: "X-XSRF-TOKEN")
        }
        let (data, response) = try await session.data(for: request)
        guard let response = response as? HTTPURLResponse, response.value(forHTTPHeaderField: "X-Canabicultarr-Desktop-Run") == runID
        else { throw Failure.unexpectedResponse }
        return (data, response)
    }
}
