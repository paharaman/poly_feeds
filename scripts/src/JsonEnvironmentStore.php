<?php

declare(strict_types=1);

namespace PolyFeeds;

use JsonException;
use RuntimeException;

abstract class JsonEnvironmentStore
{
    protected static function readJsonEnvironment(
        string $variableName
    ): array {
        $json = getenv($variableName);

        if ($json === false || trim($json) === '') {
            throw new RuntimeException(
                "Missing environment variable: {$variableName}"
            );
        }

        try {
            $decoded = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Invalid JSON in {$variableName}: "
                . $exception->getMessage(),
                previous: $exception
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                "{$variableName} must contain a JSON object or array."
            );
        }

        return $decoded;
    }
}
