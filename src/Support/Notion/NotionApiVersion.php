<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

final class NotionApiVersion
{
    public static function current(): string
    {
        return (string) config('notion-mcp.api.version', '2022-06-28');
    }

    public static function supportsDataSources(): bool
    {
        return version_compare(self::normalize(self::current()), '2025-09-03', '>=');
    }

    /**
     * @param  non-empty-string  $ymd
     */
    public static function normalize(string $ymd): string
    {
        return ltrim($ymd, 'v');
    }
}

