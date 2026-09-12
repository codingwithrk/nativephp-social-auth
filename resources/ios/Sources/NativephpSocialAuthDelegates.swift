import AuthenticationServices
import CryptoKit
import Foundation
import UIKit

// MARK: - Event names

/// Fully qualified PHP event class names dispatched by this plugin.
///
/// The `\\` sequences in these Swift string literals each produce a single
/// literal backslash, which is what PHP expects as a namespace separator.
enum SocialAuthEvents {
    static let appleSignInCompleted = "Codingwithrk\\NativephpSocialAuth\\Events\\AppleSignInCompleted"
    static let googleSignInCompleted = "Codingwithrk\\NativephpSocialAuth\\Events\\GoogleSignInCompleted"
    static let appleCredentialStateChecked = "Codingwithrk\\NativephpSocialAuth\\Events\\AppleCredentialStateChecked"
    static let signInFailed = "Codingwithrk\\NativephpSocialAuth\\Events\\SignInFailed"
}

// MARK: - Shared helpers

/// Small utility layer shared by the social auth bridge functions: main-thread
/// helpers, window/view-controller discovery, nonce hashing and event dispatch.
enum SocialAuthSupport {

    // MARK: Threading

    /// Runs `work` on the main thread and returns its result.
    ///
    /// Bridge functions are invoked on the main thread already, so this is
    /// almost always a straight passthrough. It exists so the UIKit lookups
    /// below stay safe even if the bridge ever calls us from another queue
    /// (`DispatchQueue.main.sync` would deadlock without the `isMainThread`
    /// check).
    static func onMainSync<T>(_ work: () -> T) -> T {
        if Thread.isMainThread {
            return work()
        }

        return DispatchQueue.main.sync(execute: work)
    }

    /// Runs `work` on the main thread, asynchronously if we are not on it yet.
    static func onMain(_ work: @escaping () -> Void) {
        if Thread.isMainThread {
            work()
        } else {
            DispatchQueue.main.async(execute: work)
        }
    }

    // MARK: UIKit lookups

    /// The app's key window, falling back to the first window that has a root
    /// view controller when no window reports itself as key (which can happen
    /// briefly during scene activation).
    static func keyWindow() -> UIWindow? {
        onMainSync { () -> UIWindow? in
            let windows = UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .flatMap { $0.windows }

            return windows.first(where: \.isKeyWindow)
                ?? windows.first(where: { $0.rootViewController != nil })
        }
    }

    /// The topmost presented view controller, suitable for presenting UI from.
    static func topViewController() -> UIViewController? {
        onMainSync { () -> UIViewController? in
            guard var top = keyWindow()?.rootViewController else {
                return nil
            }

            while let presented = top.presentedViewController, !presented.isBeingDismissed {
                top = presented
            }

            return top
        }
    }

    // MARK: Crypto

    /// SHA-256 hashes a string and returns the digest as a lowercase hex string.
    static func sha256Hex(_ input: String) -> String {
        SHA256.hash(data: Data(input.utf8))
            .map { String(format: "%02x", $0) }
            .joined()
    }

    // MARK: Payload helpers

    /// Converts an optional string into something that is safe to put into a
    /// `[String: Any]` payload — `NSNull()` serialises to `null` for PHP, which
    /// maps cleanly onto the nullable constructor properties of the events.
    static func payloadValue(_ value: String?) -> Any {
        guard let value = value, !value.isEmpty else {
            return NSNull()
        }

        return value
    }

    // MARK: Event dispatch

    /// Sends an event to Laravel. Always hops to the main thread first — the
    /// bridge injects into the web view and must not be touched off-main.
    static func send(_ event: String, _ payload: [String: Any]) {
        onMain {
            LaravelBridge.shared.send?(event, payload)
        }
    }

    /// Dispatches the shared `SignInFailed` event for either provider.
    ///
    /// - Parameters:
    ///   - provider: `"apple"` or `"google"`.
    ///   - message: Human readable failure reason.
    ///   - code: `"USER_CANCELLED"` for deliberate dismissals, otherwise `"SIGN_IN_FAILED"`.
    static func sendFailure(provider: String, message: String, code: String) {
        send(SocialAuthEvents.signInFailed, [
            "provider": provider,
            "error": message,
            "errorCode": code,
        ])
    }
}

// MARK: - Apple Sign-In delegate

