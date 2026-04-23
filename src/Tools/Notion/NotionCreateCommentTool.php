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
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;

#[Title('Notion create comment')]
#[IsOpenWorld]
class NotionCreateCommentTool extends Tool
{
    protected string $name = 'notion-create-comment';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Creates a comment anchored to a page (optionally referencing a discussion id or textual selection anchor metadata). Rich text payloads follow the Notion comment API.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->required(),
            'rich_text' => $schema->array()->description('Notion rich_text objects array.')->required(),
            'selection_with_ellipsis' => $schema->string()->nullable(),
            'discussion_id' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'page_id' => ['required', 'string'],
            'rich_text' => ['required', 'array'],
            'selection_with_ellipsis' => ['nullable', 'string'],
            'discussion_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $data = $validator->validated();
        $pageId = NotionIdentifier::withDashes(NotionIdentifier::normalize($data['page_id']));

        $richText = $this->normalizeRichText($data['rich_text']);

        $payload = [
            'parent' => [
                'page_id' => $pageId,
            ],
            'rich_text' => $richText,
        ];

        if (! empty($data['discussion_id'])) {
            $payload['discussion_id'] = $data['discussion_id'];
        }

        $result = $this->notion->request('POST', '/v1/comments', $payload);

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Failed to create comment.',
                'errors' => $result['errors'] ?? null,
                'meta' => [
                    'selection_with_ellipsis' => $data['selection_with_ellipsis'] ?? null,
                    'note' => 'selection_with_ellipsis is recorded for UI clients; Notion REST may ignore it unless supported.',
                ],
            ]);
        }

        return Response::structured([
            'success' => true,
            'status' => $result['status'] ?? 200,
            'data' => $result['data'],
            'meta' => [
                'selection_with_ellipsis' => $data['selection_with_ellipsis'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<int, mixed>  $parts
     * @return array<int, mixed>
     */
    private function normalizeRichText(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (isset($part['text']['content'])) {
                $out[] = [
                    'type' => 'text',
                    'text' => $part['text'],
                ];

                continue;
            }

            $out[] = $part;
        }

        return $out;
    }
}

