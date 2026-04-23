<?php

namespace RajuRayhan\LaravelNotionMcpServer\Tools\Notion;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RajuRayhan\LaravelNotionMcpServer\Services\NotionApiService;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionApiVersion;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;

#[Title('Notion fetch')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class NotionFetchTool extends Tool
{
    protected string $name = 'notion-fetch';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Retrieve a Notion page, database, data source (when API version supports it), or paginated block children for a page/block id. Optionally loads inline comments for pages.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Page, database, data source, collection:// URI, block id, or notion.so/notion.site URL.')
                ->required(),
            'include_transcript' => $schema->boolean()
                ->description('When true, includes concatenated plain text extracted from shallow paragraph blocks.')
                ->nullable(),
            'include_discussions' => $schema->boolean()
                ->description('When true, loads comment threads via the comments API for the resolved page/block id.')
                ->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'string', 'min:4'],
            'include_transcript' => ['nullable', 'boolean'],
            'include_discussions' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $validated = $validator->validated();
        $rawId = (string) $validated['id'];
        $uuid = NotionIdentifier::extractUuidFromUrl($rawId) ?? NotionIdentifier::normalize(preg_replace('#^collection://#i', '', $rawId));

        $out = [
            'id' => $uuid,
            'requested' => $rawId,
            'resolved' => [],
            'blocks' => null,
            'comments' => null,
            'transcript' => null,
            'meta' => [
                'api_version' => NotionApiVersion::current(),
            ],
        ];

        $fetch = $this->resolveEntity($uuid);
        if (! $fetch['success']) {
            return Response::structured([
                'success' => false,
                'status' => $fetch['status'] ?? 500,
                'message' => $fetch['message'] ?? 'Fetch failed.',
                'errors' => $fetch['errors'] ?? null,
            ]);
        }

        $out['resolved'] = $fetch['data'] ?? [];

        if (! empty($validated['include_transcript']) && ($fetch['kind'] ?? null) === 'page') {
            $out['transcript'] = $this->buildTranscript($uuid);
        }

        if (! empty($validated['include_discussions'])) {
            $commentParent = $uuid;
            $out['comments'] = $this->loadComments($commentParent);
        }

        if (($fetch['kind'] ?? null) === 'page') {
            $out['blocks'] = $this->paginateBlocks($uuid);
        }

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => $out,
        ]);
    }

    /**
     * @return array{success: bool, status?: int, message?: string, data?: mixed, errors?: mixed, kind?: string}
     */
    private function resolveEntity(string $uuid): array
    {
        $uuid = NotionIdentifier::withDashes($uuid);

        $page = $this->notion->request('GET', "/v1/pages/{$uuid}");
        if (! empty($page['success'])) {
            return ['success' => true, 'status' => 200, 'data' => $page['data'], 'kind' => 'page'];
        }

        $database = $this->notion->request('GET', "/v1/databases/{$uuid}");
        if (! empty($database['success'])) {
            return ['success' => true, 'status' => 200, 'data' => $database['data'], 'kind' => 'database'];
        }

        if (NotionApiVersion::supportsDataSources()) {
            $ds = $this->notion->request('GET', "/v1/data_sources/{$uuid}");
            if (! empty($ds['success'])) {
                return ['success' => true, 'status' => 200, 'data' => $ds['data'], 'kind' => 'data_source'];
            }
        }

        $block = $this->notion->request('GET', "/v1/blocks/{$uuid}");
        if (! empty($block['success'])) {
            return ['success' => true, 'status' => 200, 'data' => $block['data'], 'kind' => 'block'];
        }

        return [
            'success' => false,
            'status' => $page['status'] ?? 404,
            'message' => $page['message'] ?? 'Could not resolve id as page, database, data source, or block.',
            'errors' => $page['errors'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paginateBlocks(string $blockId): ?array
    {
        $cursor = null;
        $all = [];

        do {
            $query = ['page_size' => 100];
            if ($cursor !== null) {
                $query['start_cursor'] = $cursor;
            }

            $res = $this->notion->request('GET', "/v1/blocks/{$blockId}/children", null, $query);
            if (empty($res['success'])) {
                return ['error' => $res['message'] ?? 'Failed to load block children'];
            }

            /** @var array<string, mixed> $data */
            $data = $res['data'];
            foreach ($data['results'] ?? [] as $row) {
                $all[] = $row;
            }

            $hasMore = ! empty($data['has_more']);
            $cursor = $hasMore ? ($data['next_cursor'] ?? null) : null;
        } while ($cursor !== null);

        return ['results' => $all];
    }

    private function buildTranscript(string $pageId): string
    {
        $blocks = $this->paginateBlocks($pageId);
        if (! is_array($blocks) || ! isset($blocks['results'])) {
            return '';
        }

        $lines = [];
        foreach ($blocks['results'] as $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'paragraph') {
                continue;
            }

            $text = $block['paragraph']['rich_text'] ?? [];
            if (! is_array($text)) {
                continue;
            }

            $chunk = '';
            foreach ($text as $part) {
                if (is_array($part) && isset($part['plain_text'])) {
                    $chunk .= $part['plain_text'];
                }
            }

            if ($chunk !== '') {
                $lines[] = $chunk;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadComments(string $blockOrPageId): ?array
    {
        $res = $this->notion->request('GET', '/v1/comments', null, [
            'block_id' => NotionIdentifier::withDashes($blockOrPageId),
            'page_size' => 100,
        ]);

        if (empty($res['success'])) {
            return ['error' => $res['message'] ?? 'Comments unavailable'];
        }

        return is_array($res['data']) ? $res['data'] : null;
    }
}

