# Laravel Notion MCP Server

A Laravel package that exposes the Notion REST API as Model Context Protocol (MCP) tools — making common Notion actions available to AI agents and coding assistants.

Built on top of `laravel/mcp` for the MCP server infrastructure.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- `laravel/mcp` >= 0.1.0

## Installation

### 1. Require the package

```bash
composer require rajurayhan/laravel-notion-mcp-server
```

If you're developing locally, add it as a path repository:

```json
// composer.json (host app)
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-notion-mcp-server"
    }
  ]
}
```

### 2. Publish the config

```bash
php artisan vendor:publish --tag=notion-mcp-config
```

### 3. Set environment variables

```env
# Notion API connection (required)
NOTION_API_TOKEN=your_notion_integration_token

# API base URL (optional)
NOTION_API_BASE_URL=https://api.notion.com

# Notion API version (optional; use >= 2025-09-03 for data_sources endpoints)
NOTION_API_VERSION=2022-06-28

# MCP route (optional, defaults shown)
NOTION_MCP_ROUTE_PREFIX=mcp/notion

# Optional: register a local MCP handle for `php artisan mcp:start`
NOTION_MCP_REGISTER_LOCAL=false
NOTION_MCP_LOCAL_HANDLE=notion
```

The MCP server will be available at `https://yourapp.com/mcp/notion`.

## Configuration

```php
// config/notion-mcp.php
return [
    'route_prefix'     => env('NOTION_MCP_ROUTE_PREFIX', 'mcp/notion'),
    'route_middleware' => ['web'], // add 'auth:sanctum' to restrict access
    'register_local'   => env('NOTION_MCP_REGISTER_LOCAL', false),
    'local_handle'     => env('NOTION_MCP_LOCAL_HANDLE', 'notion'),

    'api' => [
        'base_url'        => env('NOTION_API_BASE_URL', 'https://api.notion.com'),
        'token'           => env('NOTION_API_TOKEN'),
        'version'         => env('NOTION_API_VERSION', '2022-06-28'),
        'timeout_seconds' => env('NOTION_API_TIMEOUT_SECONDS', 15),
    ],
];
```

## Tools Reference

This package registers **14 tools**:

- `notion-search`
- `notion-fetch`
- `notion-create-pages`
- `notion-update-page`
- `notion-move-pages`
- `notion-duplicate-page`
- `notion-create-database`
- `notion-update-data-source` (requires `NOTION_API_VERSION` >= `2025-09-03`)
- `notion-create-comment`
- `notion-get-comments`
- `notion-get-teams`
- `notion-get-users`
- `notion-create-view`
- `notion-update-view`

## How it’s registered

The service provider auto-registers an MCP endpoint using:

- **route**: `NOTION_MCP_ROUTE_PREFIX` (default `mcp/notion`)
- **middleware**: `notion-mcp.route_middleware` (default `['web']`)

No changes are required in your host app’s `routes/*.php`.

## Middleware / Authentication

By default, the route uses the `web` middleware group. To restrict MCP access, set:

```php
// config/notion-mcp.php
'route_middleware' => ['web', 'auth:sanctum'],
```

## Local (stdio) server (optional)

If you set `NOTION_MCP_REGISTER_LOCAL=true`, you can run:

```bash
php artisan mcp:start notion
```

## Notes

- Notion’s MCP endpoint is **POST-only** by design (`laravel/mcp`); browsers hitting `GET /mcp/notion` will receive **405**.
- Prefer smaller `page_size` values for Notion list/search endpoints.
- For data source tools, set `NOTION_API_VERSION` to **`2025-09-03`** or newer.

## Troubleshooting

- **401 / "Notion API is not configured."**: ensure `NOTION_API_TOKEN` is set and the integration is shared with the target pages/databases.
- **403**: the integration token lacks access to that page/database (share it in Notion).
- **400 on data_sources**: you likely need `NOTION_API_VERSION=2025-09-03` (or newer).

## License

MIT — see `LICENSE`.

