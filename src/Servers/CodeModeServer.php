<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Servers;

use Cloudstudio\LaravelCodemode\Tools\ExecuteTool;
use Cloudstudio\LaravelCodemode\Tools\SearchTool;
use Laravel\Mcp\Server;

/**
 * MCP server implementing the Code Mode pattern.
 *
 * Provides two tools — search (explore the API spec) and execute (call the API) —
 * along with server instructions that enforce a search-first workflow.
 *
 * The AI must ALWAYS use `search` to discover endpoints before calling them
 * with `execute`. No endpoint list is provided in the instructions — the spec
 * is only accessible through the search tool.
 *
 * Extend this class to customize tools, inject dynamic context into instructions
 * (via boot()), or modify the server name/version.
 *
 * @see \Cloudstudio\LaravelCodemode\Tools\SearchTool
 * @see \Cloudstudio\LaravelCodemode\Tools\ExecuteTool
 */
class CodeModeServer extends Server
{
    protected string $name = 'Code Mode Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
    This MCP server implements the **Code Mode** pattern.

    Instead of one tool per endpoint, you have just 2 tools:

    ## `search` — Explore the API (ALWAYS USE FIRST)
    Write JavaScript code that runs against the OpenAPI spec (`spec` variable).
    **You MUST use search to discover endpoints before calling them.** No endpoint list is provided — the spec is only accessible through this tool.

    ```js
    // List all available endpoints
    Object.entries(spec.paths).map(([p, ops]) => ({ path: p, methods: Object.keys(ops) }))
    ```
    ```js
    // Find endpoints matching a keyword
    Object.entries(spec.paths).filter(([p]) => p.includes('product')).map(([p, ops]) => ({ path: p, methods: Object.keys(ops) }))
    ```
    ```js
    // Get request body schema and parameters for an endpoint
    spec.paths['/products'].post
    ```

    ## `execute` — Call the API (only AFTER search)
    Write JavaScript code using `await api(method, path, data)` to call endpoints.
    **NEVER call execute without first using search to find the correct endpoint path, method, and parameters.**

    **CRITICAL: Always extract only the fields you need. NEVER return raw API responses.**
    Use the `pick(obj, keys)` and `pluck(arr, keys)` helpers to select fields:
    ```js
    const res = await api('GET', '/products');
    // GOOD: select only needed fields
    pluck(res.data, ['id', 'name', 'price'])
    // BAD: res (dumps entire raw response!)
    ```

    ## Workflow
    1. **ALWAYS start with `search`** to discover the endpoint path, HTTP method, and request/response schema
    2. Then use `execute` with the discovered path and field selection to call the API
    3. If the response is unexpected, use `search` again to verify the schema

    ## Sandbox Helpers
    - `pick(obj, keys)` — select keys from an object: `pick(user, ['name', 'email'])`
    - `pluck(arr, keys)` — select keys from every item in an array: `pluck(users, ['id', 'name'])`
    - `console.log()` — debug logging (appears in logs section)
    MARKDOWN;

    protected array $tools = [
        SearchTool::class,
        ExecuteTool::class,
    ];
}
