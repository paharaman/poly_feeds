<?php

declare(strict_types=1);

namespace PolyFeeds;

use DOMDocument;
use DOMElement;
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
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $document->preserveWhiteSpace = false;

        $root = $document->createElement('products');
        $document->appendChild($root);

        $vendorIds = $this->resolveVendorIds($config['vendors']);
        $seenProductIds = [];
        $productCount = 0;
        $duplicateCount = 0;

        foreach ($vendorIds as $vendorId) {
            fwrite(STDOUT, "  Vendor {$vendorId}\n");
            $groups = $this->client->getGroups($vendorId);
            fwrite(STDOUT, '    Groups: ' . count($groups) . "\n");

            foreach ($groups as $group) {
                $groupId = $group['id'];
                $subgroups = $this->client->getSubgroups(
                    $vendorId,
                    $groupId
                );

                fwrite(
                    STDOUT,
                    "    Group {$groupId}: "
                    . count($subgroups)
                    . " subgroups\n"
                );

                foreach ($subgroups as $subgroup) {
                    $subgroupId = $subgroup['id'];
                    $products = $this->client->getProducts(
                        $vendorId,
                        $groupId,
                        $subgroupId
                    );

                    fwrite(
                        STDOUT,
                        "      Subgroup {$subgroupId}: "
                        . count($products)
                        . " products\n"
                    );

                    foreach ($products as $sourceProduct) {
                        $productId = $this->getDirectChildText(
                            $sourceProduct,
                            'id'
                        );

                        if (
                            $productId !== ''
                            && isset($seenProductIds[$productId])
                        ) {
                            $duplicateCount++;
                            continue;
                        }

                        if ($productId !== '') {
                            $seenProductIds[$productId] = true;
                        }

                        $product = $document->createElement('product');
                        $this->appendTextElement(
                            $document,
                            $product,
                            'source_vendor_id',
                            $vendorId
                        );
                        $this->appendTextElement(
                            $document,
                            $product,
                            'source_group_id',
                            $groupId
                        );
                        $this->appendTextElement(
                            $document,
                            $product,
                            'source_group_name',
                            $group['name']
                        );
                        $this->appendTextElement(
                            $document,
                            $product,
                            'source_subgroup_id',
                            $subgroupId
                        );
                        $this->appendTextElement(
                            $document,
                            $product,
                            'source_subgroup_name',
                            $subgroup['name']
                        );

                        foreach ($sourceProduct->childNodes as $child) {
                            $product->appendChild(
                                $document->importNode($child, true)
                            );
                        }

                        if ($productId !== '') {
                            $this->appendTextElement(
                                $document,
                                $product,
                                'picture_url',
                                'https://polycomp.bg/poly/image/'
                                . $productId
                                . '?scale=false'
                            );
                            $this->appendTextElement(
                                $document,
                                $product,
                                'document_url',
                                'https://polycomp.bg/poly/document/'
                                . $productId
                            );
                        }

                        $root->appendChild($product);
                        $productCount++;
                    }
                }
            }
        }

        $root->setAttribute(
            'generated_at',
            gmdate('Y-m-d\TH:i:s\Z')
        );
        $root->setAttribute('count', (string) $productCount);
        $root->setAttribute(
            'duplicates_skipped',
            (string) $duplicateCount
        );

        $this->publish($document, $config['output']);

        fwrite(
            STDOUT,
            "  Products written: {$productCount}; "
            . "duplicates skipped: {$duplicateCount}\n"
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolveVendorIds(array $vendorsConfig): array
    {
        $include = $vendorsConfig['include'];
        $exclude = array_fill_keys(
            $vendorsConfig['exclude'],
            true
        );

        if ($include !== []) {
            return array_values(
                array_filter(
                    $include,
                    static fn (string $vendorId): bool =>
                        !isset($exclude[$vendorId])
                )
            );
        }

        $vendorIds = [];

        foreach ($this->client->getVendors() as $vendor) {
            if (!isset($exclude[$vendor['id']])) {
                $vendorIds[] = $vendor['id'];
            }
        }

        return $vendorIds;
    }

    private function getDirectChildText(
        DOMElement $parent,
        string $childName
    ): string {
        foreach ($parent->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && $child->tagName === $childName
            ) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    private function appendTextElement(
        DOMDocument $document,
        DOMElement $parent,
        string $name,
        string $value
    ): void {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }

    private function publish(
        DOMDocument $document,
        string $relativeOutputPath
    ): void {
        $outputPath = $this->projectRoot
            . '/'
            . $relativeOutputPath;
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

        if ($document->save($temporaryPath) === false) {
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