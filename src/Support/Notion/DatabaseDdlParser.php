<?php

namespace RajuRayhan\LaravelNotionMcpServer\Support\Notion;

use InvalidArgumentException;

/**
 * Minimal CREATE TABLE (...) parser for Notion database creation.
 * Supports common column types used with the Notion REST API shape.
 */
final class DatabaseDdlParser
{
    /**
     * @return array{properties: array<string, mixed>}
     */
    public static function parse(string $ddl): array
    {
        $ddl = trim($ddl);
        if (! preg_match('/^CREATE\s+TABLE\s*\(/is', $ddl)) {
            throw new InvalidArgumentException('Schema must begin with CREATE TABLE (...)');
        }

        $inner = self::extractInnerColumns($ddl);
        $segments = self::splitColumnSegments($inner);

        $properties = [];
        foreach ($segments as $segment) {
            self::parseColumn(trim($segment), $properties);
        }

        return ['properties' => $properties];
    }

    private static function extractInnerColumns(string $ddl): string
    {
        $first = strpos($ddl, '(');
        if ($first === false) {
            throw new InvalidArgumentException('Missing opening parenthesis in CREATE TABLE.');
        }

        $depth = 1;
        $buf = '';
        $len = strlen($ddl);

        for ($i = $first + 1; $i < $len; $i++) {
            $ch = $ddl[$i];
            if ($ch === '(') {
                $depth++;
                $buf .= '(';

                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $buf .= ')';

                continue;
            }
            $buf .= $ch;
        }

        if (trim($buf) === '') {
            throw new InvalidArgumentException('Empty CREATE TABLE column list.');
        }

        return $buf;
    }

    /**
     * @return list<string>
     */
    private static function splitColumnSegments(string $inner): array
    {
        $segments = [];
        $buf = '';
        $depth = 0;
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($inner);

        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];

            if ($inQuote) {
                $buf .= $ch;
                if ($ch === $quoteChar) {
                    $inQuote = false;
                }

                continue;
            }

            if (($ch === '"' || $ch === "'") && ($i === 0 || $inner[$i - 1] !== '\\')) {
                $inQuote = true;
                $quoteChar = $ch;
                $buf .= $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;
                $buf .= $ch;

                continue;
            }
            if ($ch === ')') {
                $depth--;
                $buf .= $ch;

                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $segments[] = trim($buf);
                $buf = '';

                continue;
            }

            $buf .= $ch;
        }

        if (trim($buf) !== '') {
            $segments[] = trim($buf);
        }

        return array_values(array_filter($segments, static fn ($s) => $s !== ''));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function parseColumn(string $segment, array &$properties): void
    {
        if ($segment === '') {
            return;
        }

        if (! preg_match('/^("([^"]+)"|\'([^\']+)\')\s+(.+)$/s', $segment, $m)) {
            throw new InvalidArgumentException("Cannot parse column definition: {$segment}");
        }

        $name = $m[2] !== '' ? $m[2] : $m[3];
        $rest = trim($m[4]);

        $upper = strtoupper(preg_replace('/\s+/', ' ', $rest) ?? $rest);

        if (str_starts_with($upper, 'TITLE')) {
            $properties[$name] = ['title' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'RICH_TEXT')) {
            $properties[$name] = ['rich_text' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'NUMBER')) {
            $properties[$name] = ['number' => ['format' => self::extractNumberFormat($rest)]];

            return;
        }

        if (str_starts_with($upper, 'CHECKBOX')) {
            $properties[$name] = ['checkbox' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'DATE')) {
            $properties[$name] = ['date' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'URL')) {
            $properties[$name] = ['url' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'EMAIL')) {
            $properties[$name] = ['email' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'PHONE_NUMBER')) {
            $properties[$name] = ['phone_number' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'STATUS')) {
            $properties[$name] = ['status' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'FILES')) {
            $properties[$name] = ['files' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'PEOPLE')) {
            $properties[$name] = ['people' => new \stdClass];

            return;
        }

        if (str_starts_with($upper, 'MULTI_SELECT')) {
            $properties[$name] = ['multi_select' => ['options' => self::parseMultiSelectOptions($rest)]];

            return;
        }

        if (str_starts_with($upper, 'SELECT')) {
            $properties[$name] = ['select' => ['options' => self::parseMultiSelectOptions($rest)]];

            return;
        }

        throw new InvalidArgumentException("Unsupported or complex column type for \"{$name}\": {$rest}");
    }

    /**
     * @return list<array{name: string, color?: string}>
     */
    private static function parseMultiSelectOptions(string $rest): array
    {
        if (! preg_match('/^(MULTI_)?SELECT\s*\(\s*(.*)\)\s*$/is', trim($rest), $m)) {
            return [];
        }

        $inner = $m[2];
        $parts = preg_split('/\s*,\s*(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $inner) ?: [];
        $options = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match("/^'([^']+)'\s*:\s*([a-z_]+)$/i", $part, $opt)) {
                $options[] = ['name' => $opt[1], 'color' => strtolower($opt[2])];

                continue;
            }

            if (preg_match('/^\'([^\']+)\'$/', $part, $opt)) {
                $options[] = ['name' => $opt[1]];

                continue;
            }
        }

        return $options;
    }

    private static function extractNumberFormat(string $rest): string
    {
        if (preg_match('/NUMBER\s*\(\s*([a-z_]+)\s*\)/i', $rest, $m)) {
            return strtolower($m[1]);
        }

        return 'number';
    }
}

