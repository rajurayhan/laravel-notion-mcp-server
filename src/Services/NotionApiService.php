<?php

namespace RajuRayhan\LaravelNotionMcpServer\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotionApiService
{
    private ?string $baseUrl;

    private ?string $token;

    private string $version;

    private int $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = config('notion-mcp.api.base_url');
        $this->token = config('notion-mcp.api.token');
        $this->version = (string) (config('notion-mcp.api.version') ?? '2022-06-28');
        $this->timeoutSeconds = (int) (config('notion-mcp.api.timeout_seconds') ?? 15);

        if (! $this->baseUrl) {
            Log::warning('Notion API base URL not configured (NOTION_API_BASE_URL).');
        }
        if (! $this->token) {
            Log::warning('Notion API token not configured (NOTION_API_TOKEN).');
        }
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>       $query
     * @return array{success: bool, status: int, data?: mixed, message?: string, errors?: mixed}
     */
    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Notion API is not configured.',
            ];
        }

        $path = '/' . ltrim($path, '/');

        if ($query !== []) {
            $normalized = array_map(
                static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v,
                $query
            );
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($normalized);
        }

        $url = rtrim((string) $this->baseUrl, '/') . $path;

        try {
            $client = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Notion-Version' => $this->version,
                    'Content-Type' => 'application/json',
                ]);

            /** @var Response $response */
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $json ?? []),
                'PUT' => $client->put($url, $json ?? []),
                'PATCH' => $client->patch($url, $json ?? []),
                'DELETE' => $client->delete($url),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'data' => $response->json(),
                ];
            }

            $message = $this->extractErrorMessage($response);

            Log::warning('Notion API request failed', [
                'method' => $method,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'message' => $message,
                'errors' => $this->extractErrorDetails($response),
            ];
        } catch (\Throwable $e) {
            Log::error('Notion API request exception', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function extractErrorMessage(Response $response): string
    {
        $decoded = $response->json();
        if (is_array($decoded)) {
            foreach (['message', 'error', 'error_message'] as $key) {
                if (! empty($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }
        }

        $body = $response->body();

        return ! empty($body) && strlen($body) <= 250 ? $body : 'Notion API returned an error.';
    }

    private function extractErrorDetails(Response $response): mixed
    {
        $decoded = $response->json();

        return $decoded ?: $response->body();
    }
}

