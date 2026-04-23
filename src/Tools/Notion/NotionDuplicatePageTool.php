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
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionPageDuplicateService;

#[Title('Notion duplicate page')]
#[IsOpenWorld]
class NotionDuplicatePageTool extends Tool
{
    protected string $name = 'notion-duplicate-page';

    public function __construct(
        private readonly NotionPageDuplicateService $duplicateService,
    ) {}

    public function description(): string
    {
        return 'Creates a copy of a page under the same parent by cloning properties and recursively copying supported block types (child pages/databases are skipped).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('Source page UUID or URL fragment.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'page_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $result = $this->duplicateService->duplicate((string) $validator->validated()['page_id']);

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Duplicate failed.',
                'errors' => $result['errors'] ?? null,
            ]);
        }

        return Response::structured([
            'success' => true,
            'status' => $result['status'] ?? 200,
            'data' => $result['data'] ?? null,
        ]);
    }
}

