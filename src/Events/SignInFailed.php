<?php

namespace Codingwithrk\NativephpSocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a sign-in attempt fails or is cancelled, for either provider.
 * Also dispatched locally by the PHP facade when a bridge call fails
 * immediately (e.g. Apple Sign-In requested on Android), so components only
 * need one failure-handling path regardless of why it failed.
 */
class SignInFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $provider,
        public string $error,
        public ?string $errorCode = null,
    ) {}
}
