<?php

declare(strict_types=1);

namespace PolyFeeds;

use DOMDocument;
use DOMElement;
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

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getVendors(): array
    {
        return $this->getNamedItems('vendors', 'vendor');
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getGroups(string $vendorId): array
    {
        return $this->getNamedItems(
            'groups/' . rawurlencode($vendorId),
            'group'
        );
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getSubgroups(
        string $vendorId,
        string $groupId
    ): array {
        return $this->getNamedItems(
            'subgroups/'
                . rawurlencode($vendorId)
                . '/'
                . rawurlencode($groupId),
            'subgroup'
        );
    }

    /**
     * Returns detached DOM elements. They can safely be imported into
     * another DOMDocument by the feed builder.
     *
     * @return array<int, DOMElement>
     */
    public function getProducts(
        string $vendorId,
        string $groupId,
        string $subgroupId
    ): array {
        $document = $this->getXmlDocument(
            'products/'
                . rawurlencode($vendorId)
                . '/'
                . rawurlencode($groupId)
                . '/'
                . rawurlencode($subgroupId)
        );

        $products = [];

        foreach ($document->getElementsByTagName('product') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $products[] = $node->cloneNode(true);
        }

        return $products;
    }

    public function getXml(string $path): string
    {
        $url = rtrim($this->baseUrl, '/')
            . '/'
            . ltrim($path, '/');

        $lastError = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
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
            'Polycomp request failed after '
            . "{$this->retryAttempts} attempts: "
            . ($lastError?->getMessage() ?? 'Unknown error')
        );
    }

    private function getXmlDocument(string $path): DOMDocument
    {
        $xml = $this->getXml($path);
        $document = new DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);
        $loaded = $document->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOBLANKS
        );

        if ($loaded === false) {
            $errors = array_map(
                static fn (\LibXMLError $error): string =>
                    trim($error->message),
                libxml_get_errors()
            );
            libxml_clear_errors();

            throw new RuntimeException(
                'Cannot parse Polycomp XML: ' . implode('; ', $errors)
            );
        }

        return $document;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function getNamedItems(
        string $path,
        string $elementName
    ): array {
        $document = $this->getXmlDocument($path);
        $items = [];

        foreach ($document->getElementsByTagName($elementName) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $id = $this->getDirectChildText($node, 'id');
            $name = $this->getDirectChildText($node, 'name');

            if ($id === '') {
                throw new RuntimeException(
                    "Polycomp {$elementName} without an ID returned by {$path}."
                );
            }

            $items[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $items;
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

    private function request(string $url): string
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Cannot initialize cURL.');
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(20, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => $this->authenticator->createHeaders(),
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

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status < 200 || $status >= 300) {
            $snippet = trim(substr((string) $body, 0, 500));

            throw new RuntimeException(
                "HTTP {$status} for {$url}. Response: {$snippet}"
            );
        }

        $body = trim((string) $body);

        if ($body === '') {
            throw new RuntimeException("Empty response from {$url}");
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
                'Invalid XML response: ' . implode('; ', $errors)
            );
        }

        return $body;
    }
}