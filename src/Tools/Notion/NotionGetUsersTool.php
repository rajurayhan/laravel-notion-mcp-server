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

#[Title('Notion get users')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class NotionGetUsersTool extends Tool
{
    protected string $name = 'notion-get-users';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Lists workspace users visible to the integration token, with optional substring filtering and pagination.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->nullable(),
            'start_cursor' => $schema->string()->nullable(),
            'page_size' => $schema->integer()->min(1)->max(100)->nullable(),
            'user_id' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'query' => ['nullable', 'string', 'min:1', 'max:100'],
            'start_cursor' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'user_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $data = $validator->validated();

        if (($data['user_id'] ?? '') === 'self') {
            return $this->wrap($this->notion->request('GET', '/v1/users/me'));
        }

        if (! empty($data['user_id'])) {
            return $this->wrap($this->notion->request('GET', '/v1/users/' . $data['user_id']));
        }

        $query = [
            'page_size' => $data['page_size'] ?? 100,
        ];

        if (! empty($data['start_cursor'])) {
            $query['start_cursor'] = $data['start_cursor'];
        }

        $result = $this->notion->request('GET', '/v1/users', null, $query);

        if (empty($result['success'])) {
            return $this->wrap($result);
        }

        if (! empty($data['query'])) {
            /** @var array<string, mixed> $payload */
            $payload = $result['data'];
            $needle = strtolower($data['query']);
            $filtered = [];

            foreach ($payload['results'] ?? [] as $user) {
                if (! is_array($user)) {
                    continue;
                }

                $haystack = strtolower((string) json_encode($user));
                if (str_contains($haystack, $needle)) {
                    $filtered[] = $user;
                }
            }

            $payload['results'] = $filtered;

            return Response::structured([
                'success' => true,
                'status' => 200,
                'data' => $payload,
                'meta' => ['filtered' => true],
            ]);
        }

        return $this->wrap($result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function wrap(array $result): Response
    {
        if (! empty($result['success'])) {
            return Response::structured([
                'success' => true,
                'status' => $result['status'] ?? 200,
                'data' => $result['data'],
            ]);
        }

        return Response::structured([
            'success' => false,
            'status' => $result['status'] ?? 500,
            'message' => $result['message'] ?? 'Notion users request failed.',
            'errors' => $result['errors'] ?? null,
        ]);
    }
}

