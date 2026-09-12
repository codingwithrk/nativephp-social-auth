/**
 * NativephpSocialAuth — native Apple Sign-In and Google Sign-In for NativePHP Mobile.
 *
 * Both flows are asynchronous: the calls below only *start* the native flow.
 * Listen for the native events (via NativePHP's global `On()` helper, or
 * Livewire's `#[OnNative]` attribute on the PHP side) to receive the result.
 *
 * @example
 * import nativephpSocialAuth, { generateNonce } from '@codingwithrk/nativephp-social-auth';
 *
 * const nonce = generateNonce();
 * await nativephpSocialAuth.googleSignIn(nonce);
 *
 * On('Codingwithrk\\NativephpSocialAuth\\Events\\GoogleSignInCompleted', (payload) => {
 *     // payload.identityToken, payload.email, payload.givenName, ...
 * });
 *
 * On('Codingwithrk\\NativephpSocialAuth\\Events\\SignInFailed', (payload) => {
 *     // payload.provider, payload.error, payload.errorCode
 * });
 */

const baseUrl = '/_native/api/call';

/**
 * Internal bridge call function
 * @private
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;
    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Generate a random, URL-safe nonce for replay protection. Keep the raw
 * value around (component state, session, ...) — you'll need it again to
 * verify the `nonce` claim inside the returned identity token server-side.
 *
 * @param {number} [length=32]
 * @returns {string}
 */
export function generateNonce(length = 32) {
    const bytes = new Uint8Array(length);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Start the native "Sign in with Apple" flow. iOS only — rejects with an
 * UNSUPPORTED_PLATFORM error on Android. Listen for the
 * `AppleSignInCompleted` / `SignInFailed` native events for the result.
 *
 * @param {Object} [options]
 * @param {string[]} [options.scopes] Defaults to ['fullName', 'email']
 * @param {string|null} [options.nonce]
 * @param {string|null} [options.state]
 */
export async function appleSignIn({ scopes = ['fullName', 'email'], nonce = null, state = null } = {}) {
    return bridgeCall('NativephpSocialAuth.AppleSignIn', { scopes, nonce, state });
}

/**
 * Start the native Google Sign-In flow. Listen for the
 * `GoogleSignInCompleted` / `SignInFailed` native events for the result.
 *
 * @param {string|null} [nonce]
 */
export async function googleSignIn(nonce = null) {
    return bridgeCall('NativephpSocialAuth.GoogleSignIn', { nonce });
}

/**
 * Check whether a previously issued Apple credential is still valid. iOS only.
 * Listen for the `AppleCredentialStateChecked` native event for the result.
 *
 * @param {string} userId The stable user identifier from a previous sign-in.
 */
export async function checkAppleCredentialState(userId) {
    return bridgeCall('NativephpSocialAuth.CheckAppleCredentialState', { userId });
}

/**
 * Sign out of the native Google session. Apple has no sign-out API.
 * @returns {Promise<{ success: boolean }>}
 */
export async function signOut() {
    return bridgeCall('NativephpSocialAuth.SignOut');
}

/**
 * NativephpSocialAuth namespace object
 */
export const nativephpSocialAuth = {
    generateNonce,
    appleSignIn,
    googleSignIn,
    checkAppleCredentialState,
    signOut
};

export default nativephpSocialAuth;
