<?php

declare(strict_types=1);

namespace PolyFeeds;

use RuntimeException;

final class CredentialsStore extends JsonEnvironmentStore
{
    public function __construct(
        private readonly array $accounts
    ) {
    }

    public static function fromEnvironment(
        string $variableName
    ): self {
        return new self(
            self::readJsonEnvironment($variableName)
        );
    }

    public function get(string $profile): array
    {
        $credentials = $this->accounts[$profile] ?? null;

        if (!is_array($credentials)) {
            throw new RuntimeException(
                "Unknown credentials profile: {$profile}"
            );
        }

        foreach (['username', 'password', 'api_code'] as $key) {
            if (
                !isset($credentials[$key])
                || !is_string($credentials[$key])
                || trim($credentials[$key]) === ''
            ) {
                throw new RuntimeException(
                    "Missing '{$key}' in credentials profile {$profile}"
                );
            }
        }

        return [
            'username' => trim($credentials['username']),
            'password' => $credentials['password'],
            'api_code' => trim($credentials['api_code']),
        ];
    }
}
