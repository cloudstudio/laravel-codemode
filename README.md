# Laravel Code Mode

**2 tools instead of 200.** Give any AI assistant full access to your Laravel API through just two MCP tools.

Instead of registering one MCP tool per endpoint (which doesn't scale), Code Mode lets the AI write JavaScript that runs in a secure V8 sandbox. It discovers your API by querying the OpenAPI spec, then calls endpoints with a simple `api()` function — all without the spec ever entering the AI's context window.

```
Traditional MCP          Code Mode
────────────────         ────────────────
GET /users        →      search  (explore spec)
POST /users       →      execute (call any endpoint)
GET /users/{id}   →
PUT /users/{id}   →      That's it. 2 tools.
DELETE /users/{id}→
GET /products     →
POST /products    →
...26 more tools  →
```

## Why Code Mode?

| Metric | Traditional (1 tool per endpoint) | Code Mode |
|--------|-----------------------------------|-----------|
| Tools registered | 26+ (grows with API) | **2** (fixed) |
| Tool calls for a cross-entity report | ~14 sequential calls | **1** execute call |
| OpenAPI spec in AI context | ~31,700 tokens every message | **0 tokens** (lives in sandbox) |
| Can join data across endpoints? | Manually, with many round-trips | **Yes** — write JS that calls multiple endpoints |
| Can paginate automatically? | No (1 page per tool call) | **Yes** — loop inside execute |
| Can do computed fields / aggregations? | No | **Yes** — full JavaScript |

### Real-world example

> "Show me total revenue per product category with the number of orders in each"

**Traditional approach**: 14 sequential tool calls (list products, list orders page by page, return raw data, hope the AI can compute the rest).

**Code Mode**: 1 search + 1 execute:

```javascript
// Single execute call — the AI writes this
const [products, orders] = await Promise.all([
  api('GET', '/products'),
  api('GET', '/orders')
]);

const revenue = {};
for (const o of orders.data) {
  const product = products.data.find(p => p.id === o.product_id);
  const cat = product?.category || 'unknown';
  revenue[cat] = revenue[cat] || { category: cat, revenue: 0, orders: 0 };
  revenue[cat].revenue += o.total;
  revenue[cat].orders++;
}
Object.values(revenue).sort((a, b) => b.revenue - a.revenue)
```

Result:
```json
[
  { "category": "electronics", "revenue": 24531.80, "orders": 87 },
  { "category": "books", "revenue": 12090.50, "orders": 142 },
  { "category": "clothing", "revenue": 8721.30, "orders": 63 }
]
```

See [BENCHMARKS.md](BENCHMARKS.md) for more examples.

## Requirements

- PHP 8.2+
- Laravel 12+
- Node.js 22 (for the V8 sandbox — `isolated-vm` is not yet compatible with Node 24)
- [`laravel/mcp`](https://github.com/laravel/mcp) ^0.5
- An OpenAPI spec source (see [OpenAPI Spec](#openapi-spec) below)

## Installation

### 1. Install the official Laravel MCP package (if you haven't already)

```bash
composer require laravel/mcp
php artisan install:mcp
```

This sets up the MCP server infrastructure. See the [laravel/mcp docs](https://github.com/laravel/mcp) for details.

### 2. Install Code Mode

```bash
composer require cloudstudio/laravel-codemode
php artisan codemode:install
```

The install command will:
1. Publish `config/codemode.php`
2. Copy the sandbox to your project root (`sandbox/`)
3. Run `npm install` inside the sandbox (installs `isolated-vm` and `acorn`)
4. Publish MCP routes if not already present

Add the sandbox dependencies to your `.gitignore`:

```gitignore
/sandbox/node_modules
```

### 3. Set up your OpenAPI spec

Code Mode needs an OpenAPI spec to let the AI discover your endpoints. The easiest way is [Scramble](https://scramble.dedoc.co/) — it auto-generates the spec from your Laravel controllers and form requests:

```bash
composer require dedoc/scramble
```

That's it. Scramble works out of the box with no configuration. Code Mode will auto-detect it.

> **Don't use Scramble?** You can point to any OpenAPI JSON file — see [OpenAPI Spec](#openapi-spec) below.

### 4. Configure your API prefix

If your API routes are prefixed (e.g. `/api/v1/users`), set the prefix so the AI can use short paths like `/users`:

```env
CODEMODE_API_PREFIX=/api
```

### 5. Start the MCP server

```bash
# Local mode (for Claude Code, Cursor, etc.)
php artisan mcp:start codemode

# Or via HTTP (for web-based AI clients)
# The route is auto-registered at /mcp/codemode
```

### 6. Connect your AI client

#### Claude Code / Claude Desktop

Add to your MCP config (`~/.claude/mcp.json` or Claude Desktop settings):

```json
{
  "mcpServers": {
    "codemode": {
      "command": "php",
      "args": ["artisan", "mcp:start", "codemode"],
      "cwd": "/path/to/your/laravel/project"
    }
  }
}
```

#### Cursor

Add to `.cursor/mcp.json` in your project:

```json
{
  "mcpServers": {
    "codemode": {
      "command": "php",
      "args": ["artisan", "mcp:start", "codemode"],
      "cwd": "/path/to/your/laravel/project"
    }
  }
}
```

Now ask your AI anything about your API — it will use `search` and `execute` automatically.

## How It Works

### Tool 1: `search` — Explore the API

The AI writes JavaScript that runs against your full OpenAPI spec (available as the `spec` variable). The spec never enters the AI's context — it lives entirely inside the sandbox.

```javascript
// List all endpoints
Object.entries(spec.paths).map(([p, ops]) => ({ path: p, methods: Object.keys(ops) }))
```

```javascript
// Find product-related endpoints
Object.entries(spec.paths)
  .filter(([p]) => p.includes('product'))
  .map(([p, ops]) => ({ path: p, methods: Object.keys(ops) }))
```

```javascript
// Get the request body schema for creating a product
spec.paths['/products'].post.requestBody.content['application/json'].schema
```

### Tool 2: `execute` — Call the API

The AI writes JavaScript using `await api(method, path, data)`:

```javascript
// Simple GET
const res = await api('GET', '/products');
pluck(res.data, ['id', 'name', 'price'])
```

```javascript
// POST with body
const product = await api('POST', '/products', { name: 'Widget', price: 29.99 });
pick(product.data, ['id', 'name'])
```

```javascript
// Complex: parallel requests + data joining
const [orders, users] = await Promise.all([
  api('GET', '/orders'),
  api('GET', '/users')
]);
const userMap = Object.fromEntries(users.data.map(u => [u.id, u.name]));
orders.data.slice(0, 5).map(o => ({
  order_id: o.id,
  customer: userMap[o.user_id],
  total: o.total,
  status: o.status
}))
```

### Sandbox Helpers

Two helpers keep responses compact (critical for staying within AI context limits):

- **`pick(obj, keys)`** — Select specific keys from an object:
  ```javascript
  pick(user, ['name', 'email'])  // { name: 'John', email: 'john@example.com' }
  ```

- **`pluck(arr, keys)`** — Select keys from every item in an array:
  ```javascript
  pluck(users, ['id', 'name'])  // [{ id: 1, name: 'John' }, { id: 2, name: 'Jane' }]
  ```

- **`console.log()`** — Debug output (appears in a separate logs section).

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

| Key | Description | Default |
|-----|-------------|---------|
| `base_url` | Where API calls are sent | `APP_URL` |
| `prefix` | Auto-prepended to paths (e.g. `/api/v1`) | `''` |
| `headers` | Static headers for every request | `[]` |

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

| Source | How it works | Setup |
|--------|-------------|-------|
| `auto` (default) | Tries Scramble → URL → file | Just install Scramble |
| `scramble` | Generates spec from your controllers at runtime | `composer require dedoc/scramble` |
| `url` | Fetches spec from a remote URL | Set `CODEMODE_SPEC_URL=https://...` |
| `file` | Reads a local JSON file | Set `CODEMODE_SPEC_PATH=docs/openapi.json` |

The spec is resolved (all `$ref` pointers flattened) and cached on first load. To rebuild:

```bash
rm storage/app/openapi-spec.json
```

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
CODEMODE_EXCLUDE_METHODS=delete
```

### Debug Mode

```env
CODEMODE_DEBUG=true
CODEMODE_DEBUG_CHANNEL=daily
```

Logs every sandbox execution (code in, result out). Bearer tokens are automatically redacted.

## Extending Code Mode

### Custom Authentication (per-request)

Override `resolveApiContext()` to inject dynamic auth headers:

```php
use Cloudstudio\LaravelCodemode\Tools\ExecuteTool;

class MyExecuteTool extends ExecuteTool
{
    protected function resolveApiContext(): array
    {
        return [
            'baseUrl' => 'https://api.example.com',
            'headers' => [
                'Authorization' => 'Bearer ' . auth()->user()->api_token,
            ],
            'prefix' => '/v2',
        ];
    }
}
```

### Custom Server

Extend CodeModeServer to inject context, swap tools, or customize instructions:

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
        $this->instructions .= "\n- User: " . auth()->user()?->name;
        $this->instructions .= "\n- Role: " . auth()->user()?->role;
    }
}
```

Then disable auto-registration and register your own:

```php
// config/codemode.php
'auto_register' => false,
```

```php
// AppServiceProvider or routes/mcp.php
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/my-api', MyServer::class);
Mcp::local('my-api', MyServer::class);
```

### Extension Points

| Extension Point | How | Use Case |
|----------------|-----|----------|
| `ExecuteTool::resolveApiContext()` | Override in subclass | Per-user auth, dynamic base URL |
| `CodeModeServer::boot()` | Override in subclass | Inject user context into instructions |
| `CodeModeServer::$tools` | Set in subclass | Swap tool implementations |
| Config values | `.env` / `config/codemode.php` | Spec source, sandbox limits, API prefix |

## Security

- **V8 isolation**: The sandbox uses `isolated-vm` — code cannot access the filesystem, network (except via `api()`), or Node.js APIs.
- **Arbitrary paths**: The `api()` function can call any endpoint. Restrict access via `exclude_methods`, API middleware, or custom `resolveApiContext()` logic.
- **Auth redaction**: Debug logs automatically strip Bearer tokens.
- **Error sanitization**: API responses with `exception` keys have stack traces removed before reaching the AI.
- **No DELETE by default?** Not restricted. Use `CODEMODE_EXCLUDE_METHODS=delete` to hide destructive endpoints from the spec.

## License

MIT. See [LICENSE](LICENSE) for details.
