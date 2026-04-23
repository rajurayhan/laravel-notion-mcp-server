<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

final class NotionIdentifier
{
    /**
     * Normalize a Notion id or URL into a UUID string (with dashes optional — returned without changing style).
     */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with(strtolower($raw), 'collection://')) {
            $raw = substr($raw, strlen('collection://'));
        }

        if (preg_match('#([0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12})#i', $raw, $m)) {
            return self::withDashes($m[1]);
        }

        return $raw;
    }

    /**
     * Format UUID with dashes if the string is 32 hex chars.
     */
    public static function withDashes(string $id): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-f]/', '', $id) ?? '');
        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            return $id;
        }

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    public static function extractUuidFromUrl(string $url): ?string
    {
        if (preg_match('#([0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $url, $m)) {
            return self::withDashes($m[1]);
        }

        return null;
    }
}

