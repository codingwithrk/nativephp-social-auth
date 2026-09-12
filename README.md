# NativephpSocialAuth

Native Apple Sign-In and Google Sign-In for [NativePHP Mobile](https://nativephp.com) apps — real platform SDKs
(`ASAuthorizationController` on iOS, Credential Manager on Android, Google Sign-In SDK on iOS), not a browser-based
OAuth redirect.

| Feature | iOS | Android |
|---|---|---|
| Apple Sign-In | ✅ | ❌ (no Apple SDK on Android) |
| Google Sign-In | ✅ | ✅ |
| Apple credential state check | ✅ | ❌ |
| Sign out (Google) | ✅ | ✅ |

## Requirements

- PHP 8.2+, Laravel 11 or 12
- NativePHP Mobile 3.x
- iOS 18.0+ / Android SDK 29+
- A paid Apple Developer Program membership (required for the Sign in with Apple entitlement)
- A Google Cloud project with **three** OAuth 2.0 client IDs (Android, iOS, Web) from the *same* project

## Installation

```bash
composer require codingwithrk/nativephp-social-auth
php artisan native:plugin:register codingwithrk/nativephp-social-auth
```

Registering the plugin is required — it's what tells NativePHP to compile this plugin's native code into your
iOS/Android build.

## Configuration

### 1. Google Cloud Console

Create three OAuth client IDs under the same project (**APIs & Services → Credentials**):

1. **Android** client — bind it to your app's package name and SHA-1 signing fingerprint. Not referenced directly
   by this plugin, but Google requires it to exist for the Android flow to be trusted.
2. **iOS** client — gives you `GOOGLE_IOS_CLIENT_ID` and its "iOS URL scheme" (`GOOGLE_IOS_REVERSED_CLIENT_ID`,
   looks like `com.googleusercontent.apps.xxxxx`).
3. **Web** client — gives you `GOOGLE_SERVER_CLIENT_ID`. This one is used on *both* platforms: Android's Credential
   Manager needs it to know which backend the ID token should be issued for, and it's also what you check the
   token's `aud` claim against on your server.

Add to your app's `.env`:

```env
GOOGLE_IOS_CLIENT_ID=123456789-abc.apps.googleusercontent.com
GOOGLE_IOS_REVERSED_CLIENT_ID=com.googleusercontent.apps.123456789-abc
GOOGLE_SERVER_CLIENT_ID=123456789-xyz.apps.googleusercontent.com
```

### 2. Apple Developer Portal

Enable the **Sign in with Apple** capability on your App ID. The `com.apple.developer.applesignin` entitlement is
added to your build automatically by this plugin's manifest — no manual Xcode configuration needed.

### 3. Publish the config (optional)

```bash
php artisan vendor:publish --tag=nativephp-social-auth-config
```

Lets you change the default Apple Sign-In scopes (`fullName`, `email`) requested when you don't pass any explicitly.

## Usage

Both flows are **asynchronous** — calling `appleSignIn()` / `googleSignIn()` starts the native flow and returns
right away. The actual result (or failure) always arrives via a native event, never as a direct return value. This
mirrors how every other async NativePHP API works (camera, geolocation, etc.) and avoids blocking the UI thread
while the system sign-in sheet is on screen.

### Livewire

```php
use Codingwithrk\NativephpSocialAuth\Facades\NativephpSocialAuth;
use Codingwithrk\NativephpSocialAuth\Events\AppleSignInCompleted;
use Codingwithrk\NativephpSocialAuth\Events\GoogleSignInCompleted;
use Codingwithrk\NativephpSocialAuth\Events\AppleCredentialStateChecked;
use Codingwithrk\NativephpSocialAuth\Events\SignInFailed;
use Native\Mobile\Attributes\OnNative;
use Livewire\Component;

class LoginScreen extends Component
{
    public ?string $nonce = null;

    public function mount(): void
    {
        $this->nonce = bin2hex(random_bytes(16));
    }

    public function signInWithApple(): void
    {
        NativephpSocialAuth::appleSignIn(nonce: $this->nonce);
    }

    public function signInWithGoogle(): void
    {
        NativephpSocialAuth::googleSignIn(nonce: $this->nonce);
    }

    #[OnNative(AppleSignInCompleted::class)]
    public function onAppleSignIn(
        string $userId,
        ?string $identityToken = null,
        ?string $email = null,
        ?string $givenName = null,
        ?string $familyName = null,
    ) {
        // Verify $identityToken server-side, then find-or-create the user.
        // $email/$givenName/$familyName are ONLY sent on the user's first
        // authorization — store them then, because Apple won't send them again.
    }

    #[OnNative(GoogleSignInCompleted::class)]
    public function onGoogleSignIn(
        string $userId,
        ?string $identityToken = null,
        ?string $email = null,
        ?string $givenName = null,
        ?string $familyName = null,
        ?string $photoUrl = null,
    ) {
        // Verify $identityToken server-side, then find-or-create the user.
    }

    #[OnNative(AppleCredentialStateChecked::class)]
    public function onCredentialStateChecked(string $userId, string $state)
    {
        // $state is one of: authorized, revoked, notFound, transferred, unknown
        if ($state === 'revoked') {
            // Log the user out locally — they revoked access in Settings.
        }
    }

    #[OnNative(SignInFailed::class)]
    public function onSignInFailed(string $provider, string $error, ?string $errorCode = null)
    {
        // errorCode is one of: USER_CANCELLED, UNSUPPORTED_PLATFORM,
        // NO_PRESENTING_CONTROLLER, MISSING_CONFIGURATION, NO_CREDENTIALS,
        // INVALID_PARAMETERS, SIGN_IN_FAILED
        if ($errorCode !== 'USER_CANCELLED') {
            report(new \RuntimeException("$provider sign-in failed: $error"));
        }
    }
}
```

<aside>
Handle both providers' completion through a single failure path where you can — every immediate failure (like
requesting Apple Sign-In on Android) also fires <code>SignInFailed</code>, so you never need a second, separate
error branch just for "the call itself didn't start".
</aside>

