<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Route & Handle
    |--------------------------------------------------------------------------
    */

    'route' => '/mcp/codemode',

    'handle' => 'codemode',

    /*
    |--------------------------------------------------------------------------
    | Auto-Register
    |--------------------------------------------------------------------------
    |
    | When true, the ServiceProvider automatically registers the default
    | CodeModeServer at the configured route and handle. Set to false
    | when you extend the server and register routes yourself.
    |
    */

    'auto_register' => env('CODEMODE_AUTO_REGISTER', true),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | base_url: The base URL for API calls made by the `execute` tool.
    | prefix:   Auto-prefix for paths. If set, paths that don't already
    |           start with this prefix get it prepended. Set to empty
    |           string or null to disable auto-prefixing.
    | headers:  Static headers merged into every API request. Useful for
    |           API keys, Accept headers, etc. Can be overridden per-request
    |           by extending ExecuteTool and overriding resolveApiContext().
    |
    */

    'api' => [
        'base_url' => env('CODEMODE_API_URL', env('APP_URL')),
        'prefix' => env('CODEMODE_API_PREFIX', ''),
        'headers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | The sandbox is a Node.js V8 isolate that executes JavaScript code
    | in a secure environment. The path should point to the published
    | sandbox directory (created by `php artisan codemode:install`).
    |
    | timeout:     Max execution time in milliseconds.
    | memory:      V8 heap limit in MB.
    | path:        Directory containing sandbox-runner.mjs and node_modules.
    | node_binary: Path to the Node.js binary. Defaults to `node` on PATH.
    |              Requires Node.js 22 (isolated-vm is not yet compatible with 24).
    |
    */

    'sandbox' => [
        'timeout' => 5000,
        'memory' => 64,
        'path' => base_path('sandbox'),
        'node_binary' => env('CODEMODE_NODE_BINARY', 'node'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    |
    | When enabled, logs every sandbox execution (code in, result out)
    | to the Laravel log using the configured channel and level.
    |
    */

    'debug' => env('CODEMODE_DEBUG', false),

    'debug_channel' => env('CODEMODE_DEBUG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Excluded HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of HTTP methods to exclude from the OpenAPI spec.
    | Operations using these methods will be stripped before caching.
    | Set to empty string to include all methods.
    |
    */

    'exclude_methods' => env('CODEMODE_EXCLUDE_METHODS', ''),

    /*
    |--------------------------------------------------------------------------
    | Local / CLI Mode
    |--------------------------------------------------------------------------
    |
    | When running as a local MCP server (via `php artisan mcp:start`),
    | there is no authenticated HTTP user. These values provide fallback
    | credentials for subclasses that override resolveApiContext().
    |
    */

    'local' => [
        'api_url' => env('MCP_CODEMODE_API_URL'),
        'api_token' => env('MCP_CODEMODE_TOKEN'),
        'employee_id' => env('MCP_CODEMODE_EMPLOYEE_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Spec
    |--------------------------------------------------------------------------
    |
    | Source: how to load the spec.
    |   - "scramble"  → generate from Scramble (requires dedoc/scramble)
    |   - "url"       → fetch from a remote URL (set spec.url)
    |   - "file"      → read from a local JSON file (set spec.path)
    |   - "auto"      → try scramble first, then url, then file (default)
    |
    */

    'spec' => [
        'source' => env('CODEMODE_SPEC_SOURCE', 'auto'),
        'url' => env('CODEMODE_SPEC_URL'),
        'path' => env('CODEMODE_SPEC_PATH'),
        'cache' => true,
        'cache_path' => storage_path('app/openapi-spec.json'),
    ],

];
