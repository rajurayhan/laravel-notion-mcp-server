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
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\DatabaseDdlParser;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;

#[Title('Notion create database')]
#[IsOpenWorld]
class NotionCreateDatabaseTool extends Tool
{
    protected string $name = 'notion-create-database';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Creates a database from a simplified CREATE TABLE (...) DDL string (quoted column names). Title row auto-adds if omitted. Parent must be a page id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'schema' => $schema->string()->description('CREATE TABLE ("Col" TYPE, ...) DDL fragment.')->required(),
            'parent' => $schema->object()->description('{type:page_id, page_id:uuid}')->required(),
            'title' => $schema->string()->nullable(),
            'description' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'schema' => ['required', 'string'],
            'parent' => ['required', 'array'],
            'parent.type' => ['required', 'in:page_id'],
            'parent.page_id' => ['required', 'string'],
            'title' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $validated = $validator->validated();

        try {
            $parsed = DatabaseDdlParser::parse($validated['schema']);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $properties = $parsed['properties'];

        $titleProperty = null;
        foreach ($properties as $name => $def) {
            if (is_array($def) && array_key_exists('title', $def)) {
                $titleProperty = $name;

                break;
            }
        }

        if ($titleProperty === null) {
            $properties['Name'] = ['title' => new \stdClass];
        }

        $pageId = NotionIdentifier::withDashes(NotionIdentifier::normalize($validated['parent']['page_id']));

        $payload = [
            'parent' => [
                'type' => 'page_id',
                'page_id' => $pageId,
            ],
            'title' => [[
                'type' => 'text',
                'text' => ['content' => $validated['title'] ?? 'Untitled'],
            ]],
            'properties' => $properties,
        ];

        if (! empty($validated['description'])) {
            $payload['description'] = [[
                'type' => 'text',
                'text' => ['content' => $validated['description']],
            ]];
        }

        $result = $this->notion->request('POST', '/v1/databases', $payload);

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Failed to create database.',
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

