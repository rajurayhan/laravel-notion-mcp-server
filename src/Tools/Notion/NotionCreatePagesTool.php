<?php

namespace RajuRayhan\LaravelNotionMcpServer\Tools\Notion;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use RajuRayhan\LaravelNotionMcpServer\Services\NotionApiService;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\IconCoverMapper;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionApiVersion;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionParentNormalizer;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionPropertiesCoercer;

#[Title('Notion create pages')]
#[IsOpenWorld]
class NotionCreatePagesTool extends Tool
{
    protected string $name = 'notion-create-pages';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Create one or more Notion pages with a shared parent. `pages[*].properties` may be flat strings; they are coerced when a database or data source parent is used. `content` is turned into top-level paragraph blocks (plain text, not full Notion-flavored markdown).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'parent' => $schema->object([])
                ->description('Parent: {type: page_id|database_id|data_source_id, <id>}.')
                ->required(),
            'pages' => $schema->array()
                ->description('Up to 100 page create payloads: properties, optional content, template_id, icon, cover strings.')
                ->min(1)
                ->max(100)
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $validator = Validator::make($request->all(), [
            'parent' => ['required', 'array'],
            'pages' => ['required', 'array', 'min:1', 'max:100'],
            'pages.*.properties' => ['required', 'array'],
            'pages.*.content' => ['nullable', 'string'],
            'pages.*.template_id' => ['nullable', 'string'],
            'pages.*.icon' => ['nullable', 'string'],
            'pages.*.cover' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $validated = $validator->validated();

        try {
            $parent = NotionParentNormalizer::forCreate($validated['parent']);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $schemaProps = $this->resolveSchemaProperties($parent);

        $created = [];

        foreach ($validated['pages'] as $page) {
            $propsIn = $page['properties'];
            $properties = is_array($schemaProps) && $schemaProps !== []
                ? NotionPropertiesCoercer::coerce($schemaProps, $propsIn)
                : $propsIn;

            $payload = [
                'parent' => $parent,
                'properties' => $properties,
            ];

            if (! empty($page['template_id'])) {
                $payload['template'] = ['template_id' => $page['template_id']];
            }

            if (! empty($page['content']) && empty($page['template_id'])) {
                $payload['children'] = $this->contentToParagraphBlocks((string) $page['content']);
            }

            if (array_key_exists('icon', $page)) {
                $icon = IconCoverMapper::icon($page['icon'] ?? null);
                if (is_array($icon)) {
                    $payload = array_merge($payload, $icon);
                }
            }

            if (array_key_exists('cover', $page)) {
                $cover = IconCoverMapper::cover($page['cover'] ?? null);
                if (is_array($cover)) {
                    $payload = array_merge($payload, $cover);
                }
            }

            $result = $this->notion->request('POST', '/v1/pages', $payload);
            if (empty($result['success'])) {
                return Response::structured([
                    'success' => false,
                    'status' => $result['status'] ?? 500,
                    'message' => $result['message'] ?? 'Failed to create page.',
                    'errors' => $result['errors'] ?? null,
                    'partial' => $created,
                ]);
            }

            $created[] = $result['data'];
        }

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => $created,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $parent
     * @return array<string, mixed>|null
     */
    private function resolveSchemaProperties(?array $parent): ?array
    {
        if ($parent === null) {
            return null;
        }

        $type = $parent['type'] ?? null;

        if ($type === 'database_id' && isset($parent['database_id'])) {
            $id = NotionIdentifier::withDashes((string) $parent['database_id']);
            $db = $this->notion->request('GET', "/v1/databases/{$id}");

            return is_array($db['data']['properties'] ?? null) ? $db['data']['properties'] : null;
        }

        if ($type === 'data_source_id' && isset($parent['data_source_id']) && NotionApiVersion::supportsDataSources()) {
            $id = NotionIdentifier::withDashes((string) $parent['data_source_id']);
            $ds = $this->notion->request('GET', "/v1/data_sources/{$id}");

            return is_array($ds['data']['properties'] ?? null) ? $ds['data']['properties'] : null;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentToParagraphBlocks(string $content): array
    {
        $paragraphs = preg_split("/\n{2,}/", $content) ?: [$content];
        $blocks = [];

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            $blocks[] = [
                'object' => 'block',
                'type' => 'paragraph',
                'paragraph' => [
                    'rich_text' => [[
                        'type' => 'text',
                        'text' => ['content' => mb_substr($para, 0, 1900)],
                    ]],
                ],
            ];
        }

        if ($blocks === []) {
            $blocks[] = [
                'object' => 'block',
                'type' => 'paragraph',
                'paragraph' => ['rich_text' => []],
            ];
        }

        return $blocks;
    }
}

