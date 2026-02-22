<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode;

use Cloudstudio\LaravelCodemode\Console\InstallCommand;
use Cloudstudio\LaravelCodemode\Servers\CodeModeServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

/**
 * Service provider for the Code Mode package.
 *
 * Registers the config, publishes assets (config and sandbox),
 * and optionally auto-registers the default CodeModeServer at
 * the configured route and handle.
 */
class CodeModeServiceProvider extends ServiceProvider
{
    /**
     * Register the package configuration.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/codemode.php', 'codemode');
    }

    /**
     * Boot the package services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishAssets();
        $this->registerMcpServer();
        $this->registerCommands();
    }

    /**
     * Publish config and sandbox assets for vendor:publish.
     *
     * @return void
     */
    private function publishAssets(): void
    {
        $this->publishes([
            __DIR__.'/../config/codemode.php' => config_path('codemode.php'),
        ], 'codemode-config');

        $this->publishes([
            __DIR__.'/../sandbox' => base_path('sandbox'),
        ], 'codemode-sandbox');
    }

    /**
     * Auto-register the default CodeModeServer unless disabled.
     *
     * @return void
     */
    private function registerMcpServer(): void
    {
        if (! config('codemode.auto_register', true)) {
            return;
        }

        $route = config('codemode.route', '/mcp/codemode');
        $handle = config('codemode.handle', 'codemode');

        Mcp::web($route, CodeModeServer::class);
        Mcp::local($handle, CodeModeServer::class);
    }

    /**
     * Register Artisan console commands when running in CLI.
     *
     * @return void
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}
