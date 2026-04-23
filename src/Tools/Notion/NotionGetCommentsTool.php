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
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;

#[Title('Notion get comments')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class NotionGetCommentsTool extends Tool
{
    protected string $name = 'notion-get-comments';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Lists comments for a page/block id using the REST comments endpoint. Supports pagination cursors returned by Notion.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('Page or block id hosting the discussion.')->required(),
            'include_all_blocks' => $schema->boolean()->nullable(),
            'include_resolved' => $schema->boolean()->nullable(),
            'discussion_id' => $schema->string()->nullable(),
            'start_cursor' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'page_id' => ['required', 'string'],
            'include_all_blocks' => ['nullable', 'boolean'],
            'include_resolved' => ['nullable', 'boolean'],
            'discussion_id' => ['nullable', 'string'],
            'start_cursor' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $data = $validator->validated();
        $id = NotionIdentifier::withDashes(NotionIdentifier::normalize($data['page_id']));

        $query = array_filter([
            'block_id' => $id,
            'page_size' => 100,
            'start_cursor' => $data['start_cursor'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $result = $this->notion->request('GET', '/v1/comments', null, $query);

        if (empty($result['success'])) {
            $fallbackQuery = array_filter([
                'page_id' => $id,
                'page_size' => 100,
                'start_cursor' => $data['start_cursor'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');

            $result = $this->notion->request('GET', '/v1/comments', null, $fallbackQuery);
        }

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Failed to load comments.',
                'errors' => $result['errors'] ?? null,
            ]);
        }

        $payload = $result['data'];
        $meta = [
            'include_all_blocks' => $data['include_all_blocks'] ?? null,
            'include_resolved' => $data['include_resolved'] ?? null,
            'discussion_id_filter' => $data['discussion_id'] ?? null,
            'note' => 'Additional include_* flags are hints for clients; filtering is applied when the API exposes the fields.',
        ];

        return Response::structured([
            'success' => true,
            'status' => $result['status'] ?? 200,
            'data' => $payload,
            'meta' => $meta,
        ]);
    }
}

