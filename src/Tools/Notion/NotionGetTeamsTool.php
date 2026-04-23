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

#[Title('Notion get teams')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class NotionGetTeamsTool extends Tool
{
    protected string $name = 'notion-get-teams';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Attempts to enumerate teamspaces via lightweight REST probes; results depend on workspace permissions and API availability for your integration.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'query' => ['nullable', 'string', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $query = strtolower((string) ($validator->validated()['query'] ?? ''));

        $paths = ['/v1/organizations', '/v1/teams'];

        foreach ($paths as $path) {
            $attempt = $this->notion->request('GET', $path);
            if (! empty($attempt['success'])) {
                return Response::structured([
                    'success' => true,
                    'status' => $attempt['status'] ?? 200,
                    'data' => $attempt['data'],
                    'meta' => [
                        'endpoint' => $path,
                        'note' => 'Team listings vary by workspace plan and token scopes.',
                    ],
                ]);
            }
        }

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => [
                'results' => [],
                'has_more' => false,
                'filter' => $query,
            ],
            'meta' => [
                'note' => 'No compatible team listing endpoint responded successfully for this token. Use workspace settings to inspect teamspaces manually.',
            ],
        ]);
    }
}

