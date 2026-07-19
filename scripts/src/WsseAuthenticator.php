<?php

declare(strict_types=1);

namespace PolyFeeds;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class WsseAuthenticator
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $apiCode
    ) {
    }

    public function createHeaders(): array
    {
        $nonce = $this->generateNonce();

        $created = (
            new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )
        )->format('Y-m-d\TH:i:s\Z');

        $passwordMd5 = strtoupper(
            md5($this->password)
        );

        $passwordDigest = base64_encode(
            sha1(
                $this->apiCode
                    . $nonce
                    . $created
                    . $passwordMd5,
                true
            )
        );

        $xWsse = sprintf(
            'UsernameToken Username="%s", PasswordDigest="%s", Nonce="%s", Created="%s", ApiCode="%s"',
            $this->escapeHeaderValue($this->username),
            $this->escapeHeaderValue($passwordDigest),
            $this->escapeHeaderValue($nonce),
            $this->escapeHeaderValue($created),
            $this->escapeHeaderValue($this->apiCode)
        );

        return [
            'Authorization: WSSE profile="UsernameToken"',
            'X-WSSE: ' . $xWsse,
            'Accept: application/xml',
        ];
    }

    private function generateNonce(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
            . 'abcdefghijklmnopqrstuvwxyz'
            . '0123456789';

        $nonce = '';

        for ($position = 0; $position < 16; $position++) {
            $nonce .= $alphabet[
                random_int(
                    0,
                    strlen($alphabet) - 1
                )
            ];
        }

        return $nonce;
    }

    private function escapeHeaderValue(
        string $value
    ): string {
        if (
            str_contains($value, "\r")
            || str_contains($value, "\n")
        ) {
            throw new RuntimeException(
                'Invalid newline in WSSE header value.'
            );
        }

        return addcslashes($value, "\\\"");
    }
}
