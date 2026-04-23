<?php

namespace RajuRayhan\LaravelNotionMcpServer\Server;

use Laravel\Mcp\Server;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionCreateCommentTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionCreateDatabaseTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionCreatePagesTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionCreateViewTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionDuplicatePageTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionFetchTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionGetCommentsTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionGetTeamsTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionGetUsersTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionMovePagesTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionSearchTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionUpdateDataSourceTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionUpdatePageTool;
use RajuRayhan\LaravelNotionMcpServer\Tools\Notion\NotionUpdateViewTool;

class NotionServer extends Server
{
    public string $serverName = 'Notion Server';

    public string $serverVersion = '1.0.0';

    public string $instructions = 'Notion MCP bridge backed by the official REST API (integration token via NOTION_API_TOKEN). Prefer smaller page_size values. Data source endpoints require NOTION_API_VERSION >= 2025-09-03 when noted in tool descriptions.';

    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 100;

    public array $tools = [
        NotionSearchTool::class,
        NotionFetchTool::class,
        NotionCreatePagesTool::class,
        NotionUpdatePageTool::class,
        NotionMovePagesTool::class,
        NotionDuplicatePageTool::class,
        NotionCreateDatabaseTool::class,
        NotionUpdateDataSourceTool::class,
        NotionCreateCommentTool::class,
        NotionGetCommentsTool::class,
        NotionGetTeamsTool::class,
        NotionGetUsersTool::class,
        NotionCreateViewTool::class,
        NotionUpdateViewTool::class,
    ];

    public array $resources = [];

    public array $prompts = [];
}