### JavaScript (Vue / React / Inertia)

```javascript
import nativephpSocialAuth, { generateNonce } from '@codingwithrk/nativephp-social-auth';

const nonce = generateNonce();

async function handleGoogleSignIn() {
    await nativephpSocialAuth.googleSignIn(nonce);
}

async function handleAppleSignIn() {
    await nativephpSocialAuth.appleSignIn({ nonce });
}

// Use NativePHP's global `On()` helper to receive the result.
On('Codingwithrk\\NativephpSocialAuth\\Events\\GoogleSignInCompleted', (payload) => {
    sendIdentityTokenToBackend(payload.identityToken);
});

On('Codingwithrk\\NativephpSocialAuth\\Events\\SignInFailed', (payload) => {
    console.error(`${payload.provider} sign-in failed: ${payload.error} (${payload.errorCode})`);
});
```

## Available Methods

| Method | Platforms | Description |
|---|---|---|
| `NativephpSocialAuth::appleSignIn(?array $scopes, ?string $nonce, ?string $state)` | iOS | Starts Sign in with Apple. `$scopes` defaults to config `apple.default_scopes`. |
| `NativephpSocialAuth::googleSignIn(?string $nonce)` | iOS, Android | Starts native Google Sign-In. |
| `NativephpSocialAuth::checkAppleCredentialState(string $userId)` | iOS | Checks if a stored Apple credential is still valid. |
| `NativephpSocialAuth::signOut()` | iOS, Android | Clears the native Google session. Apple has no sign-out API. |

Every method returns `bool` — whether the native flow *started* successfully, not the sign-in result itself.

## Events

| Event | Fired when |
|---|---|
| `AppleSignInCompleted` | Apple Sign-In succeeds |
| `GoogleSignInCompleted` | Google Sign-In succeeds |
| `AppleCredentialStateChecked` | A credential state check resolves |
| `SignInFailed` | Either flow fails, is cancelled, or can't start on the current platform |

## Verifying identity tokens server-side

Both providers return a signed JWT in `identityToken`. **Always verify it server-side** before trusting the claims
— never trust `email`/`givenName`/`familyName` sent from the device alone. Using
[`firebase/php-jwt`](https://github.com/firebase/php-jwt):

```php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

// Google — verify aud, iss, and (if you passed one) the nonce claim.
$keys = JWK::parseKeySet(json_decode(file_get_contents('https://www.googleapis.com/oauth2/v3/certs'), true));
$claims = JWT::decode($identityToken, $keys);
abort_unless($claims->aud === config('services.google.server_client_id'), 401);
abort_unless($claims->iss === 'https://accounts.google.com', 401);

// Apple — verify aud (your bundle ID), iss, and the nonce claim (hash your
// raw nonce with SHA-256 and compare — Apple hashes it the same way before
// embedding it in the token).
$keys = JWK::parseKeySet(json_decode(file_get_contents('https://appleid.apple.com/auth/keys'), true));
$claims = JWT::decode($identityToken, $keys);
abort_unless($claims->aud === config('nativephp.bundle_id'), 401);
abort_unless($claims->iss === 'https://appleid.apple.com', 401);
abort_unless(hash('sha256', $rawNonce) === ($claims->nonce ?? null), 401);
```

## License

MIT
