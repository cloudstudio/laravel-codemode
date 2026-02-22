# Laravel Code Mode

**2 tools instead of 200.** Code Mode is a Laravel package that gives AI assistants full API access through just two MCP tools — `search` to explore your OpenAPI spec and `execute` to call any endpoint.

Instead of registering one MCP tool per API endpoint (which doesn't scale), Code Mode lets the AI write JavaScript code that runs in a secure V8 sandbox. The AI discovers endpoints by querying the OpenAPI spec, then calls them using a simple `api()` function.

## Requirements

- PHP 8.2+
- Laravel 12+
- Node.js 18+ (for the V8 sandbox)
- `laravel/mcp` ^0.5
- An OpenAPI spec (via [Scramble](https://scramble.dedoc.co/), URL, or JSON file)

## Installation

```bash
composer require cloudstudio/laravel-codemode
php artisan codemode:install
```

The install command will:
1. Publish the config file to `config/codemode.php`
2. Copy the sandbox to your project root (`sandbox/`)
3. Run `npm install` in the sandbox directory
4. Publish MCP routes if not present

## Configuration

### API Settings

```php
// config/codemode.php

'api' => [
    'base_url' => env('CODEMODE_API_URL', env('APP_URL')),
    'prefix'   => env('CODEMODE_API_PREFIX', ''),
    'headers'  => [],
],
```

- **base_url**: Where API calls are sent. Defaults to your app URL.
- **prefix**: Auto-prepended to paths. E.g., set to `/api/v1` so the AI can just use `/users` instead of `/api/v1/users`.
- **headers**: Static headers merged into every request (API keys, Accept headers, etc.).

### OpenAPI Spec

```php
'spec' => [
    'source'     => env('CODEMODE_SPEC_SOURCE', 'auto'),
    'url'        => env('CODEMODE_SPEC_URL'),
    'path'       => env('CODEMODE_SPEC_PATH'),
    'cache'      => true,
    'cache_path' => storage_path('app/openapi-spec.json'),
],
```

Sources:
- `scramble` — Generate from [Scramble](https://scramble.dedoc.co/) (requires `dedoc/scramble`)
- `url` — Fetch from a remote URL
- `file` — Read from a local JSON file
- `auto` — Try all sources in order (default)

### Sandbox

```php
'sandbox' => [
    'timeout'     => 5000,       // ms
    'memory'      => 64,         // MB (V8 heap limit)
    'path'        => base_path('sandbox'),
    'node_binary' => env('CODEMODE_NODE_BINARY', 'node'),
],
```

### Excluding HTTP Methods

Hide destructive endpoints from the AI:

```env
CODEMODE_EXCLUDE_METHODS=delete,patch
```

### Debug Mode

```env
CODEMODE_DEBUG=true
CODEMODE_DEBUG_CHANNEL=daily
```

Logs sandbox input/output. Auth headers are automatically redacted.

## How It Works

### Tool 1: `search` — Explore the API

The AI writes JavaScript that runs against your OpenAPI spec (available as the `spec` variable):

```javascript
// Find all product-related endpoints
Object.entries(spec.paths)
  .filter(([p]) => p.includes('product'))
  .map(([p, ops]) => ({ path: p, methods: Object.keys(ops) }))
```

```javascript
// Get the schema for creating a product
spec.paths['/products'].post.requestBody.content['application/json'].schema
```

### Tool 2: `execute` — Call the API

The AI writes JavaScript using the `api(method, path, data)` function:

```javascript
const res = await api('GET', '/products');
pluck(res.data, ['id', 'name', 'price'])
```

```javascript
const product = await api('POST', '/products', {
  name: 'Widget',
  price: 29.99
});
pick(product.data, ['id', 'name'])
```

### Sandbox Helpers

Two helpers keep responses compact (critical for staying within AI context limits):

- **`pick(obj, keys)`** — Select specific keys from an object:
  ```javascript
  pick(user, ['name', 'email'])
  // { name: 'John', email: 'john@example.com' }
  ```

- **`pluck(arr, keys)`** — Select keys from every item in an array:
  ```javascript
  pluck(users, ['id', 'name'])
  // [{ id: 1, name: 'John' }, { id: 2, name: 'Jane' }]
  ```

- **`console.log()`** — Debug output (appears in a separate logs section).

## Extending Code Mode

### Custom Authentication (per-request)

Override `resolveApiContext()` in a custom ExecuteTool to inject dynamic auth:

```php
use Cloudstudio\LaravelCodemode\Tools\ExecuteTool;

class MyExecuteTool extends ExecuteTool
{
    protected function resolveApiContext(): array
    {
        $user = auth()->user();

        return [
            'baseUrl' => 'https://api.example.com',
            'headers' => [
                'Authorization' => 'Bearer ' . $user->api_token,
            ],
            'prefix' => '/v2',
        ];
    }
}
```

### Custom Server (dynamic instructions)

Extend CodeModeServer to inject context or swap tools:

```php
use Cloudstudio\LaravelCodemode\Servers\CodeModeServer;
use Cloudstudio\LaravelCodemode\Tools\SearchTool;

class MyServer extends CodeModeServer
{
    protected string $name = 'My API';

    protected array $tools = [
        SearchTool::class,
        MyExecuteTool::class,
    ];

    protected function boot(): void
    {
        parent::boot();

        $this->instructions .= "\n\n## Your Context";
        $this->instructions .= "\n- User ID: " . auth()->id();
    }
}
```

When using a custom server, disable auto-registration and register your own:

```php
// config/codemode.php
'auto_register' => false,
```

```php
// routes/mcp.php or a service provider
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/my-api', MyServer::class);
Mcp::local('my-api', MyServer::class);
```

### Extension Points Summary

| Extension Point | How | Use Case |
|----------------|-----|----------|
| `ExecuteTool::resolveApiContext()` | Override in subclass | Per-user auth, dynamic base URL |
| `CodeModeServer::boot()` | Override in subclass | Inject user context into instructions |
| `CodeModeServer::$tools` | Set in subclass | Swap tool implementations |
| Config values | `.env` / `config/codemode.php` | Change spec source, sandbox settings |

## Security Notes

- The sandbox runs in a V8 isolate (`isolated-vm`) — it cannot access the filesystem, network (except via the `api()` bridge), or Node.js APIs.
- The `api()` function accepts **arbitrary paths**. If you need to restrict which endpoints the AI can call, implement validation in your `resolveApiContext()` override or use `exclude_methods` in config.
- Debug mode redacts Bearer tokens from logs, but avoid enabling it in production.
- Error responses from your API that contain `exception` keys are automatically sanitized (stack traces stripped).
- Destructive HTTP methods (DELETE, PATCH, PUT) are **not** restricted by default. Use `CODEMODE_EXCLUDE_METHODS` to hide them from the spec, or implement guardrails in your API middleware.

## License

MIT. See [LICENSE](LICENSE) for details.
