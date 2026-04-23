<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

final class IconCoverMapper
{
    /**
     * @return array<string, mixed>|null
     */
    public static function icon(?string $icon): ?array
    {
        if ($icon === null) {
            return null;
        }

        $icon = trim($icon);
        if ($icon === '' || strtolower($icon) === 'none') {
            return ['icon' => null];
        }

        if (preg_match('#^https?://#i', $icon)) {
            return ['icon' => ['type' => 'external', 'external' => ['url' => $icon]]];
        }

        return ['icon' => ['type' => 'emoji', 'emoji' => $icon]];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function cover(?string $cover): ?array
    {
        if ($cover === null) {
            return null;
        }

        $cover = trim($cover);
        if ($cover === '' || strtolower($cover) === 'none') {
            return ['cover' => null];
        }

        if (! preg_match('#^https?://#i', $cover)) {
            return null;
        }

        return ['cover' => ['type' => 'external', 'external' => ['url' => $cover]]];
    }
}

