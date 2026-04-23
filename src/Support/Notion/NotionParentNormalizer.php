<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

use InvalidArgumentException;

final class NotionParentNormalizer
{
    /**
     * Map UI-style parent objects into Notion REST `parent` payloads for page create.
     *
     * @param  array<string, mixed>|null  $parent
     * @return array<string, mixed>|null
     */
    public static function forCreate(?array $parent): ?array
    {
        if ($parent === null || $parent === []) {
            return null;
        }

        $type = $parent['type'] ?? null;

        if ($type === 'page_id' && isset($parent['page_id'])) {
            return [
                'type' => 'page_id',
                'page_id' => NotionIdentifier::withDashes((string) $parent['page_id']),
            ];
        }

        if ($type === 'database_id' && isset($parent['database_id'])) {
            return [
                'type' => 'database_id',
                'database_id' => NotionIdentifier::withDashes((string) $parent['database_id']),
            ];
        }

        if ($type === 'data_source_id' && isset($parent['data_source_id'])) {
            if (! NotionApiVersion::supportsDataSources()) {
                throw new InvalidArgumentException('Creating pages under a data_source_id requires NOTION_API_VERSION >= 2025-09-03.');
            }

            return [
                'type' => 'data_source_id',
                'data_source_id' => NotionIdentifier::withDashes((string) $parent['data_source_id']),
            ];
        }

        throw new InvalidArgumentException('Invalid parent object; expected type page_id, database_id, or data_source_id.');
    }
}

