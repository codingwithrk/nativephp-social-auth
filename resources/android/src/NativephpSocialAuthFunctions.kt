package com.codingwithrk.plugins.nativephp_social_auth

import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.credentials.ClearCredentialStateRequest
import androidx.credentials.CredentialManager
import androidx.credentials.CustomCredential
import androidx.credentials.GetCredentialRequest
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.NoCredentialException
import androidx.fragment.app.FragmentActivity
import com.google.android.libraries.identity.googleid.GetGoogleIdOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.json.JSONObject

private const val TAG = "NativephpSocialAuth"

/**
 * Fully-qualified PHP event names dispatched by this plugin.
 *
 * The double backslashes are Kotlin escapes — the resulting strings contain a
 * single backslash, matching the PHP namespace separator.
 */
private object SocialAuthEvents {
    const val GOOGLE_SIGN_IN_COMPLETED =
        "Codingwithrk\\NativephpSocialAuth\\Events\\GoogleSignInCompleted"
    const val SIGN_IN_FAILED =
        "Codingwithrk\\NativephpSocialAuth\\Events\\SignInFailed"
}

/**
 * Application-scoped coroutine scope used for all Credential Manager work.
 *
 * Deliberately NOT tied to `activity.lifecycleScope`: a configuration change
 * (rotation, dark-mode toggle, multi-window resize) recreates the Activity and
 * would cancel an in-flight Credential Manager request, leaving the PHP side
 * waiting for an event that never arrives. A process-lifetime `SupervisorJob`
 * keeps the sign-in alive across those recreations, and one failed child job
 * never tears down the scope for subsequent calls.
 *
 * `Dispatchers.Main` is used so that `NativeActionCoordinator.dispatchEvent`
 * (which touches the WebView / JS bridge) always runs on the UI thread without
 * an extra `Handler(Looper.getMainLooper()).post { }` hop.
 */
private object NativephpSocialAuthScope {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
}

/**
 * Reads an `<application>`-level `meta-data` string from the merged manifest.
 *
 * @param key The fully-qualified meta-data name.
 * @return The value, or null when the entry is absent or not a string.
 */
private fun FragmentActivity.readManifestMetaData(key: String): String? {
    return try {
        val appInfo: ApplicationInfo = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            packageManager.getApplicationInfo(
                packageName,
                PackageManager.ApplicationInfoFlags.of(PackageManager.GET_META_DATA.toLong())
            )
        } else {
            @Suppress("DEPRECATION")
            packageManager.getApplicationInfo(packageName, PackageManager.GET_META_DATA)
        }

        appInfo.metaData?.getString(key)
    } catch (e: Exception) {
        Log.w(TAG, "Unable to read manifest meta-data '$key': ${e.message}")
        null
    }
}

/**
 * Dispatches the `SignInFailed` event to PHP/JS.
 *
 * Must be called from the main thread (the plugin scope already guarantees it).
 *
 * @param activity  Host activity used by the coordinator to reach the WebView.
 * @param provider  "google" or "apple".
 * @param message   Human-readable failure description.
 * @param errorCode Machine-readable code: USER_CANCELLED | NO_CREDENTIALS | SIGN_IN_FAILED.
 */
private fun dispatchSignInFailed(
    activity: FragmentActivity,
    provider: String,
    message: String,
    errorCode: String
) {
    val payload = JSONObject().apply {
        put("provider", provider)
        put("error", message)
        put("errorCode", errorCode)
    }

    NativeActionCoordinator.dispatchEvent(
        activity,
        SocialAuthEvents.SIGN_IN_FAILED,
        payload.toString()
    )
}

object NativephpSocialAuthFunctions {

