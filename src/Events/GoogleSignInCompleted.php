<?php

namespace Codingwithrk\NativephpSocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a successful native Google Sign-In flow, on both platforms.
 */
class GoogleSignInCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $userId,
        public ?string $identityToken = null,
        public ?string $email = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $fullName = null,
        public ?string $photoUrl = null,
        public ?string $nonce = null,
    ) {}
}
