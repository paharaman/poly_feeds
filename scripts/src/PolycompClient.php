<?php

declare(strict_types=1);

namespace PolyFeeds;

use RuntimeException;

final class PolycompClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly WsseAuthenticator $authenticator,
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryAttempts = 3
    ) {
    }

    public function getXml(string $path): string
    {
        $url = rtrim($this->baseUrl, '/')
            . '/'
            . ltrim($path, '/');

        $lastError = null;

        for (
            $attempt = 1;
            $attempt <= $this->retryAttempts;
            $attempt++
        ) {
            try {
                return $this->request($url);
            } catch (RuntimeException $exception) {
                $lastError = $exception;

                if ($attempt < $this->retryAttempts) {
                    sleep($attempt);
                }
            }
        }

        throw new RuntimeException(
            "Polycomp request failed after "
            . "{$this->retryAttempts} attempts: "
            . ($lastError?->getMessage() ?? 'Unknown error')
        );
    }

    private function request(string $url): string
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'Cannot initialize cURL.'
            );
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT =>
                    min(20, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER =>
                    $this->authenticator->createHeaders(),
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'poly-feeds/1.0',
            ]
        );

        $body = curl_exec($curl);

        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new RuntimeException(
                "Network error for {$url}: {$error}"
            );
        }

        $status = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        if ($status < 200 || $status >= 300) {
            $snippet = trim(
                substr((string) $body, 0, 500)
            );

            throw new RuntimeException(
                "HTTP {$status} for {$url}. "
                . "Response: {$snippet}"
            );
        }

        $body = trim((string) $body);

        if ($body === '') {
            throw new RuntimeException(
                "Empty response from {$url}"
            );
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            $errors = array_map(
                static fn (\LibXMLError $error): string =>
                    trim($error->message),
                libxml_get_errors()
            );

            libxml_clear_errors();

            throw new RuntimeException(
                'Invalid XML response: '
                . implode('; ', $errors)
            );
        }

        return $body;
    }
}
