<?php

return [
    'route_prefix' => env('NOTION_MCP_ROUTE_PREFIX', 'mcp/notion'),
    'route_middleware' => ['web'],
    'register_local' => env('NOTION_MCP_REGISTER_LOCAL', false),
    'local_handle' => env('NOTION_MCP_LOCAL_HANDLE', 'notion'),

    'api' => [
        'base_url' => env('NOTION_API_BASE_URL', 'https://api.notion.com'),
        'token' => env('NOTION_API_TOKEN'),
        'version' => env('NOTION_API_VERSION', '2022-06-28'),
        'timeout_seconds' => env('NOTION_API_TIMEOUT_SECONDS', 15),
    ],
];

