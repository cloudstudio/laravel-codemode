<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Tools;

use Cloudstudio\LaravelCodemode\Tools\Concerns\RunsSandbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Execute JavaScript code that calls the API via the sandbox.
 *
 * Runs user-provided JavaScript in the V8 sandbox with an `api(method, path, data)`
 * function available for making HTTP requests. The API context (base URL, headers,
 * prefix) is resolved via resolveApiContext(), which can be overridden in subclasses
 * to provide per-request authentication or dynamic configuration.
 */
class ExecuteTool extends Tool
{
    use RunsSandbox;

    protected string $name = 'execute';

    protected string $description = 'Execute JavaScript code that calls the API. Use `await api(method, path, data)` for HTTP requests. IMPORTANT: Always use pick()/pluck() to select only the fields you need — never return raw API responses.';

    /**
     * Define the input schema for the execute tool.
     *
     * @param  JsonSchema  $schema  The JSON Schema factory instance.
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string(
                'JavaScript code to execute. Use `await api(method, path, data)` to call API endpoints. The `api` function accepts method (GET/POST/PUT/PATCH/DELETE), path (e.g. "/products"), and optional data object. Return the value you want to see.',
            )->required(),
        ];
    }

    /**
     * Execute JavaScript code with API access in the sandbox.
     *
     * @param  Request  $request  The MCP request containing the JavaScript code.
     * @return ResponseFactory
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $context = $this->resolveApiContext();

        $result = $this->runSandbox(
            code: $validated['code'],
            apiBaseUrl: $context['baseUrl'] ?? null,
            headers: $context['headers'] ?? [],
            apiPrefix: $context['prefix'] ?? null,
        );

        if (! $result['success']) {
            return Response::make(Response::error('Execution error: '.($result['error'] ?? 'Unknown error')));
        }

        return Response::make(Response::text($this->formatOutput($result)));
    }

    /**
     * Resolve the API context for this request.
     *
     * Override this method in subclasses to provide dynamic base URLs,
     * authentication headers, or custom prefixes per-request.
     *
     * @return array{baseUrl: string|null, headers: array<string, string>, prefix: string|null}
     */
    protected function resolveApiContext(): array
    {
        return [
            'baseUrl' => config('codemode.api.base_url', config('app.url')),
            'headers' => config('codemode.api.headers', []),
            'prefix' => config('codemode.api.prefix', ''),
        ];
    }
}
