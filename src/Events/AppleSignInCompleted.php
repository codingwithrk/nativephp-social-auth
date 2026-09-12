<?php

namespace Codingwithrk\NativephpSocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a successful native "Sign in with Apple" flow.
 *
 * `email`, `givenName`, `familyName` and `fullName` are only populated on the
 * user's FIRST authorization with your app. On every subsequent sign-in,
 * Apple only returns `userId` and `identityToken` — persist what you need
 * from the first sign-in on your backend.
 */
class AppleSignInCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $userId,
        public ?string $identityToken = null,
        public ?string $authorizationCode = null,
        public ?string $email = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $fullName = null,
        public ?string $nonce = null,
        public ?string $state = null,
        public ?string $isRealUser = null,
    ) {}
}
