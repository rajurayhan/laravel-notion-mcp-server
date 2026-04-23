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

#[Title('Notion create view')]
#[IsOpenWorld]
class NotionCreateViewTool extends Tool
{
    protected string $name = 'notion-create-view';

    public function description(): string
    {
        return 'Reserved for UI-specific database view orchestration. The public integration REST API does not expose a stable view-creation endpoint; create views inside Notion or upgrade when Notion documents an endpoint.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'data_source_id' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'type' => $schema->string()
                ->enum(['table', 'board', 'list', 'calendar', 'timeline', 'gallery', 'form', 'chart', 'map', 'dashboard'])
                ->required(),
            'database_id' => $schema->string()->nullable(),
            'parent_page_id' => $schema->string()->nullable(),
            'configure' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'data_source_id' => ['required', 'string'],
            'name' => ['required', 'string'],
            'type' => ['required', 'string'],
            'database_id' => ['nullable', 'string'],
            'parent_page_id' => ['nullable', 'string'],
            'configure' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        return Response::structured([
            'success' => false,
            'status' => 501,
            'message' => 'Creating linked views / database tabs is not implemented against the public Notion REST API for integrations. Use the Notion UI or an officially documented endpoint when available.',
            'arguments' => $validator->validated(),
        ]);
    }
}

