<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Tools\Concerns;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Run JavaScript code in an isolated V8 sandbox via Node.js.
 *
 * Spawns a Node.js subprocess running sandbox-runner.mjs, which uses
 * the isolated-vm package for V8 isolation. Provides sandbox execution,
 * debug logging with auth redaction, and output formatting.
 *
 * @see \Cloudstudio\LaravelCodemode\Tools\SearchTool
 * @see \Cloudstudio\LaravelCodemode\Tools\ExecuteTool
 */
trait RunsSandbox
{
    private const SANDBOX_RUNNER = 'sandbox-runner.mjs';

    private const BEARER_PATTERN = '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i';

    /**
     * Run JavaScript code in the isolated sandbox.
     *
     * @param  string  $code  The JavaScript code to execute.
     * @param  array<string, mixed>  $context  Variables to inject (e.g. spec).
     * @param  string|null  $apiBaseUrl  Base URL for api(). Null disables api().
     * @param  array<string, string>  $headers  Headers for every api() request.
     * @param  string|null  $apiPrefix  Auto-prefix for api() paths.
     * @return array{success: bool, result?: mixed, error?: string, logs: array}
     */
    protected function runSandbox(
        string $code,
        array $context = [],
        ?string $apiBaseUrl = null,
        array $headers = [],
        ?string $apiPrefix = null,
    ): array {
        $sandboxPath = config('codemode.sandbox.path', base_path('sandbox'));
        $nodeBinary = config('codemode.sandbox.node_binary', 'node');
        $timeout = (int) ceil(config('codemode.sandbox.timeout', 5000) / 1000);
        $memoryLimit = (int) config('codemode.sandbox.memory', 64);

        $input = $this->buildSandboxInput($code, $context, $apiBaseUrl, $headers, $apiPrefix, $memoryLimit);

        $this->debugLog('Sandbox input', [
            'tool' => static::class,
            'code' => $code,
            'has_context' => ! empty($context),
            'api_base_url' => $apiBaseUrl,
        ]);

        $result = Process::timeout($timeout)
            ->path($sandboxPath)
            ->input($input)
            ->run("{$nodeBinary} ".self::SANDBOX_RUNNER);

        if (! $result->successful()) {
            return $this->handleProcessFailure($result, $nodeBinary);
        }

        return $this->parseOutput($result->output());
    }

    /**
     * Build the JSON input payload for the sandbox subprocess.
     *
     * @param  string  $code  The JavaScript code to execute.
     * @param  array<string, mixed>  $context  Variables to inject into the sandbox.
     * @param  string|null  $apiBaseUrl  Base URL for the api() helper.
     * @param  array<string, string>  $headers  Headers for every api() request.
     * @param  string|null  $apiPrefix  Auto-prefix for api() paths.
     * @param  int  $memoryLimit  V8 isolate memory limit in MB.
     * @return string JSON-encoded payload.
     */
    private function buildSandboxInput(
        string $code,
        array $context,
        ?string $apiBaseUrl,
        array $headers,
        ?string $apiPrefix,
        int $memoryLimit,
    ): string {
        return json_encode(array_filter([
            'code' => $code,
            'context' => $context ?: null,
            'apiBaseUrl' => $apiBaseUrl,
            'headers' => $headers ?: null,
            'apiPrefix' => $apiPrefix,
            'memoryLimit' => $memoryLimit,
        ]));
    }

    /**
     * Handle a failed sandbox process, providing actionable error messages.
     *
     * @param  ProcessResult  $result  The failed process result.
     * @param  string  $nodeBinary  Path to the Node.js binary (for error context).
     * @return array{success: false, error: string, logs: array}
     */
    private function handleProcessFailure(ProcessResult $result, string $nodeBinary): array
    {
        $errorOutput = $result->errorOutput() ?: 'Sandbox process failed';

        if (str_contains($errorOutput, 'not found') || str_contains($errorOutput, 'No such file')) {
            $errorOutput = sprintf(
                "Node.js binary not found at '%s'. Install Node.js 22 (isolated-vm is not compatible with Node 24) or set CODEMODE_NODE_BINARY in .env.",
                $nodeBinary,
            );
        }

        $error = ['success' => false, 'error' => $errorOutput, 'logs' => []];

        $this->debugLog('Sandbox failed', $error, 'error');

        return $error;
    }

    /**
     * Parse and validate the JSON output from the sandbox process.
     *
     * @param  string  $rawOutput  Raw JSON string from the subprocess stdout.
     * @return array{success: bool, result?: mixed, error?: string, logs: array}
     */
    private function parseOutput(string $rawOutput): array
    {
        $output = json_decode($rawOutput, true) ?? [
            'success' => false,
            'error' => 'Failed to parse sandbox output',
            'logs' => [],
        ];

        $this->debugLog('Sandbox output', $this->redactSensitiveData($output));

        return $output;
    }

    /**
     * Redact Bearer tokens from output to prevent credential leaks in logs.
     *
     * @param  array<string, mixed>  $data  Sandbox output data potentially containing tokens.
     * @return array<string, mixed> Data with Bearer tokens replaced by "[REDACTED]".
     */
    private function redactSensitiveData(array $data): array
    {
        if (isset($data['error']) && is_string($data['error'])) {
            $data['error'] = preg_replace(self::BEARER_PATTERN, 'Bearer [REDACTED]', $data['error']);
        }

        return $data;
    }

    /**
     * Log a debug message if debug mode is enabled.
     *
     * @param  string  $message  The log message.
     * @param  array<string, mixed>  $context  Contextual data to include in the log entry.
     * @param  string  $level  PSR-3 log level (default: "debug").
     */
    private function debugLog(string $message, array $context = [], string $level = 'debug'): void
    {
        if (! config('codemode.debug', false)) {
            return;
        }

        $this->log($level, $message, $context);
    }

    /**
     * Write a log entry to the configured channel.
     *
     * @param  string  $level  PSR-3 log level (e.g. "debug", "error").
     * @param  string  $message  The log message.
     * @param  array<string, mixed>  $context  Contextual data to include in the log entry.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $channel = config('codemode.debug_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->{$level}("[CodeMode] {$message}", $context);
    }

    /**
     * Format sandbox output for the MCP response.
     *
     * @param  array{result: mixed, logs: array}  $result  Successful sandbox output.
     * @return string Formatted JSON output, optionally with console logs appended.
     */
    protected function formatOutput(array $result): string
    {
        $output = json_encode(
            $result['result'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (! empty($result['logs'])) {
            $output .= "\n\n--- Console Logs ---\n".implode("\n", $result['logs']);
        }

        return $output;
    }
}
