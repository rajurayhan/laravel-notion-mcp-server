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
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\IconCoverMapper;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionIdentifier;
use RajuRayhan\LaravelNotionMcpServer\Support\Notion\NotionPropertiesCoercer;

#[Title('Notion update page')]
#[IsOpenWorld]
class NotionUpdatePageTool extends Tool
{
    protected string $name = 'notion-update-page';

    public function __construct(private readonly NotionApiService $notion) {}

    public function description(): string
    {
        return 'Update Notion page properties, archive/replace primary content blocks, apply limited search-and-replace over paragraph text, set icon/cover strings, or attempt template application when supported by the workspace token.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('Page UUID or URL fragment.')->required(),
            'command' => $schema->string()
                ->enum(['update_properties', 'update_content', 'replace_content', 'apply_template', 'update_verification'])
                ->required(),
            'properties' => $schema->object()->description('Property payload for update_properties (Notion API shape or strings coerced like create).')->nullable(),
            'content_updates' => $schema->array()->description('Pairs of old_str/new_str for update_content.')->nullable(),
            'new_str' => $schema->string()->description('Full replacement body for replace_content (plain text → paragraph blocks).')->nullable(),
            'allow_deleting_content' => $schema->boolean()->nullable(),
            'template_id' => $schema->string()->nullable(),
            'verification_status' => $schema->string()->enum(['verified', 'unverified'])->nullable(),
            'verification_expiry_days' => $schema->integer()->nullable(),
            'icon' => $schema->string()->nullable(),
            'cover' => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $args = $request->all();

        $validator = Validator::make($args, [
            'page_id' => ['required', 'string'],
            'command' => ['required', 'string', 'in:update_properties,update_content,replace_content,apply_template,update_verification'],
            'properties' => ['nullable', 'array'],
            'content_updates' => ['nullable', 'array'],
            'content_updates.*.old_str' => ['required_with:content_updates', 'string'],
            'content_updates.*.new_str' => ['required_with:content_updates', 'string'],
            'content_updates.*.replace_all_matches' => ['nullable', 'boolean'],
            'new_str' => ['nullable', 'string'],
            'allow_deleting_content' => ['nullable', 'boolean'],
            'template_id' => ['nullable', 'string'],
            'verification_status' => ['nullable', 'string', 'in:verified,unverified'],
            'verification_expiry_days' => ['nullable', 'integer', 'min:1'],
            'icon' => ['nullable', 'string'],
            'cover' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return Response::error($validator->errors()->first());
        }

        $data = $validator->validated();
        $pageId = NotionIdentifier::withDashes(NotionIdentifier::normalize($data['page_id']));
        $command = $data['command'];

        $patchExtras = [];
        if (array_key_exists('icon', $data)) {
            $icon = IconCoverMapper::icon($data['icon'] ?? null);
            if (is_array($icon)) {
                $patchExtras = array_merge($patchExtras, $icon);
            }
        }
        if (array_key_exists('cover', $data)) {
            $cover = IconCoverMapper::cover($data['cover'] ?? null);
            if (is_array($cover)) {
                $patchExtras = array_merge($patchExtras, $cover);
            }
        }

        return match ($command) {
            'update_properties' => $this->updateProperties($pageId, $data, $patchExtras),
            'replace_content' => $this->replaceContent($pageId, $data, $patchExtras),
            'update_content' => $this->updateContent($pageId, $data, $patchExtras),
            'apply_template' => $this->applyTemplate($pageId, $data, $patchExtras),
            'update_verification' => $this->updateVerification($pageId, $data, $patchExtras),
            default => Response::error('Unsupported command.'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $patchExtras
     */
    private function updateProperties(string $pageId, array $data, array $patchExtras): Response
    {
        $props = $data['properties'] ?? null;
        if (! is_array($props)) {
            return Response::error('properties is required for update_properties.');
        }

        $page = $this->notion->request('GET', "/v1/pages/{$pageId}");
        $schemaProps = null;
        if (! empty($page['success']) && isset($page['data']['parent']['database_id'])) {
            $dbId = $page['data']['parent']['database_id'];
            $db = $this->notion->request('GET', "/v1/databases/{$dbId}");
            $schemaProps = $db['data']['properties'] ?? null;
        }

        $properties = is_array($schemaProps)
            ? NotionPropertiesCoercer::coerce($schemaProps, $props)
            : $props;

        $payload = array_merge(['properties' => $properties], $patchExtras);

        return $this->patchPage($pageId, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $patchExtras
     */
    private function replaceContent(string $pageId, array $data, array $patchExtras): Response
    {
        $newStr = $data['new_str'] ?? '';
        if ($newStr === '') {
            return Response::error('new_str is required for replace_content.');
        }

        $archive = $this->archiveDirectChildren($pageId);
        if (! $archive['success']) {
            return Response::structured([
                'success' => false,
                'status' => $archive['status'] ?? 500,
                'message' => $archive['message'] ?? 'Failed to archive existing blocks.',
            ]);
        }

        $children = $this->contentToParagraphBlocks($newStr);
        $append = $this->notion->request('PATCH', "/v1/blocks/{$pageId}/children", ['children' => $children]);

        if (empty($append['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $append['status'] ?? 500,
                'message' => $append['message'] ?? 'Failed to append new content.',
                'errors' => $append['errors'] ?? null,
            ]);
        }

        $responseData = [
            'blocks' => $append['data'],
            'note' => 'replace_content archives previous top-level blocks then appends plain paragraphs derived from new_str.',
        ];

        if ($patchExtras !== []) {
            $extras = $this->notion->request('PATCH', "/v1/pages/{$pageId}", $patchExtras);
            $responseData['page_patch'] = $extras['success'] ? $extras['data'] : $extras;

            return Response::structured([
                'success' => true,
                'status' => 200,
                'data' => $responseData,
            ]);
        }

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => $responseData,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $patchExtras
     */
    private function updateContent(string $pageId, array $data, array $patchExtras): Response
    {
        $updates = $data['content_updates'] ?? [];
        if (! is_array($updates) || $updates === []) {
            return Response::error('content_updates is required for update_content.');
        }

        $children = $this->notion->request('GET', "/v1/blocks/{$pageId}/children", null, ['page_size' => 100]);
        if (empty($children['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $children['status'] ?? 500,
                'message' => $children['message'] ?? 'Failed to read page blocks.',
            ]);
        }

        /** @var array<int, mixed> $results */
        $results = $children['data']['results'] ?? [];
        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            $old = (string) ($update['old_str'] ?? '');
            $new = (string) ($update['new_str'] ?? '');
            $replaceAll = ! empty($update['replace_all_matches']);

            foreach ($results as $block) {
                if (! is_array($block) || ($block['type'] ?? '') !== 'paragraph') {
                    continue;
                }

                $rt = $block['paragraph']['rich_text'] ?? null;
                if (! is_array($rt)) {
                    continue;
                }

                $plain = '';
                foreach ($rt as $part) {
                    if (is_array($part) && isset($part['plain_text'])) {
                        $plain .= (string) $part['plain_text'];
                    }
                }

                if ($old === '' || ! str_contains($plain, $old)) {
                    continue;
                }

                $updated = $replaceAll ? str_replace($old, $new, $plain) : preg_replace('/' . preg_quote($old, '/') . '/', $new, $plain, 1);
                if (! is_string($updated)) {
                    continue;
                }

                $patch = [
                    'paragraph' => [
                        'rich_text' => [[
                            'type' => 'text',
                            'text' => ['content' => mb_substr($updated, 0, 1900)],
                        ]],
                    ],
                ];

                $blockId = $block['id'] ?? null;
                if (is_string($blockId)) {
                    $this->notion->request('PATCH', "/v1/blocks/{$blockId}", $patch);
                }
            }
        }

        $out = [
            'note' => 'update_content runs a best-effort search/replace across the first page_size=100 paragraph blocks.',
        ];

        if ($patchExtras !== []) {
            $extras = $this->notion->request('PATCH', "/v1/pages/{$pageId}", $patchExtras);
            $out['page_patch'] = $extras['success'] ? $extras['data'] : $extras;
        }

        return Response::structured([
            'success' => true,
            'status' => 200,
            'data' => $out,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $patchExtras
     */
    private function applyTemplate(string $pageId, array $data, array $patchExtras): Response
    {
        $templateId = $data['template_id'] ?? '';
        if ($templateId === '') {
            return Response::error('template_id is required for apply_template.');
        }

        $payload = array_merge([
            'template' => ['template_id' => $templateId],
        ], $patchExtras);

        return $this->patchPage($pageId, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $patchExtras
     */
    private function updateVerification(string $pageId, array $data, array $patchExtras): Response
    {
        $status = $data['verification_status'] ?? null;
        if (! is_string($status) || $status === '') {
            return Response::error('verification_status is required for update_verification.');
        }

        $payload = [
            'verification' => [
                'state' => $status,
            ],
        ];

        if (isset($data['verification_expiry_days'])) {
            $payload['verification']['expiry_days'] = (int) $data['verification_expiry_days'];
        }

        $payload = array_merge($payload, $patchExtras);

        return $this->patchPage($pageId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patchPage(string $pageId, array $payload): Response
    {
        $result = $this->notion->request('PATCH', "/v1/pages/{$pageId}", $payload);

        if (empty($result['success'])) {
            return Response::structured([
                'success' => false,
                'status' => $result['status'] ?? 500,
                'message' => $result['message'] ?? 'Failed to patch page.',
                'errors' => $result['errors'] ?? null,
            ]);
        }

        return Response::structured([
            'success' => true,
            'status' => $result['status'] ?? 200,
            'data' => $result['data'],
        ]);
    }

    /**
     * @return array{success: bool, status?: int, message?: string}
     */
    private function archiveDirectChildren(string $pageId): array
    {
        $children = $this->notion->request('GET', "/v1/blocks/{$pageId}/children", null, ['page_size' => 100]);
        if (empty($children['success'])) {
            return ['success' => false, 'status' => $children['status'] ?? 500, 'message' => $children['message'] ?? 'Failed to list blocks for archiving.'];
        }

        $results = $children['data']['results'] ?? [];
        if (! is_array($results)) {
            return ['success' => true];
        }

        foreach ($results as $block) {
            if (! is_array($block) || ! isset($block['id'])) {
                continue;
            }
            $id = (string) $block['id'];
            $this->notion->request('PATCH', "/v1/blocks/{$id}", ['archived' => true]);
        }

        return ['success' => true];
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

