## codingwithrk/nativephp-social-auth

Native Apple Sign-In (iOS) and native Google Sign-In (iOS + Android) for NativePHP Mobile apps. Both flows are
**asynchronous** — calling a sign-in method starts the native flow and returns a `bool` (whether it started), never
the sign-in result. The result always arrives later through a native event.

### PHP Usage (Livewire/Blade)

Use the `NativephpSocialAuth` facade to start a flow, and `#[OnNative(...)]` to receive its result:

@verbatim
<code-snippet name="Starting native sign-in" lang="php">
use Codingwithrk\NativephpSocialAuth\Facades\NativephpSocialAuth;

// iOS only — Android fires a SignInFailed(errorCode: 'UNSUPPORTED_PLATFORM') event immediately.
NativephpSocialAuth::appleSignIn(nonce: $nonce);

// iOS + Android
NativephpSocialAuth::googleSignIn(nonce: $nonce);

// iOS only — resolves via the AppleCredentialStateChecked event.
NativephpSocialAuth::checkAppleCredentialState($userId);

// iOS + Android. Apple has no sign-out API, so this only affects Google.
NativephpSocialAuth::signOut();
</code-snippet>
@endverbatim

### Available Methods

- `NativephpSocialAuth::appleSignIn(?array $scopes = null, ?string $nonce = null, ?string $state = null): bool`
- `NativephpSocialAuth::googleSignIn(?string $nonce = null): bool`
- `NativephpSocialAuth::checkAppleCredentialState(string $userId): bool`
- `NativephpSocialAuth::signOut(): bool`

### Events

- `AppleSignInCompleted` — `userId`, `identityToken`, `authorizationCode`, `email`, `givenName`, `familyName`, `fullName`, `nonce`, `state`, `isRealUser`. `email`/name fields are only present on the user's FIRST authorization.
- `GoogleSignInCompleted` — `userId`, `identityToken`, `email`, `givenName`, `familyName`, `fullName`, `photoUrl`, `nonce`.
- `AppleCredentialStateChecked` — `userId`, `state` (`authorized`|`revoked`|`notFound`|`transferred`|`unknown`).
- `SignInFailed` — `provider`, `error`, `errorCode` (`USER_CANCELLED`|`UNSUPPORTED_PLATFORM`|`NO_PRESENTING_CONTROLLER`|`MISSING_CONFIGURATION`|`NO_CREDENTIALS`|`INVALID_PARAMETERS`|`SIGN_IN_FAILED`). Also dispatched locally by the PHP facade when a call fails before it even reaches the device (e.g. Apple Sign-In requested on Android) — always wire this one up so there's a single failure path.

@verbatim
<code-snippet name="Listening for NativephpSocialAuth events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Codingwithrk\NativephpSocialAuth\Events\GoogleSignInCompleted;
use Codingwithrk\NativephpSocialAuth\Events\SignInFailed;

#[OnNative(GoogleSignInCompleted::class)]
public function onGoogleSignIn(string $userId, ?string $identityToken = null, ?string $email = null)
{
    // Verify $identityToken server-side before trusting any claim.
}

#[OnNative(SignInFailed::class)]
public function onSignInFailed(string $provider, string $error, ?string $errorCode = null)
{
    // Handle cancellation/failure for either provider here.
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using NativephpSocialAuth in JavaScript" lang="javascript">
import nativephpSocialAuth, { generateNonce } from '@codingwithrk/nativephp-social-auth';

const nonce = generateNonce();
await nativephpSocialAuth.googleSignIn(nonce);

On('Codingwithrk\\NativephpSocialAuth\\Events\\GoogleSignInCompleted', (payload) => {
    // payload.identityToken, payload.email, ...
});
</code-snippet>
@endverbatim

### Required `.env` secrets

`GOOGLE_IOS_CLIENT_ID`, `GOOGLE_IOS_REVERSED_CLIENT_ID`, `GOOGLE_SERVER_CLIENT_ID` — all three come from OAuth
client IDs in the same Google Cloud project (iOS, iOS again for the reversed scheme, and Web respectively). Apple
Sign-In needs no secrets — its entitlement is added automatically.

### Always verify identity tokens server-side

`identityToken` is a signed JWT. Never trust `email`/`givenName`/`familyName` from the device without verifying the
token's signature, `aud`, `iss`, and (if a nonce was passed) `nonce` claim against Apple's/Google's JWKS endpoint
first — see the plugin README for a `firebase/php-jwt` example.
