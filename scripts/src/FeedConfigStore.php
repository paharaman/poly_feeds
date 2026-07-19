<?php

declare(strict_types=1);

namespace PolyFeeds;

use RuntimeException;

final class FeedConfigStore extends JsonEnvironmentStore
{
    private const DEFAULT_POLYCOMP_CONFIG = [
        'base_url' => 'https://api.polycomp.bg/service/data/v1',
        'fetch_extended_data' => false,
        'request_timeout_seconds' => 60,
        'retry_attempts' => 3,
    ];

    public function __construct(
        private readonly array $feeds
    ) {
    }

    public static function fromEnvironment(
        string $variableName
    ): self {
        return new self(
            self::readJsonEnvironment($variableName)
        );
    }

    public function getEnabledFeeds(): array
    {
        $validated = [];
        $seenIds = [];
        $seenOutputs = [];

        foreach ($this->feeds as $index => $feed) {
            if (!is_array($feed)) {
                throw new RuntimeException(
                    "Feed configuration at index {$index} must be an object."
                );
            }

            $config = $this->validateFeed($feed, $index);

            if ($config['enabled'] !== true) {
                continue;
            }

            if (isset($seenIds[$config['id']])) {
                throw new RuntimeException(
                    "Duplicate feed ID: {$config['id']}"
                );
            }

            if (isset($seenOutputs[$config['output']])) {
                throw new RuntimeException(
                    "Duplicate feed output: {$config['output']}"
                );
            }

            $seenIds[$config['id']] = true;
            $seenOutputs[$config['output']] = true;
            $validated[] = $config;
        }

        return $validated;
    }

    private function validateFeed(
        array $feed,
        int|string $index
    ): array {
        foreach (
            ['id', 'credentials_profile', 'output']
            as $requiredKey
        ) {
            if (
                !isset($feed[$requiredKey])
                || !is_string($feed[$requiredKey])
                || trim($feed[$requiredKey]) === ''
            ) {
                throw new RuntimeException(
                    "Missing or invalid '{$requiredKey}' "
                    . "in feed configuration at index {$index}."
                );
            }
        }

        $id = trim($feed['id']);

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new RuntimeException(
                "Invalid feed ID: {$id}"
            );
        }

        $output = trim($feed['output']);

        if (
            !preg_match(
                '#^docs/[a-zA-Z0-9_-]+\.xml$#',
                $output
            )
        ) {
            throw new RuntimeException(
                "Feed output must match docs/<safe-name>.xml: {$output}"
            );
        }

        $vendors = $feed['vendors'] ?? [
            'include' => [],
            'exclude' => [],
        ];

        if (!is_array($vendors)) {
            throw new RuntimeException(
                "vendors must be an object for feed {$id}."
            );
        }

        $include = $vendors['include'] ?? [];
        $exclude = $vendors['exclude'] ?? [];

        if (!is_array($include) || !is_array($exclude)) {
            throw new RuntimeException(
                "vendors.include and vendors.exclude "
                . "must be arrays for feed {$id}."
            );
        }

        $include = $this->validateStringList(
            $include,
            "vendors.include",
            $id
        );

        $exclude = $this->validateStringList(
            $exclude,
            "vendors.exclude",
            $id
        );

        $polycomp = array_merge(
            self::DEFAULT_POLYCOMP_CONFIG,
            is_array($feed['polycomp'] ?? null)
                ? $feed['polycomp']
                : []
        );

        if (
            !is_string($polycomp['base_url'])
            || !str_starts_with(
                $polycomp['base_url'],
                'https://'
            )
        ) {
            throw new RuntimeException(
                "polycomp.base_url must use HTTPS for feed {$id}."
            );
        }

        $timeout = (int) $polycomp['request_timeout_seconds'];
        $retries = (int) $polycomp['retry_attempts'];

        if ($timeout < 1 || $timeout > 300) {
            throw new RuntimeException(
                "Invalid request timeout for feed {$id}."
            );
        }

        if ($retries < 1 || $retries > 10) {
            throw new RuntimeException(
                "Invalid retry count for feed {$id}."
            );
        }

        return [
            'id' => $id,
            'enabled' => ($feed['enabled'] ?? true) === true,
            'credentials_profile' => trim(
                $feed['credentials_profile']
            ),
            'output' => $output,
            'vendors' => [
                'include' => $include,
                'exclude' => $exclude,
            ],
            'polycomp' => [
                'base_url' => rtrim(
                    $polycomp['base_url'],
                    '/'
                ),
                'fetch_extended_data' =>
                    ($polycomp['fetch_extended_data'] ?? false) === true,
                'request_timeout_seconds' => $timeout,
                'retry_attempts' => $retries,
            ],
        ];
    }

    private function validateStringList(
        array $values,
        string $field,
        string $feedId
    ): array {
        $result = [];

        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException(
                    "{$field} contains an invalid value "
                    . "for feed {$feedId}."
                );
            }

            $result[] = trim($value);
        }

        return array_values(array_unique($result));
    }
}
