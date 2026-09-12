import AuthenticationServices
import Foundation
import GoogleSignIn
import UIKit

/// Native social authentication bridge functions.
///
/// Apple Sign-In is implemented with `AuthenticationServices`; Google Sign-In
/// with the official GoogleSignIn-iOS SDK (`~> 9.0`). Both flows are
/// asynchronous: `execute()` returns `["pending": true]` as soon as the native
/// UI has been kicked off, and the actual result is delivered later as a
/// Laravel event.
enum NativephpSocialAuthFunctions {

    // MARK: - Apple

    /// Starts the native "Sign in with Apple" flow.
    ///
    /// - Parameters:
    ///   - scopes: Optional array of requested scopes. Recognised values are
    ///     `"fullName"` and `"email"`; anything else is ignored. Defaults to
    ///     both when the key is absent.
    ///   - nonce: Optional RAW (unhashed) nonce. It is SHA-256 hashed before
    ///     being handed to Apple, so the identity token's `nonce` claim holds
    ///     the hash. The raw value is echoed back in the completion event so
    ///     PHP can re-hash and compare it itself.
    ///   - state: Optional opaque value echoed back unchanged on completion.
    ///
    /// - Returns: `["pending": true]` once the system sheet has been requested.
    ///   The result arrives via `AppleSignInCompleted` or `SignInFailed`.
    ///
    /// Note that `email` and the name components are only ever returned by
    /// Apple on the user's *first* authorization for this app.
    class AppleSignIn: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let rawNonce = (parameters["nonce"] as? String).flatMap { $0.isEmpty ? nil : $0 }
            let state = (parameters["state"] as? String).flatMap { $0.isEmpty ? nil : $0 }

            guard let anchor = SocialAuthSupport.keyWindow() else {
                return BridgeResponse.error(
                    code: "NO_PRESENTING_CONTROLLER",
                    message: "No active window was found to present the Sign in with Apple sheet from."
                )
            }

            let request = ASAuthorizationAppleIDProvider().createRequest()
            request.requestedScopes = Self.requestedScopes(from: parameters["scopes"])

            // Apple only ever receives the hashed nonce — never the raw one.
            if let rawNonce = rawNonce {
                request.nonce = SocialAuthSupport.sha256Hex(rawNonce)
            }

            // The delegate keeps itself alive via AppleSignInDelegate.current
            // for the duration of the flow.
            let delegate = AppleSignInDelegate(rawNonce: rawNonce, state: state, anchor: anchor)
            delegate.perform(request: request)

