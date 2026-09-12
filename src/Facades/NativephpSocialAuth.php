<?php

namespace Codingwithrk\NativephpSocialAuth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool appleSignIn(?array $scopes = null, ?string $nonce = null, ?string $state = null)
 * @method static bool googleSignIn(?string $nonce = null)
 * @method static bool checkAppleCredentialState(string $userId)
 * @method static bool signOut()
 *
 * @see \Codingwithrk\NativephpSocialAuth\NativephpSocialAuth
 */
class NativephpSocialAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Codingwithrk\NativephpSocialAuth\NativephpSocialAuth::class;
    }
}
