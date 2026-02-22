<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Artisan command to install Code Mode in a Laravel application.
 *
 * Publishes the config file, copies the sandbox directory,
 * installs Node.js dependencies, and publishes MCP routes.
 */
class InstallCommand extends Command
{
    protected $signature = 'codemode:install';

    protected $description = 'Install Code Mode: publish config, sandbox, and register routes';

    public function handle(): int
    {
        $this->components->info('Installing Code Mode...');

        $this->publishConfig();
        $this->copySandbox();
        $this->installDependencies();
        $this->publishRoutes();
        $this->printSuccess();

        return self::SUCCESS;
    }

    /**
     * Publish the package configuration file via vendor:publish.
     */
    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'codemode-config']);
        $this->components->task('Config published', fn () => true);
    }

    /**
     * Copy the sandbox directory to the application root.
     *
     * Skips copying if the sandbox directory already exists.
     */
    private function copySandbox(): void
    {
        $destination = base_path('sandbox');

        if (File::isDirectory($destination)) {
            $this->components->task('Sandbox already exists — skipped', fn () => true);

            return;
        }

        File::copyDirectory($this->sandboxSource(), $destination);
        $this->components->task('Sandbox copied', fn () => true);
    }

    /**
     * Run npm install inside the sandbox directory.
     *
     * Skips if package.json is missing or node_modules already exists.
     */
    private function installDependencies(): void
    {
        $destination = base_path('sandbox');

        if (! File::exists("{$destination}/package.json") || File::isDirectory("{$destination}/node_modules")) {
            $this->components->task('Sandbox dependencies already installed — skipped', fn () => true);

            return;
        }

        $this->components->task('Installing sandbox dependencies', function () use ($destination) {
            return Process::path($destination)->timeout(120)->run('npm install')->successful();
        });
    }

    /**
     * Publish the MCP route file via vendor:publish.
     *
     * Skips if routes/ai.php already exists in the application.
     */
    private function publishRoutes(): void
    {
        if (File::exists(base_path('routes/ai.php'))) {
            $this->components->task('MCP routes already exist — skipped', fn () => true);

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'mcp-routes']);
        $this->components->task('MCP routes published', fn () => true);
    }

    /**
     * Display the post-install success message with next-step instructions.
     */
    private function printSuccess(): void
    {
        $this->newLine();
        $this->components->info('Code Mode installed successfully!');
        $this->newLine();
        $this->line('  Start the MCP server:');
        $this->line('  <comment>php artisan mcp:start codemode</comment>');
        $this->newLine();
    }

    /**
     * Get the path to the package's sandbox source directory.
     */
    private function sandboxSource(): string
    {
        return dirname(__DIR__, 2).'/sandbox';
    }
}
