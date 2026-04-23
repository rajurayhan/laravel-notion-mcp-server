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

#[Title('Notion move pages')]
#[IsOpenWorld]
class NotionMovePagesTool extends Tool
{
    protected string $name = 'notion-move-pages';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Moves pages or databases to a new parent (page, database, data source, or workspace root when supported).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_or_database_ids' => $schema->array()
                ->description('Up to 100 page or database UUIDs.')
                ->min(1)
                ->max(100)
                ->required(),
            'new_parent' => $schema->object([])
                ->description('Discriminated union: {type:page_id|database_id|data_source_id|workspace,...}')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'page_or_database_ids' => ['required', 'array', 'min:1', 'max:100'],
            'page_or_database_ids.*' => ['required', 'string'],
            'new_parent' => ['required', 'array'],
            'new_parent.type' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $validated = $validator->validated();

        try {
            $parent = $this->normalizeDestinationParent($validated['new_parent']);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $results = [];

        foreach ($validated['page_or_database_ids'] as $rawId) {
            $id = NotionIdentifier::withDashes(NotionIdentifier::normalize($rawId));

            $pageProbe = $this->notion->request('GET', "/v1/pages/{$id}");
            $databaseProbe = $this->notion->request('GET', "/v1/databases/{$id}");

            if (! empty($pageProbe['success'])) {
                $payload = ['parent' => $parent];
                $res = $this->notion->request('PATCH', "/v1/pages/{$id}", $payload);
                $results[] = ['id' => $id, 'kind' => 'page', 'result' => $res];

                continue;
            }

            if (! empty($databaseProbe['success'])) {
                $payload = ['parent' => $parent];
                $res = $this->notion->request('PATCH', "/v1/databases/{$id}", $payload);
                $results[] = ['id' => $id, 'kind' => 'database', 'result' => $res];

                continue;
            }

            $results[] = [
                'id' => $id,
                'kind' => 'unknown',
                'result' => [
                    'success' => false,
                    'status' => 404,
                    'message' => 'Not a page or database id.',
                ],
            ];
        }

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => $results,
            'meta' => [
                'note' => 'Moving data sources individually is unsupported; move the parent database/page instead.',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $parent
     * @return array<string, mixed>
     */
    private function normalizeDestinationParent(array $parent): array
    {
        $type = $parent['type'] ?? '';

        return match ($type) {
            'page_id' => [
                'type' => 'page_id',
                'page_id' => NotionIdentifier::withDashes((string) ($parent['page_id'] ?? '')),
            ],
            'database_id' => [
                'type' => 'database_id',
                'database_id' => NotionIdentifier::withDashes((string) ($parent['database_id'] ?? '')),
            ],
            'data_source_id' => NotionApiVersion::supportsDataSources()
                ? [
                    'type' => 'data_source_id',
                    'data_source_id' => NotionIdentifier::withDashes((string) ($parent['data_source_id'] ?? '')),
                ]
                : throw new \InvalidArgumentException('destination data_source_id requires NOTION_API_VERSION >= 2025-09-03.'),
            'workspace' => [
                'type' => 'workspace',
                'workspace' => true,
            ],
            default => throw new \InvalidArgumentException('Unsupported new_parent.type'),
        };
    }
}

