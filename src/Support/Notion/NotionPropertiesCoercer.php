<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

/**
 * Coerces simplified string values into Notion API property payloads using a schema map
 * from GET /v1/databases/{id} or GET /v1/data_sources/{id}.
 *
 * @param  array<string, mixed>  $schemaProperties  Notion "properties" object from API
 * @param  array<string, mixed>  $flat  Human-entered map (often strings)
 * @return array<string, mixed>
 */
final class NotionPropertiesCoercer
{
    public static function coerce(array $schemaProperties, array $flat): array
    {
        $out = [];

        foreach ($flat as $name => $value) {
            if (! isset($schemaProperties[$name])) {
                continue;
            }

            $def = $schemaProperties[$name];
            if (! is_array($def) || ! isset($def['type'])) {
                continue;
            }

            $type = $def['type'];
            $out[$name] = self::coerceValue($type, $value);
        }

        return $out;
    }

    private static function coerceValue(string $type, mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        $str = (string) $value;

        return match ($type) {
            'title' => [
                'title' => [[
                    'type' => 'text',
                    'text' => ['content' => $str],
                ]],
            ],
            'rich_text' => [
                'rich_text' => [[
                    'type' => 'text',
                    'text' => ['content' => $str],
                ]],
            ],
            'number' => [
                'number' => is_numeric($str) ? 0 + $str : null,
            ],
            'url' => ['url' => $str],
            'email' => ['email' => $str],
            'phone_number' => ['phone_number' => $str],
            'checkbox' => [
                'checkbox' => in_array(strtolower($str), ['1', 'true', 'yes', '__yes__'], true),
            ],
            'date' => [
                'date' => ['start' => $str],
            ],
            'select' => ['select' => ['name' => $str]],
            'status' => ['status' => ['name' => $str]],
            default => [
                'rich_text' => [[
                    'type' => 'text',
                    'text' => ['content' => $str],
                ]],
            ],
        };
    }
}