/// Drives a single native "Sign in with Apple" authorization request.
///
/// `ASAuthorizationController` holds its `delegate` and
/// `presentationContextProvider` **weakly**, so nothing else in the app keeps
/// this object alive while the system sheet is on screen. Without the
/// `current` static strong reference below, ARC deallocates the instance as
/// soon as `execute()` returns and the delegate callbacks silently never fire.
/// The reference is released once a callback has been handled.
final class AppleSignInDelegate: NSObject,
                                 ASAuthorizationControllerDelegate,
                                 ASAuthorizationControllerPresentationContextProviding {

    /// Strong reference to the in-flight flow. See the class documentation.
    static var current: AppleSignInDelegate?

    /// The raw (unhashed) nonce supplied by PHP, echoed back on completion.
    private let rawNonce: String?

    /// Opaque passthrough value supplied by PHP, echoed back on completion.
    private let state: String?

    /// The window the system sheet is anchored to.
    private let anchor: UIWindow

    /// Retained for the duration of the request; the system does not keep a
    /// reference we can rely on.
    private var controller: ASAuthorizationController?

    init(rawNonce: String?, state: String?, anchor: UIWindow) {
        self.rawNonce = rawNonce
        self.state = state
        self.anchor = anchor
        super.init()
    }

    /// Presents the authorization sheet for the given request.
    func perform(request: ASAuthorizationAppleIDRequest) {
        AppleSignInDelegate.current = self

        SocialAuthSupport.onMain { [self] in
            let controller = ASAuthorizationController(authorizationRequests: [request])
            controller.delegate = self
            controller.presentationContextProvider = self
            self.controller = controller
            controller.performRequests()
        }
    }

    // MARK: ASAuthorizationControllerPresentationContextProviding

    func presentationAnchor(for controller: ASAuthorizationController) -> ASPresentationAnchor {
        anchor
    }

    // MARK: ASAuthorizationControllerDelegate

    func authorizationController(controller: ASAuthorizationController,
                                 didCompleteWithAuthorization authorization: ASAuthorization) {
        defer { finish() }

        guard let credential = authorization.credential as? ASAuthorizationAppleIDCredential else {
            SocialAuthSupport.sendFailure(
                provider: "apple",
                message: "Unexpected credential type returned by Sign in with Apple.",
                code: "SIGN_IN_FAILED"
            )

            return
        }

        let identityToken = credential.identityToken.flatMap { String(data: $0, encoding: .utf8) }
        let authorizationCode = credential.authorizationCode.flatMap { String(data: $0, encoding: .utf8) }

        let givenName = credential.fullName?.givenName
        let familyName = credential.fullName?.familyName
        let fullName = credential.fullName.flatMap { Self.formattedName(from: $0) }

        // `nonce` is echoed back RAW (exactly as PHP supplied it). Apple only
        // ever sees — and therefore only embeds in the identity token's `nonce`
        // claim — the SHA-256 hash of this value. PHP re-hashes the raw nonce
        // itself and compares it against the claim, so the hash never has to
        // travel back across the bridge.
        let payload: [String: Any] = [
            "userId": credential.user,
            "identityToken": SocialAuthSupport.payloadValue(identityToken),
            "authorizationCode": SocialAuthSupport.payloadValue(authorizationCode),
            "email": SocialAuthSupport.payloadValue(credential.email),
            "givenName": SocialAuthSupport.payloadValue(givenName),
            "familyName": SocialAuthSupport.payloadValue(familyName),
            "fullName": SocialAuthSupport.payloadValue(fullName),
            "nonce": SocialAuthSupport.payloadValue(rawNonce),
            "state": SocialAuthSupport.payloadValue(state),
            "isRealUser": Self.realUserStatusName(credential.realUserStatus),
        ]

        SocialAuthSupport.send(SocialAuthEvents.appleSignInCompleted, payload)
    }

    func authorizationController(controller: ASAuthorizationController,
                                 didCompleteWithError error: Error) {
        defer { finish() }

        var code = "SIGN_IN_FAILED"
        var message = error.localizedDescription

        if let authError = error as? ASAuthorizationError {
            let caseName = Self.errorCodeName(authError.code)

            if authError.code == .canceled {
                code = "USER_CANCELLED"
                message = "Sign in with Apple was cancelled."
            } else {
                message = "\(message) (ASAuthorizationError.\(caseName))"
            }
        }

        SocialAuthSupport.sendFailure(provider: "apple", message: message, code: code)
    }

    // MARK: Lifecycle

    /// Releases the static strong reference.
    ///
    /// This is deliberately deferred to the next main-thread turn: clearing it
    /// synchronously could drop the last reference to `self` while a delegate
    /// callback is still executing. The closure holds `self` until it runs.
    private func finish() {
        controller = nil

        DispatchQueue.main.async { [self] in
            if AppleSignInDelegate.current === self {
                AppleSignInDelegate.current = nil
            }
        }
    }

    // MARK: Mapping helpers

    /// Formats `PersonNameComponents` into a localised display name, returning
    /// `nil` when Apple gave us nothing to format (which is the case on every
    /// sign-in after the user's first authorization).
    private static func formattedName(from components: PersonNameComponents) -> String? {
        let formatter = PersonNameComponentsFormatter()
        formatter.style = .default

        let formatted = formatter.string(from: components).trimmingCharacters(in: .whitespacesAndNewlines)

        return formatted.isEmpty ? nil : formatted
    }

    /// Maps Apple's real user hint onto the strings the PHP event expects.
    private static func realUserStatusName(_ status: ASUserDetectionStatus) -> String {
        switch status {
        case .likelyReal:
            return "likelyReal"
        case .unsupported:
            return "unsupported"
        case .unknown:
            return "unknown"
        default:
            return "unknown"
        }
    }

    /// Best-effort name for the underlying `ASAuthorizationError.Code`, folded
    /// into the failure message for easier debugging.
    private static func errorCodeName(_ code: ASAuthorizationError.Code) -> String {
        switch code {
        case .canceled:
            return "canceled"
        case .failed:
            return "failed"
        case .invalidResponse:
            return "invalidResponse"
        case .notHandled:
            return "notHandled"
        case .unknown:
            return "unknown"
        default:
            return "unknown"
        }
    }
}
