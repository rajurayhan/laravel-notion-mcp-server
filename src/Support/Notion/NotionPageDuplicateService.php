<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

use RajuRayhan\LaravelNotionMcpServer\Services\NotionApiService;

/**
 * Best-effort page duplication using block children copy (public Notion API has no single duplicate call).
 */
final class NotionPageDuplicateService
{
    private int $blocksCopied = 0;

    private const MAX_BLOCKS = 200;

    public function __construct(private readonly NotionApiService $notion) {}

    /**
     * @return array{success: bool, status?: int, message?: string, data?: mixed, errors?: mixed}
     */
    public function duplicate(string $pageId): array
    {
        $this->blocksCopied = 0;

        $pageId = NotionIdentifier::withDashes(NotionIdentifier::normalize($pageId));

        $page = $this->notion->request('GET', "/v1/pages/{$pageId}");
        if (empty($page['success'])) {
            return $page;
        }

        /** @var array<string, mixed> $pageData */
        $pageData = $page['data'];
        $parent = $pageData['parent'] ?? null;
        if (! is_array($parent)) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Could not determine parent for duplicated page.',
            ];
        }

        $createPayload = [
            'parent' => $parent,
            'properties' => $pageData['properties'] ?? [],
        ];

        $created = $this->notion->request('POST', '/v1/pages', $createPayload);
        if (empty($created['success'])) {
            return $created;
        }

        /** @var array<string, mixed> $newPage */
        $newPage = $created['data'];
        $newId = $newPage['id'] ?? null;
        if (! is_string($newId)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Duplicate created but response had no page id.',
            ];
        }

        $copy = $this->copyBlocksRecursive($pageId, $newId);
        if (! $copy['success']) {
            return $copy;
        }

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'page' => $newPage,
                'note' => 'Duplication copies block trees recursively up to ' . self::MAX_BLOCKS . ' blocks. Child pages/databases inside the tree are skipped.',
                'blocks_copied' => $this->blocksCopied,
            ],
        ];
    }

    /**
     * @return array{success: bool, status?: int, message?: string}
     */
    private function copyBlocksRecursive(string $sourceBlockId, string $targetParentId): array
    {
        $cursor = null;

        do {
            $query = ['page_size' => 100];
            if ($cursor !== null) {
                $query['start_cursor'] = $cursor;
            }

            $children = $this->notion->request('GET', "/v1/blocks/{$sourceBlockId}/children", null, $query);
            if (empty($children['success'])) {
                return ['success' => false, 'status' => $children['status'] ?? 500, 'message' => $children['message'] ?? 'Failed to list block children.'];
            }

            /** @var array<string, mixed> $payload */
            $payload = $children['data'];
            $results = $payload['results'] ?? [];
            if (! is_array($results)) {
                break;
            }

            /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> $pairs */
            $pairs = [];
            foreach ($results as $original) {
                if ($this->blocksCopied >= self::MAX_BLOCKS) {
                    break 2;
                }

                if (! is_array($original)) {
                    continue;
                }

                $sanitized = $this->sanitizeBlockForAppend($original);
                if ($sanitized !== null) {
                    $pairs[] = [$original, $sanitized];
                }
            }

            if ($pairs !== []) {
                $blocksToAppend = array_map(static fn ($p) => $p[1], $pairs);

                $append = $this->notion->request('PATCH', "/v1/blocks/{$targetParentId}/children", [
                    'children' => $blocksToAppend,
                ]);

                if (empty($append['success'])) {
                    return ['success' => false, 'status' => $append['status'] ?? 500, 'message' => $append['message'] ?? 'Failed to append duplicated blocks.'];
                }

                /** @var array<string, mixed>|null $appendData */
                $appendData = $append['data'];
                $newBlocks = is_array($appendData['results'] ?? null) ? $appendData['results'] : [];

                foreach ($pairs as $idx => $pair) {
                    if ($this->blocksCopied >= self::MAX_BLOCKS) {
                        break 2;
                    }

                    /** @var array<string, mixed> $original */
                    $original = $pair[0];
                    $hasChildren = ! empty($original['has_children']);
                    $origId = $original['id'] ?? null;
                    $newBlock = $newBlocks[$idx] ?? null;

                    if ($hasChildren && is_string($origId) && is_array($newBlock) && isset($newBlock['id'])) {
                        $nested = $this->copyBlocksRecursive($origId, (string) $newBlock['id']);
                        if (! $nested['success']) {
                            return $nested;
                        }
                    }

                    $this->blocksCopied++;
                }
            }

            $hasMore = ! empty($payload['has_more']);
            $cursor = $hasMore ? ($payload['next_cursor'] ?? null) : null;
        } while ($cursor !== null && $this->blocksCopied < self::MAX_BLOCKS);

        return ['success' => true];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function sanitizeBlockForAppend(array $block): ?array
    {
        $type = $block['type'] ?? null;
        if (! is_string($type)) {
            return null;
        }

        if ($type === 'child_page' || $type === 'child_database') {
            return null;
        }

        $payload = $block[$type] ?? null;
        if (! is_array($payload)) {
            return null;
        }

        return [
            'object' => 'block',
            'type' => $type,
            $type => $payload,
        ];
    }
}

