<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Tools;

use Cloudstudio\LaravelCodemode\Support\SpecLoader;
use Cloudstudio\LaravelCodemode\Tools\Concerns\RunsSandbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Explore the API by running JavaScript against the OpenAPI spec.
 *
 * Loads the OpenAPI spec via SpecLoader and executes user-provided
 * JavaScript code in the V8 sandbox with the spec injected as the
 * `spec` variable. Used for discovering endpoints, schemas, and
 * parameters before calling them with ExecuteTool.
 */
class SearchTool extends Tool
{
    use RunsSandbox;

    protected string $name = 'search';

    protected string $description = 'Explore the API by running JavaScript code against the OpenAPI spec. The spec is available as a `spec` variable. Use this to discover endpoints, schemas, and parameters before calling them.';

    /**
     * Define the input schema for the search tool.
     *
     * @param  JsonSchema  $schema  The JSON Schema factory instance.
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string(
                'JavaScript code to explore the OpenAPI spec. The `spec` variable contains the full OpenAPI JSON. Return the value you want to see.',
            )->required(),
        ];
    }

    /**
     * Execute JavaScript code against the OpenAPI spec in the sandbox.
     *
     * @param  Request  $request  The MCP request containing the JavaScript code.
     * @return ResponseFactory
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $spec = SpecLoader::load();

        if ($spec === null) {
            return Response::make(Response::error('Failed to load OpenAPI spec. Check your codemode.spec configuration.'));
        }

        $result = $this->runSandbox($validated['code'], ['spec' => $spec]);

        if (! $result['success']) {
            return Response::make(Response::error('Sandbox error: '.($result['error'] ?? 'Unknown error')));
        }

        return Response::make(Response::text($this->formatOutput($result)));
    }
}
