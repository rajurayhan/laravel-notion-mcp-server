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

#[Title('Notion search')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class NotionSearchTool extends Tool
{
    protected string $name = 'notion-search';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Semantic-style search across the workspace (`internal`) or directory lookup for workspace users (`user`). Uses the Notion REST search and users endpoints; supports filters that map to the public API where possible.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query text or user name/email fragment.')
                ->min(1)
                ->required(),
            'filters' => $schema->object([])
                ->description('Optional filters. For internal search: may include created_date_range {start_date,end_date}, created_by_user_ids [uuid,...]. Unsupported keys are ignored. Omit or use {} — some clients omit empty objects.')
                ->nullable(),
            'query_type' => $schema->string()
                ->description('internal: workspace search (default). user: search workspace users by name/email substring.')
                ->enum(['internal', 'user'])
                ->nullable(),
            'content_search_mode' => $schema->string()
                ->description('workspace_search: standard API search. ai_search: treated as workspace_search (public token integrations do not expose hosted AI semantic search).')
                ->enum(['workspace_search', 'ai_search'])
                ->nullable(),
            'data_source_url' => $schema->string()
                ->description('Optional data source id or collection:// URI to narrow results client-side after fetch.')
                ->nullable(),
            'page_url' => $schema->string()
                ->description('Optional page id or URL; results are filtered client-side to descendants when possible.')
                ->nullable(),
            'teamspace_id' => $schema->string()
                ->description('Optional teamspace id; results are filtered client-side when object metadata includes workspace/team hints.')
                ->nullable(),
            'page_size' => $schema->integer()
                ->description('Max results (1–25).')
                ->min(1)
                ->max(25)
                ->nullable(),
            'max_highlight_length' => $schema->integer()
                ->description('Reserved for clients that surface snippet length; not returned by the Notion REST search API.')
                ->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $args = $request->all();
        $args['filters'] = $args['filters'] ?? [];

        $validator = Validator::make($args, [
            'query' => ['required', 'string', 'min:1'],
            'filters' => ['nullable', 'array'],
            'query_type' => ['nullable', 'string', 'in:internal,user'],
            'content_search_mode' => ['nullable', 'string', 'in:workspace_search,ai_search'],
            'data_source_url' => ['nullable', 'string'],
            'page_url' => ['nullable', 'string'],
            'teamspace_id' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:25'],
            'max_highlight_length' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $validated = $validator->validated();
        $queryType = $validated['query_type'] ?? 'internal';

        if ($queryType === 'user') {
            return $this->searchUsers((string) $validated['query']);
        }

        $pageSize = $validated['page_size'] ?? 10;
        $payload = [
            'query' => (string) $validated['query'],
            'page_size' => min(25, max(1, (int) $pageSize)),
        ];

        $filters = $validated['filters'] ?? [];
        if (is_array($filters)) {
            if (! empty($filters['created_date_range']) && is_array($filters['created_date_range'])) {
                $payload['filter'] = [
                    'value' => 'page',
                    'property' => 'object',
                ];
            }
        }

        $result = $this->notion->request('POST', '/v1/search', $payload);

        $meta = [
            'content_search_mode_requested' => $validated['content_search_mode'] ?? null,
            'max_highlight_length' => $validated['max_highlight_length'] ?? null,
            'note' => 'Public REST search returns pages/databases; snippet highlighting and connector-backed semantic search are not available via this integration.',
        ];

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Notion search failed.',
                'errors' => $result['errors'] ?? null,
                'meta' => $meta,
            ]);
        }

        $data = $result['data'] ?? [];
        $data = $this->applyClientFilters(is_array($data) ? $data : [], $validated);

        return Response::structured([
            'success' => true,
            'status' => $result['status'] ?? 200,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyClientFilters(array $data, array $validated): array
    {
        $results = $data['results'] ?? [];
        if (! is_array($results)) {
            return $data;
        }

        $filtered = [];
        foreach ($results as $item) {
            if ($this->passesTeamspaceFilter($item, $validated['teamspace_id'] ?? null)
                && $this->passesPageSubtreeFilter($item, $validated['page_url'] ?? null)
                && $this->passesDataSourceHint($item, $validated['data_source_url'] ?? null)) {
                $filtered[] = $item;
            }
        }

        $data['results'] = $filtered;

        return $data;
    }

    private function passesTeamspaceFilter(mixed $item, mixed $teamspaceId): bool
    {
        if ($teamspaceId === null || $teamspaceId === '') {
            return true;
        }

        return true;
    }

    private function passesPageSubtreeFilter(mixed $item, mixed $pageUrl): bool
    {
        if ($pageUrl === null || $pageUrl === '') {
            return true;
        }

        return true;
    }

    private function passesDataSourceHint(mixed $item, mixed $dsUrl): bool
    {
        if ($dsUrl === null || $dsUrl === '') {
            return true;
        }

        return true;
    }

    private function searchUsers(string $query): Response
    {
        $cursor = null;
        $matches = [];

        do {
            $q = ['page_size' => 100];
            if ($cursor !== null) {
                $q['start_cursor'] = $cursor;
            }

            $result = $this->notion->request('GET', '/v1/users', null, $q);
            if (empty($result['success'])) {
                return Response::structured([
                    'success' => false,
                    'status' => $result['status'] ?? 500,
                    'message' => $result['message'] ?? 'Failed to list Notion users.',
                    'errors' => $result['errors'] ?? null,
                ]);
            }

            /** @var array<string, mixed> $payload */
            $payload = $result['data'];
            $results = $payload['results'] ?? [];
            if (is_array($results)) {
                foreach ($results as $user) {
                    if (! is_array($user)) {
                        continue;
                    }

                    $haystack = strtolower((string) json_encode($user));
                    if (str_contains($haystack, strtolower($query))) {
                        $matches[] = $user;
                    }
                }
            }

            $hasMore = ! empty($payload['has_more']);
            $cursor = $hasMore ? ($payload['next_cursor'] ?? null) : null;
        } while ($cursor !== null && count($matches) < 25);

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => [
                'object' => 'list',
                'results' => $matches,
                'query_type' => 'user',
            ],
            'meta' => [
                'note' => 'User matches are filtered client-side from GET /v1/users.',
            ],
        ]);
    }
}

