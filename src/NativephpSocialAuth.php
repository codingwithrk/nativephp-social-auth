<?php

namespace Codingwithrk\NativephpSocialAuth;

use Codingwithrk\NativephpSocialAuth\Events\SignInFailed;

class NativephpSocialAuth
{
    /**
     * Start the native "Sign in with Apple" flow.
     *
     * iOS only — Android has no Apple Sign-In SDK and immediately fails with
     * an UNSUPPORTED_PLATFORM error. The result arrives asynchronously via
     * the AppleSignInCompleted or SignInFailed event.
     *
     * @param  array<int, string>|null  $scopes  Requested scopes: "fullName", "email".
     *                                            Defaults to config('nativephp-social-auth.apple.default_scopes').
     * @param  string|null  $nonce  Raw (unhashed) nonce for replay protection. Hash it again
     *                              (SHA-256, hex) server-side and compare with the identityToken's
     *                              `nonce` claim.
     * @param  string|null  $state  Opaque value echoed back unchanged in the completed event.
     * @return bool  True once the native flow has started. False if it failed immediately
     *               (a SignInFailed event is dispatched in that case too).
     */
    public function appleSignIn(?array $scopes = null, ?string $nonce = null, ?string $state = null): bool
    {
        return $this->call('NativephpSocialAuth.AppleSignIn', [
            'scopes' => $scopes ?? config('nativephp-social-auth.apple.default_scopes', ['fullName', 'email']),
            'nonce' => $nonce,
            'state' => $state,
        ], provider: 'apple');
    }

    /**
     * Start the native Google Sign-In flow — Credential Manager on Android,
     * the Google Sign-In SDK on iOS. The result arrives asynchronously via
     * the GoogleSignInCompleted or SignInFailed event.
     *
     * @param  string|null  $nonce  Raw nonce for replay protection, echoed back and folded into
     *                              the identityToken so it can be re-hashed and verified server-side.
     * @return bool  True once the native flow has started. False if it failed immediately.
     */
    public function googleSignIn(?string $nonce = null): bool
    {
        return $this->call('NativephpSocialAuth.GoogleSignIn', [
            'nonce' => $nonce,
        ], provider: 'google');
    }

    /**
     * Check whether a previously issued Apple credential is still valid.
     *
     * iOS only. The result arrives asynchronously via the
     * AppleCredentialStateChecked event.
     *
     * @param  string  $userId  The stable user identifier from a previous AppleSignInCompleted event.
     * @return bool  True once the check has started. False if it failed immediately.
     */
    public function checkAppleCredentialState(string $userId): bool
    {
        return $this->call('NativephpSocialAuth.CheckAppleCredentialState', [
            'userId' => $userId,
        ], provider: 'apple');
    }

    /**
     * Sign out of the native Google session. Apple has no sign-out API, so
     * this only affects Google — Apple Sign-In state is managed entirely by
     * iOS and there is nothing for an app to clear.
     */
    public function signOut(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('NativephpSocialAuth.SignOut', json_encode([]));
        $decoded = $result ? json_decode($result) : null;

        return (bool) ($decoded->data->success ?? false);
    }

    /**
     * Call a bridge function and, if it fails immediately (e.g. an unsupported
     * platform or missing configuration), dispatch a SignInFailed event so
     * consumers only need to wire up one failure-handling path.
     */
    protected function call(string $function, array $params, string $provider): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call($function, json_encode($params));
        $decoded = $result ? json_decode($result) : null;

        if ($decoded === null) {
            return false;
        }

        if (isset($decoded->error)) {
            $error = $decoded->error;
            $message = is_object($error) ? ($error->message ?? 'Unknown error') : (string) $error;
            $code = is_object($error) ? ($error->code ?? null) : null;

            event(new SignInFailed(provider: $provider, error: $message, errorCode: $code));

            return false;
        }

        return true;
    }
}
