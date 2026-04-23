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

#[Title('Notion update view')]
#[IsOpenWorld]
class NotionUpdateViewTool extends Tool
{
    protected string $name = 'notion-update-view';

    public function description(): string
    {
        return 'Reserved for UI-specific database view updates. There is no stable public REST endpoint for mutating saved views via integration tokens yet.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'view_id' => $schema->string()->required(),
            'name' => $schema->string()->nullable(),
            'configure' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'view_id' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'configure' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        return Response::structured([
            'success' => false,
            'status' => 501,
            'message' => 'Updating database views is not supported through this REST integration. Adjust views in Notion.',
            'arguments' => $validator->validated(),
        ]);
    }
}

