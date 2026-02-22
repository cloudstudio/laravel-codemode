# Debug Panel

A built-in web-based debug panel for inspecting Code Mode executions in real time.

## Overview

The debug panel provides a visual timeline of every `search` and `execute` tool call, showing the JavaScript code the AI wrote, the result or error, execution time, and console logs — all in a dark-themed, auto-refreshing web UI.

```
┌─────────────────────────────────────────────────────────────┐
│  CodeMode Debug Panel          All(12) Search(4) Execute(8) │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ▸ EXECUTE ✓  pluck(res.data, ['id', 'name'])    142ms     │
│  ▸ EXECUTE ✗  const stats = await api('G...       38ms     │
│  ▸ SEARCH  ✓  Object.entries(spec.paths)...       12ms     │
│  ▸ EXECUTE ✓  const [orders, users] = ...        287ms     │
│  ...                                                        │
│                                                             │
│  ▾ EXECUTE ✓  pick(pikachu, ['id', 'name'])      186ms     │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ API: https://pokeapi.co                             │    │
│  │                                                     │    │
│  │ CODE INPUT                                          │    │
│  │ ┌─────────────────────────────────────────────────┐ │    │
│  │ │ const pikachu = await api('GET',                │ │    │
│  │ │   '/api/v2/pokemon/pikachu');                   │ │    │
│  │ │ pick(pikachu, ['id', 'name', 'height'])         │ │    │
│  │ └─────────────────────────────────────────────────┘ │    │
│  │                                                     │    │
│  │ RESULT                                              │    │
│  │ ┌─────────────────────────────────────────────────┐ │    │
│  │ │ { "id": 25, "name": "pikachu", "height": 4 }   │ │    │
│  │ └─────────────────────────────────────────────────┘ │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Enabling

Add to your `.env`:

```env
CODEMODE_DEBUG_PANEL=true
```

The panel requires **both** `CODEMODE_DEBUG_PANEL=true` and `APP_DEBUG=true`. If either is `false`, the panel route does not exist — it cannot be accessed or probed.

Then visit:

```
http://your-app.test/codemode/debug
```

## Configuration

```php
// config/codemode.php

'debug_panel' => [
    'enabled'      => env('CODEMODE_DEBUG_PANEL', false),
    'route'        => env('CODEMODE_DEBUG_PANEL_ROUTE', '/codemode/debug'),
    'storage_path' => storage_path('app/codemode-debug.json'),
    'max_entries'   => (int) env('CODEMODE_DEBUG_PANEL_MAX_ENTRIES', 100),
],
```

| Key | Description | Default |
|-----|-------------|---------|
| `enabled` | Master toggle | `false` |
| `route` | URL path for the panel | `/codemode/debug` |
| `storage_path` | JSON file for execution history | `storage/app/codemode-debug.json` |
| `max_entries` | Max stored entries (oldest pruned) | `100` |

## What's Captured

Each tool execution records:

| Field | Description |
|-------|-------------|
| `tool` | `search` or `execute` |
| `timestamp` | ISO 8601 |
| `code` | The JavaScript code the AI wrote |
| `success` | Whether execution succeeded |
| `result` | The return value (JSON) |
| `error` | Error message if failed |
| `duration_ms` | Execution time in milliseconds |
| `logs` | `console.log()` output from the sandbox |
| `api_base_url` | Base URL used for `api()` calls |

## Panel Features

- **Timeline view**: All executions ordered by most recent, with tool badge, status icon, code preview, and duration
- **Expandable entries**: Click to reveal full code, result JSON, errors, and console logs
- **Filter by tool**: Toggle between All, Search, and Execute with live counters
- **Auto-refresh**: Updates every 5 seconds without page reload
- **Clear history**: Delete all stored entries with one click
- **Zero dependencies**: Self-contained HTML page with inline CSS and JS — no Blade, no npm, no external assets

## Architecture

```
MCP Tool Call
    │
    ▼
RunsSandbox::runSandbox()
    │
    ├── existing flow (build input → run Node → parse output)
    │
    └── recordDebugEntry() ──▶ DebugStore::record()
                                    │
                                    ├── read JSON file
                                    ├── prepend entry
                                    ├── prune to max_entries
                                    └── write JSON file (LOCK_EX)


Browser: GET /codemode/debug
    │
    ▼
DebugPanelController::index()  ──▶  Self-contained HTML
    │
    ▼
JS fetches /codemode/debug/api  ──▶  DebugStore::all() → JSON
```

**Storage**: Simple JSON file (no database, no migrations). Concurrent writes protected with `LOCK_EX`. Suitable for development — not designed for production traffic.

**Security**: Routes only registered when `debug_panel.enabled` AND `APP_DEBUG` are both `true`. Uses Laravel's `web` middleware for CSRF protection on the clear endpoint.

## Production

**Do not enable in production.** The panel:
- Stores execution history including API responses on disk
- Has no authentication (relies on `APP_DEBUG=false` to prevent access)
- Is designed for local development and staging environments only

The `APP_DEBUG` guard ensures the routes physically don't exist in production. Even if someone sets `CODEMODE_DEBUG_PANEL=true` in production, the panel won't work unless `APP_DEBUG=true` — which would already expose other debug information via Laravel itself.
