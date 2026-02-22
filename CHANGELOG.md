# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-02-22

### Added
- Two-tool MCP pattern: `search` (explore OpenAPI spec) and `execute` (call API endpoints)
- V8 sandbox isolation via `isolated-vm` for secure JavaScript execution
- `pick(obj, keys)` and `pluck(arr, keys)` sandbox helpers for compact responses
- Multi-source OpenAPI spec loading: Scramble, URL, file, auto-detection
- `$ref` resolution with circular reference protection
- Configurable API prefix auto-prepending
- HTTP method exclusion filtering
- Search-first workflow: AI must discover endpoints via `search` before calling them
- `codemode:install` Artisan command for one-step setup
- Extension points: `resolveApiContext()`, `boot()`, tool swapping
- Debug logging with auth header redaction
- Configurable sandbox timeout, memory limit, and Node.js binary path
