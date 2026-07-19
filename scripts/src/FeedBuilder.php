<?php

declare(strict_types=1);

namespace PolyFeeds;

use DOMDocument;
use RuntimeException;

final class FeedBuilder
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly PolycompClient $client
    ) {
    }

    public function build(array $config): void
    {
        /*
         * Temporary implementation.
         *
         * The next step is to connect the confirmed Polycomp
         * vendor/group/subgroup/product endpoints and map the
         * returned products to the required XML structure.
         */

        $document = new DOMDocument(
            '1.0',
            'UTF-8'
        );

        $document->formatOutput = true;

        $root = $document->createElement('products');
        $document->appendChild($root);

        $root->appendChild(
            $document->createComment(
                'Feed generation is configured. '
                . 'Catalog traversal is not connected yet.'
            )
        );

        $outputPath = $this->projectRoot
            . '/'
            . $config['output'];

        $directory = dirname($outputPath);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                "Cannot create output directory: {$directory}"
            );
        }

        $temporaryPath = $outputPath . '.tmp';

        if (
            $document->save($temporaryPath) === false
        ) {
            throw new RuntimeException(
                "Cannot write temporary feed: {$temporaryPath}"
            );
        }

        if (!rename($temporaryPath, $outputPath)) {
            @unlink($temporaryPath);

            throw new RuntimeException(
                "Cannot publish feed: {$outputPath}"
            );
        }
    }
}
