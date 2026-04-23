<?php

namespace RajuRayhan\LaravelNotionMcpServer;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use RajuRayhan\LaravelNotionMcpServer\Server\NotionServer;

class NotionMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/notion-mcp.php', 'notion-mcp');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/notion-mcp.php' => config_path('notion-mcp.php'),
        ], 'notion-mcp-config');

        $prefix = (string) config('notion-mcp.route_prefix', 'mcp/notion');
        $middleware = (array) config('notion-mcp.route_middleware', ['web']);

        Mcp::web($prefix, NotionServer::class)->middleware($middleware);

        if (config('notion-mcp.register_local', false)) {
            Mcp::local((string) config('notion-mcp.local_handle', 'notion'), NotionServer::class);
        }
    }
}

