<?php

namespace Codingwithrk\NativephpSocialAuth\Data;

/**
 * A normalized sign-in result, shared by both providers.
 *
 * Not every field is populated by every provider/event — see the individual
 * event classes for exactly what each one carries.
 */
class AuthResult
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $userId = null,
        public readonly ?string $identityToken = null,
        public readonly ?string $authorizationCode = null,
        public readonly ?string $email = null,
        public readonly ?string $givenName = null,
        public readonly ?string $familyName = null,
        public readonly ?string $fullName = null,
        public readonly ?string $photoUrl = null,
        public readonly ?string $nonce = null,
        public readonly ?string $state = null,
        public readonly ?string $isRealUser = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? 'unknown',
            userId: $data['userId'] ?? null,
            identityToken: $data['identityToken'] ?? null,
            authorizationCode: $data['authorizationCode'] ?? null,
            email: $data['email'] ?? null,
            givenName: $data['givenName'] ?? null,
            familyName: $data['familyName'] ?? null,
            fullName: $data['fullName'] ?? null,
            photoUrl: $data['photoUrl'] ?? null,
            nonce: $data['nonce'] ?? null,
            state: $data['state'] ?? null,
            isRealUser: $data['isRealUser'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'userId' => $this->userId,
            'identityToken' => $this->identityToken,
            'authorizationCode' => $this->authorizationCode,
            'email' => $this->email,
            'givenName' => $this->givenName,
            'familyName' => $this->familyName,
            'fullName' => $this->fullName,
            'photoUrl' => $this->photoUrl,
            'nonce' => $this->nonce,
            'state' => $this->state,
            'isRealUser' => $this->isRealUser,
        ];
    }
}
