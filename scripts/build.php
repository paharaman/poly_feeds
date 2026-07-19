<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PolyFeeds\CredentialsStore;
use PolyFeeds\FeedBuilder;
use PolyFeeds\FeedConfigStore;
use PolyFeeds\PolycompClient;
use PolyFeeds\WsseAuthenticator;

$projectRoot = dirname(__DIR__);

try {
    $credentialsStore = CredentialsStore::fromEnvironment(
        'POLYCOMP_ACCOUNTS'
    );

    $feedConfigStore = FeedConfigStore::fromEnvironment(
        'POLYCOMP_FEEDS'
    );

    $configs = $feedConfigStore->getEnabledFeeds();

    if ($configs === []) {
        fwrite(STDOUT, "No enabled feeds found.\n");
        exit(0);
    }

    foreach ($configs as $config) {
        $feedId = $config['id'];

        fwrite(
            STDOUT,
            "Building feed: {$feedId}\n"
        );

        $credentials = $credentialsStore->get(
            $config['credentials_profile']
        );

        $authenticator = new WsseAuthenticator(
            username: $credentials['username'],
            password: $credentials['password'],
            apiCode: $credentials['api_code']
        );

        $polycompConfig = $config['polycomp'];

        $client = new PolycompClient(
            baseUrl: $polycompConfig['base_url'],
            authenticator: $authenticator,
            timeoutSeconds: (int) $polycompConfig['request_timeout_seconds'],
            retryAttempts: (int) $polycompConfig['retry_attempts']
        );

        $builder = new FeedBuilder(
            projectRoot: $projectRoot,
            client: $client
        );

        $builder->build($config);

        fwrite(
            STDOUT,
            "Completed: {$config['output']}\n"
        );
    }
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        '[ERROR] ' . $exception->getMessage() . PHP_EOL
    );

    exit(1);
}
