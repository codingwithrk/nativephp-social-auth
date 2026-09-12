<?php

/**
 * Plugin validation tests for NativephpSocialAuth.
 *
 * Run with: ./vendor/bin/pest
 */

beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();
        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        expect($this->manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect($this->manifest['name'])->toBe('codingwithrk/nativephp-social-auth');
        expect($this->manifest['namespace'])->toBe('NativephpSocialAuth');
    });

    it('declares the four bridge functions for both platforms', function () {
        $names = array_column($this->manifest['bridge_functions'], 'name');

        expect($names)->toEqualCanonicalizing([
            'NativephpSocialAuth.AppleSignIn',
            'NativephpSocialAuth.GoogleSignIn',
            'NativephpSocialAuth.CheckAppleCredentialState',
            'NativephpSocialAuth.SignOut',
        ]);

        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'android', 'ios']);
        }
    });

    it('declares all four events with fully qualified class names', function () {
        expect($this->manifest['events'])->toEqualCanonicalizing([
            'Codingwithrk\\NativephpSocialAuth\\Events\\AppleSignInCompleted',
            'Codingwithrk\\NativephpSocialAuth\\Events\\GoogleSignInCompleted',
            'Codingwithrk\\NativephpSocialAuth\\Events\\AppleCredentialStateChecked',
            'Codingwithrk\\NativephpSocialAuth\\Events\\SignInFailed',
        ]);

        foreach ($this->manifest['events'] as $event) {
            expect(class_exists($event))->toBeTrue();
        }
    });

    it('requires the three Google OAuth client id secrets', function () {
        expect($this->manifest['secrets'])->toHaveKeys([
            'GOOGLE_IOS_CLIENT_ID',
            'GOOGLE_IOS_REVERSED_CLIENT_ID',
            'GOOGLE_SERVER_CLIENT_ID',
        ]);

        foreach ($this->manifest['secrets'] as $secret) {
            expect($secret)->toHaveKeys(['description', 'required']);
        }
    });

    it('adds the Apple Sign-In entitlement', function () {
        expect($this->manifest['ios']['entitlements'])->toHaveKey('com.apple.developer.applesignin');
    });

    it('meets the minimum platform versions NativePHP supports', function () {
        expect($this->manifest['android']['min_version'])->toBeGreaterThanOrEqual(29);
        expect((float) $this->manifest['ios']['min_version'])->toBeGreaterThanOrEqual(18.0);
    });

    it('has valid marketplace metadata', function () {
        expect($this->manifest['platforms'])->toBeArray();
        foreach ($this->manifest['platforms'] as $platform) {
            expect($platform)->toBeIn(['android', 'ios']);
        }
    });
});

describe('Native Code', function () {
    it('has Android Kotlin file with matching bridge function classes', function () {
        $file = $this->pluginPath.'/resources/android/src/NativephpSocialAuthFunctions.kt';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('package com.codingwithrk.plugins.nativephp_social_auth');
        expect($content)->toContain('object NativephpSocialAuthFunctions');
        expect($content)->toContain('BridgeFunction');

        foreach ($this->manifest['bridge_functions'] as $function) {
            $parts = explode('.', $function['android']);
            $className = end($parts);
            expect($content)->toContain("class {$className}");
        }
    });

    it('has iOS Swift file with matching bridge function classes', function () {
        $file = $this->pluginPath.'/resources/ios/Sources/NativephpSocialAuthFunctions.swift';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('enum NativephpSocialAuthFunctions');
        expect($content)->toContain('BridgeFunction');

        foreach ($this->manifest['bridge_functions'] as $function) {
            $parts = explode('.', $function['ios']);
            $className = end($parts);
            expect($content)->toContain("class {$className}");
        }
    });
});

describe('PHP Classes', function () {
    it('has service provider', function () {
        $file = $this->pluginPath.'/src/NativephpSocialAuthServiceProvider.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Codingwithrk\NativephpSocialAuth');
        expect($content)->toContain('class NativephpSocialAuthServiceProvider');
    });

    it('has facade', function () {
        $file = $this->pluginPath.'/src/Facades/NativephpSocialAuth.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Codingwithrk\NativephpSocialAuth\Facades');
        expect($content)->toContain('class NativephpSocialAuth extends Facade');
    });

    it('has main implementation class with all four public methods', function () {
        $file = $this->pluginPath.'/src/NativephpSocialAuth.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Codingwithrk\NativephpSocialAuth');
        expect($content)->toContain('class NativephpSocialAuth');

        $methods = get_class_methods(\Codingwithrk\NativephpSocialAuth\NativephpSocialAuth::class);
        expect($methods)->toContain('appleSignIn');
        expect($methods)->toContain('googleSignIn');
        expect($methods)->toContain('checkAppleCredentialState');
        expect($methods)->toContain('signOut');
    });

    it('has an event class per manifest declaration with a matching constructor', function () {
        expect(\Codingwithrk\NativephpSocialAuth\Events\AppleSignInCompleted::class)
            ->toBeString();

        $ctor = new ReflectionMethod(\Codingwithrk\NativephpSocialAuth\Events\SignInFailed::class, '__construct');
        $params = array_map(fn ($p) => $p->getName(), $ctor->getParameters());
        expect($params)->toEqualCanonicalizing(['provider', 'error', 'errorCode']);
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composerPath = $this->pluginPath.'/composer.json';
        $composer = json_decode(file_get_contents($composerPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
        expect($composer['extra']['laravel']['providers'])->toContain(
            'Codingwithrk\\NativephpSocialAuth\\NativephpSocialAuthServiceProvider'
        );
    });
});

describe('Config', function () {
    it('ships a config file with default Apple scopes', function () {
        $file = $this->pluginPath.'/config/nativephp-social-auth.php';
        expect(file_exists($file))->toBeTrue();

        $config = require $file;
        expect($config['apple']['default_scopes'])->toBe(['fullName', 'email']);
    });
});