            return BridgeResponse.success(data: ["pending": true])
        }

        /// Maps the incoming scope strings onto `ASAuthorization.Scope` values,
        /// preserving order, dropping duplicates and ignoring unknown entries.
        private static func requestedScopes(from value: Any?) -> [ASAuthorization.Scope]? {
            guard let names = value as? [String] else {
                // Key absent (or not an array of strings): request everything.
                return [.fullName, .email]
            }

            var scopes: [ASAuthorization.Scope] = []

            for name in names {
                let scope: ASAuthorization.Scope?

                switch name {
                case "fullName":
                    scope = .fullName
                case "email":
                    scope = .email
                default:
                    scope = nil
                }

                if let scope = scope, !scopes.contains(scope) {
                    scopes.append(scope)
                }
            }

            // An explicitly empty list means "no scopes"; Apple expects nil
            // rather than an empty array in that case.
            return scopes.isEmpty ? nil : scopes
        }
    }

    /// Checks whether a previously issued Apple credential is still valid.
    ///
    /// - Parameters:
    ///   - userId: The stable user identifier from a previous `AppleSignInCompleted` event.
    ///
    /// - Returns: `["pending": true]`. The resolved state arrives via the
    ///   `AppleCredentialStateChecked` event with `state` set to one of
    ///   `"authorized"`, `"revoked"`, `"notFound"`, `"transferred"` or `"unknown"`.
    class CheckAppleCredentialState: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let userId = parameters["userId"] as? String, !userId.isEmpty else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "userId is required")
            }

            ASAuthorizationAppleIDProvider().getCredentialState(forUserID: userId) { state, error in
                // The completion handler is not guaranteed to run on any
                // particular queue, and `send` itself hops to main anyway.
                let name: String

                if error != nil {
                    name = "unknown"
                } else {
                    switch state {
                    case .authorized:
                        name = "authorized"
                    case .revoked:
                        name = "revoked"
                    case .notFound:
                        name = "notFound"
                    case .transferred:
                        name = "transferred"
                    default:
                        name = "unknown"
                    }
                }

                DispatchQueue.main.async {
                    SocialAuthSupport.send(SocialAuthEvents.appleCredentialStateChecked, [
                        "userId": userId,
                        "state": name,
                    ])
                }
            }

            return BridgeResponse.success(data: ["pending": true])
        }
    }

    // MARK: - Google

    /// Starts the native Google Sign-In flow using the GoogleSignIn-iOS SDK.
    ///
    /// - Parameters:
    ///   - nonce: Optional raw nonce forwarded to the SDK so it ends up in the
    ///     ID token's `nonce` claim, and echoed back unchanged on completion.
    ///
    /// - Returns: `["pending": true]`. The result arrives via
    ///   `GoogleSignInCompleted` or `SignInFailed`.
    ///
    /// The client IDs are read from `Info.plist` (`GIDClientID` /
    /// `GIDServerClientID`), which the plugin manifest populates from the
    /// `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_SERVER_CLIENT_ID` secrets.
    class GoogleSignIn: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let nonce = (parameters["nonce"] as? String).flatMap { $0.isEmpty ? nil : $0 }

            guard let presenter = SocialAuthSupport.topViewController() else {
                return BridgeResponse.error(
                    code: "NO_PRESENTING_CONTROLLER",
                    message: "No active view controller was found to present the Google Sign-In flow from."
                )
            }

            if let configurationError = Self.ensureConfiguration() {
                return configurationError
            }

            SocialAuthSupport.onMain {
                // GoogleSignIn-iOS exposes an OIDC `nonce` parameter on the
                // sign-in call (added in 7.1 and carried through 8.x/9.x), which
                // embeds the raw value in the returned ID token's `nonce` claim
                // for backend/Firebase verification. If a future SDK revision
                // drops that parameter, remove the `nonce:` argument here — the
                // payload still echoes the input nonce back either way, and
                // server-side ID token verification does not strictly require
                // the claim to be present.
                GIDSignIn.sharedInstance.signIn(
                    withPresenting: presenter,
                    hint: nil,
                    additionalScopes: nil,
                    nonce: nonce
                ) { result, error in
                    if let error = error {
                        // Fully qualified rather than `Self.` so the static
                        // helper is resolved without capturing `self` in this
                        // escaping closure.
                        let cancelled = NativephpSocialAuthFunctions.GoogleSignIn.isCancellation(error)

                        SocialAuthSupport.sendFailure(
                            provider: "google",
                            message: cancelled ? "Google Sign-In was cancelled." : error.localizedDescription,
                            code: cancelled ? "USER_CANCELLED" : "SIGN_IN_FAILED"
                        )

                        return
                    }

                    guard let user = result?.user else {
                        SocialAuthSupport.sendFailure(
                            provider: "google",
                            message: "Google Sign-In returned no user.",
                            code: "SIGN_IN_FAILED"
                        )

                        return
                    }

                    guard let userId = user.userID, !userId.isEmpty else {
                        SocialAuthSupport.sendFailure(
                            provider: "google",
                            message: "Google Sign-In returned a user without an identifier.",
                            code: "SIGN_IN_FAILED"
                        )

                        return
                    }

                    let profile = user.profile

                    SocialAuthSupport.send(SocialAuthEvents.googleSignInCompleted, [
                        "userId": userId,
                        "identityToken": SocialAuthSupport.payloadValue(user.idToken?.tokenString),
                        "email": SocialAuthSupport.payloadValue(profile?.email),
                        "givenName": SocialAuthSupport.payloadValue(profile?.givenName),
                        "familyName": SocialAuthSupport.payloadValue(profile?.familyName),
                        "fullName": SocialAuthSupport.payloadValue(profile?.name),
                        "photoUrl": SocialAuthSupport.payloadValue(
                            profile?.imageURL(withDimension: 320)?.absoluteString
                        ),
                        "nonce": SocialAuthSupport.payloadValue(nonce),
                    ])
                }
            }

            return BridgeResponse.success(data: ["pending": true])
        }

        /// Makes sure the SDK has a configuration.
        ///
        /// The SDK falls back to `Info.plist` on its own, but it raises a fatal
        /// assertion when `GIDClientID` is missing. Configuring it explicitly
        /// lets us return a useful bridge error instead of crashing the app.
        ///
        /// - Returns: A `BridgeResponse.error` dictionary when the app is
        ///   misconfigured, otherwise `nil`.
        private static func ensureConfiguration() -> [String: Any]? {
            guard GIDSignIn.sharedInstance.configuration == nil else {
                return nil
            }

            guard let clientID = Bundle.main.object(forInfoDictionaryKey: "GIDClientID") as? String,
                  !clientID.isEmpty else {
                return BridgeResponse.error(
                    code: "MISSING_CONFIGURATION",
                    message: "GIDClientID is missing from Info.plist. Set the GOOGLE_IOS_CLIENT_ID secret and rebuild."
                )
            }

            let serverClientID = (Bundle.main.object(forInfoDictionaryKey: "GIDServerClientID") as? String)
                .flatMap { $0.isEmpty ? nil : $0 }

            GIDSignIn.sharedInstance.configuration = GIDConfiguration(
                clientID: clientID,
                serverClientID: serverClientID
            )

            return nil
        }

        /// Whether the SDK error represents the user dismissing the flow.
        private static func isCancellation(_ error: Error) -> Bool {
            let nsError = error as NSError

            guard nsError.domain == kGIDSignInErrorDomain else {
                return false
            }

            return nsError.code == GIDSignInError.Code.canceled.rawValue
        }
    }

    // MARK: - Session

    /// Signs out of the native Google session.
    ///
    /// Apple deliberately provides no sign-out API — Sign in with Apple state
    /// lives in the system and is managed from Settings — so this only clears
    /// the cached Google credentials.
    ///
    /// - Returns: `["success": true]`. This call is synchronous; no event is
    ///   dispatched for it.
    class SignOut: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            SocialAuthSupport.onMainSync {
                GIDSignIn.sharedInstance.signOut()
            }

            return BridgeResponse.success(data: ["success": true])
        }
    }
}