    /**
     * Start the native Sign in with Apple flow.
     *
     * Android has no first-party Apple Sign-In SDK (the only option is a web
     * OAuth round-trip, which this plugin intentionally does not provide), so
     * this always fails fast rather than silently doing nothing.
     *
     * Parameters: none (ignored).
     *
     * Returns:
     * - error UNSUPPORTED_PLATFORM — always.
     */
    class AppleSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.error(
                "UNSUPPORTED_PLATFORM",
                "Apple Sign-In is not available on Android."
            )
        }
    }

    /**
     * Start the native Google Sign-In flow via Credential Manager.
     *
     * The call is asynchronous: this function returns immediately after the
     * bottom sheet has been requested, and the outcome is delivered later as a
     * NativePHP event.
     *
     * Parameters:
     * - nonce: String (optional) — Raw nonce embedded in the returned ID token
     *   so your backend can bind the token to this specific request.
     *
     * Returns (immediately):
     * - pending: Boolean — always true; wait for the events below.
     *
     * Events:
     * - `Codingwithrk\NativephpSocialAuth\Events\GoogleSignInCompleted` with
     *   userId, identityToken, email, givenName, familyName, fullName,
     *   photoUrl, nonce.
     * - `Codingwithrk\NativephpSocialAuth\Events\SignInFailed` with provider,
     *   error, errorCode (USER_CANCELLED | NO_CREDENTIALS | SIGN_IN_FAILED).
     *
     * Errors (returned synchronously):
     * - MISSING_CONFIGURATION — the GOOGLE_SERVER_CLIENT_ID manifest meta-data
     *   entry is absent or blank.
     */
    class GoogleSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val serverClientId = activity.readManifestMetaData(
                "com.codingwithrk.nativephp_social_auth.GOOGLE_SERVER_CLIENT_ID"
            )

            if (serverClientId.isNullOrBlank()) {
                return BridgeResponse.error(
                    "MISSING_CONFIGURATION",
                    "GOOGLE_SERVER_CLIENT_ID is not configured."
                )
            }

            val nonce = (parameters["nonce"] as? String)?.takeIf { it.isNotBlank() }

            val googleIdOption = GetGoogleIdOption.Builder()
                // false => show every Google account on the device, not just the
                // ones that have already authorized this app. Required for a
                // usable first-time sign-in experience.
                .setFilterByAuthorizedAccounts(false)
                .setServerClientId(serverClientId)
                .apply { nonce?.let { setNonce(it) } }
                .build()

            val request = GetCredentialRequest.Builder()
                .addCredentialOption(googleIdOption)
                .build()

            NativephpSocialAuthScope.scope.launch {
                try {
                    // Suspends while the Credential Manager UI is shown. The
                    // Activity context is required so the sheet is anchored to
                    // the current window.
                    val result = CredentialManager.create(activity)
                        .getCredential(activity, request)

                    val credential = result.credential

                    // Defensive: a provider could theoretically return another
                    // credential type for this request.
                    val isGoogleIdToken = credential is CustomCredential &&
                        credential.type == GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_CREDENTIAL

                    if (!isGoogleIdToken) {
                        dispatchSignInFailed(
                            activity,
                            provider = "google",
                            message = "Unexpected credential type returned: ${credential.type}",
                            errorCode = "SIGN_IN_FAILED"
                        )
                        return@launch
                    }

                    val googleCredential = GoogleIdTokenCredential.createFrom(credential.data)

                    // `id` is the account identifier, which for Google ID token
                    // credentials is the user's email address. Typed as nullable
                    // so a misbehaving provider can never NPE the payload build.
                    val accountId: String? = googleCredential.id
                    val idToken: String? = googleCredential.idToken

                    val payload = JSONObject().apply {
                        put("userId", accountId ?: JSONObject.NULL)
                        put("identityToken", idToken ?: JSONObject.NULL)
                        put("email", accountId ?: JSONObject.NULL)
                        put("givenName", googleCredential.givenName ?: JSONObject.NULL)
                        put("familyName", googleCredential.familyName ?: JSONObject.NULL)
                        put("fullName", googleCredential.displayName ?: JSONObject.NULL)
                        put(
                            "photoUrl",
                            googleCredential.profilePictureUri?.toString() ?: JSONObject.NULL
                        )
                        put("nonce", nonce ?: JSONObject.NULL)
                    }

                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        SocialAuthEvents.GOOGLE_SIGN_IN_COMPLETED,
                        payload.toString()
                    )
                } catch (e: Exception) {
                    // Covers GetCredentialException and every subclass, plus
                    // GoogleIdTokenParsingException and any provider crash.
                    val (errorCode, fallbackMessage) = when (e) {
                        is GetCredentialCancellationException ->
                            "USER_CANCELLED" to "Google Sign-In was cancelled."

                        is NoCredentialException ->
                            "NO_CREDENTIALS" to "No Google accounts are available on this device."

                        else ->
                            "SIGN_IN_FAILED" to "Google Sign-In failed."
                    }

                    Log.w(TAG, "Google Sign-In failed ($errorCode): ${e.message}", e)

                    dispatchSignInFailed(
                        activity,
                        provider = "google",
                        message = e.message?.takeIf { it.isNotBlank() } ?: fallbackMessage,
                        errorCode = errorCode
                    )
                }
            }

            return BridgeResponse.success(mapOf("pending" to true))
        }
    }

    /**
     * Check whether a previously issued Apple credential is still valid.
     *
     * Apple-only API (`ASAuthorizationAppleIDProvider.getCredentialState`);
     * there is no Android equivalent.
     *
     * Parameters: none (ignored).
     *
     * Returns:
     * - error UNSUPPORTED_PLATFORM — always.
     */
    class CheckAppleCredentialState(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.error(
                "UNSUPPORTED_PLATFORM",
                "Apple credential state is not available on Android."
            )
        }
    }

    /**
     * Sign out of the native Google session.
     *
     * Clears the Credential Manager's cached selection so the next
     * `GoogleSignIn` call shows the account picker again instead of silently
     * re-selecting the previous account. There is no server-side revocation
     * here — invalidate your own session/token in PHP as well.
     *
     * The clear is best-effort and fire-and-forget: failures are logged and
     * swallowed, never surfaced to PHP, because a failed local cache clear must
     * not block the app's sign-out flow.
     *
     * Parameters: none.
     *
     * Returns (immediately, optimistically):
     * - success: Boolean — always true.
     */
    class SignOut(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            NativephpSocialAuthScope.scope.launch {
                try {
                    CredentialManager.create(activity)
                        .clearCredentialState(ClearCredentialStateRequest())
                } catch (e: Exception) {
                    Log.w(TAG, "Failed to clear credential state: ${e.message}", e)
                }
            }

            return BridgeResponse.success(mapOf("success" to true))
        }
    }
}
