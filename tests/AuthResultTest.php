<?php

use Codingwithrk\NativephpSocialAuth\Data\AuthResult;

it('builds from a decoded event payload array', function () {
    $result = AuthResult::fromArray([
        'provider' => 'apple',
        'userId' => 'user-123',
        'identityToken' => 'jwt-token',
        'email' => 'person@example.com',
        'givenName' => 'Ada',
        'familyName' => 'Lovelace',
        'isRealUser' => 'likelyReal',
    ]);

    expect($result->provider)->toBe('apple');
    expect($result->userId)->toBe('user-123');
    expect($result->identityToken)->toBe('jwt-token');
    expect($result->email)->toBe('person@example.com');
    expect($result->givenName)->toBe('Ada');
    expect($result->familyName)->toBe('Lovelace');
    expect($result->isRealUser)->toBe('likelyReal');
    expect($result->photoUrl)->toBeNull();
});

it('defaults missing fields to null and provider to unknown', function () {
    $result = AuthResult::fromArray([]);

    expect($result->provider)->toBe('unknown');
    expect($result->userId)->toBeNull();
    expect($result->identityToken)->toBeNull();
});

it('round-trips through toArray()', function () {
    $data = [
        'provider' => 'google',
        'userId' => 'user-456',
        'identityToken' => 'jwt-token',
        'authorizationCode' => null,
        'email' => 'person@example.com',
        'givenName' => 'Grace',
        'familyName' => 'Hopper',
        'fullName' => 'Grace Hopper',
        'photoUrl' => 'https://example.com/photo.jpg',
        'nonce' => 'abc123',
        'state' => null,
        'isRealUser' => null,
    ];

    expect(AuthResult::fromArray($data)->toArray())->toBe($data);
});
