<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3Example\Simulator;

/**
 * Tiny JSON-file-backed store standing in for the platform's own gradebook
 * storage, just enough to make the AGS demo endpoints behave statefully
 * (create -> read -> update -> delete, score publish -> result read).
 */
final class Database
{
    /**
     * @var array{lineItems: array<string, array<string, mixed>>, results: array<string, array<string, array<string, mixed>>>, nextLineItemId: int}
     */
    private array $data;

    public function __construct(private readonly string $path)
    {
        if (!is_file($this->path)) {
            $this->data = ['lineItems' => [], 'results' => [], 'nextLineItemId' => 1];
            $this->write();
        } else {
            /** @var array{lineItems: array<string, array<string, mixed>>, results: array<string, array<string, array<string, mixed>>>, nextLineItemId: int} $data */
            $data = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
            $this->data = $data;
        }
    }

    private function write(): void
    {
        file_put_contents($this->path, json_encode($this->data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * Ensures a demo line item exists (auto-created on first use), so the
     * example's single-line-item AGS flow has something to score against
     * without a separate "create it first" step.
     *
     * @return array<string, mixed>
     */
    public function ensureDefaultLineItem(): array
    {
        if ($this->data['lineItems'] !== []) {
            return $this->data['lineItems'][array_key_first($this->data['lineItems'])];
        }

        return $this->createLineItem([
            'scoreMaximum' => 10.0,
            'label' => 'Demo Assignment',
            'resourceLinkId' => 'demo-resource-link',
        ]);
    }

    /**
     * @param array<string, mixed> $lineItem
     * @return array<string, mixed>
     */
    public function createLineItem(array $lineItem): array
    {
        $id = $this->data['nextLineItemId'];
        $lineItem['id'] = (string) $id;
        $this->data['lineItems'][(string) $id] = $lineItem;
        $this->data['nextLineItemId'] = $id + 1;
        $this->write();

        return $lineItem;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLineItem(string $id): ?array
    {
        return $this->data['lineItems'][$id] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLineItems(): array
    {
        return array_values($this->data['lineItems']);
    }

    /**
     * @param array<string, mixed> $lineItem
     * @return array<string, mixed>|null
     */
    public function updateLineItem(string $id, array $lineItem): ?array
    {
        if (!isset($this->data['lineItems'][$id])) {
            return null;
        }

        $lineItem['id'] = $id;
        $this->data['lineItems'][$id] = $lineItem;
        $this->write();

        return $lineItem;
    }

    public function deleteLineItem(string $id): void
    {
        unset($this->data['lineItems'][$id]);
        unset($this->data['results'][$id]);
        $this->write();
    }

    /**
     * @param array<string, mixed> $score
     */
    public function recordScore(string $lineItemId, array $score): void
    {
        $userId = $score['userId'];
        if (!is_string($userId)) {
            throw new \InvalidArgumentException('Score must have a string userId.');
        }

        $this->data['results'][$lineItemId][$userId] = $score;
        $this->write();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listResults(string $lineItemId): array
    {
        return array_values($this->data['results'][$lineItemId] ?? []);
    }
}
