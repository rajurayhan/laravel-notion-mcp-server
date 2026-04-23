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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use RajuRayhan\LaravelNotionMcpServer\Services\NotionApiService;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionApiVersion;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;

#[Title('Notion update data source')]
#[IsOpenWorld]
class NotionUpdateDataSourceTool extends Tool
{
    protected string $name = 'notion-update-data-source';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Patches a Notion data source when your token uses API version >= 2025-09-03. Accepts title/description/trash flags or raw `properties` JSON. SQL-like `statements` strings are not parsed here—pass equivalent `properties` updates instead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'data_source_id' => $schema->string()->description('collection:// URI or UUID.')->required(),
            'statements' => $schema->string()->nullable(),
            'title' => $schema->string()->nullable(),
            'description' => $schema->string()->nullable(),
            'is_inline' => $schema->boolean()->nullable(),
            'in_trash' => $schema->boolean()->nullable(),
            'properties' => $schema->object()->description('Direct Notion API properties map for PATCH /v1/data_sources/{id}.')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        if (! NotionApiVersion::supportsDataSources()) {
            return Response::structured([
                'success' => false,
                'status' => 400,
                'message' => 'Set NOTION_API_VERSION to 2025-09-03 or newer to update data sources.',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'data_source_id' => ['required', 'string'],
            'statements' => ['nullable', 'string'],
            'title' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_inline' => ['nullable', 'boolean'],
            'in_trash' => ['nullable', 'boolean'],
            'properties' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $data = $validator->validated();

        if (! empty($data['statements'])) {
            return Response::structured([
                'success' => false,
                'status' => 422,
                'message' => 'Automated parsing of SQL-like statements is not implemented. Fetch the current schema with notion-fetch, build a Notion properties map, and pass it via the properties argument.',
                'hint' => $data['statements'],
            ]);
        }

        $id = NotionIdentifier::withDashes(NotionIdentifier::normalize(preg_replace('#^collection://#i', '', $data['data_source_id'])));

        $payload = [];
        if (isset($data['title'])) {
            $payload['title'] = [[
                'type' => 'text',
                'text' => ['content' => $data['title']],
            ]];
        }

        if (isset($data['description'])) {
            $payload['description'] = [[
                'type' => 'text',
                'text' => ['content' => $data['description']],
            ]];
        }

        if (array_key_exists('is_inline', $data)) {
            $payload['is_inline'] = (bool) $data['is_inline'];
        }

        if (array_key_exists('in_trash', $data)) {
            $payload['in_trash'] = (bool) $data['in_trash'];
        }

        if (! empty($data['properties'])) {
            $payload['properties'] = $data['properties'];
        }

        if ($payload === []) {
            return Response::error('Provide at least one of title, description, is_inline, in_trash, or properties.');
        }

        $result = $this->notion->request('PATCH', "/v1/data_sources/{$id}", $payload);

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Failed to update data source.',
                'errors' => $result['errors'] ?? null,
            ]);
        }

        return Response::structured([
            'success' => true,
            'status' => $result['status'] ?? 200,
            'data' => $result['data'],
        ]);
    }
}

