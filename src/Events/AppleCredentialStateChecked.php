<?php

namespace Codingwithrk\NativephpSocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after checkAppleCredentialState() resolves. `state` is one of:
 * "authorized", "revoked", "notFound", "transferred" or "unknown".
 */
class AppleCredentialStateChecked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $userId,
        public string $state,
    ) {}
}
