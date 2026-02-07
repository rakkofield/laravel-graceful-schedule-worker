<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * テスト用の Fake Application
 *
 * Illuminate\Contracts\Foundation\Application インターフェースの Fake 実装
 */
class FakeApplication extends Container implements Application
{
    /** @var string */
    private $basePath = '/fake/base/path';

    /** @var string */
    private $environment = 'testing';

    /** @var bool */
    private $runningInConsole = true;

    /** @var bool */
    private $isDownForMaintenance = false;

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public function version()
    {
        return '0.0.0-fake';
    }

    /**
     * Get the base path of the Laravel installation.
     *
     * @param string $path
     * @return string
     */
    public function basePath($path = '')
    {
        return $this->basePath . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the bootstrap directory.
     *
     * @param string $path
     * @return string
     */
    public function bootstrapPath($path = '')
    {
        return $this->basePath('bootstrap') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the application configuration files.
     *
     * @param string $path
     * @return string
     */
    public function configPath($path = '')
    {
        return $this->basePath('config') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the database directory.
     *
     * @param string $path
     * @return string
     */
    public function databasePath($path = '')
    {
        return $this->basePath('database') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the language files.
     *
     * @param string $path
     * @return string
     */
    public function langPath($path = '')
    {
        return $this->basePath('lang') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the public / web directory.
     *
     * @param string $path
     * @return string
     */
    public function publicPath($path = '')
    {
        return $this->basePath('public') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the resources directory.
     *
     * @param string $path
     * @return string
     */
    public function resourcePath($path = '')
    {
        return $this->basePath('resources') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get the path to the storage directory.
     *
     * @param string $path
     * @return string
     */
    public function storagePath($path = '')
    {
        return $this->basePath('storage') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Get or check the current application environment.
     *
     * @param string|array ...$environments
     * @return string|bool
     */
    public function environment(...$environments)
    {
        if (count($environments) > 0) {
            $patterns = is_array($environments[0]) ? $environments[0] : $environments;
            return in_array($this->environment, $patterns, true);
        }
        return $this->environment;
    }

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    public function runningInConsole()
    {
        return $this->runningInConsole;
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    public function runningUnitTests()
    {
        return $this->environment === 'testing';
    }

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @return bool
     */
    public function isDownForMaintenance()
    {
        return $this->isDownForMaintenance;
    }

    /**
     * Register all of the configured providers.
     *
     * @return void
     */
    public function registerConfiguredProviders()
    {
        // No-op for fake
    }

    /**
     * Register a service provider with the application.
     *
     * @param \Illuminate\Support\ServiceProvider|string $provider
     * @param bool $force
     * @return \Illuminate\Support\ServiceProvider
     */
    public function register($provider, $force = false)
    {
        // No-op for fake, return a mock provider
        return new class extends \Illuminate\Support\ServiceProvider {
            public function register(): void
            {
            }
        };
    }

    /**
     * Register a deferred provider and service.
     *
     * @param string $provider
     * @param string|null $service
     * @return void
     */
    public function registerDeferredProvider($provider, $service = null)
    {
        // No-op for fake
    }

    /**
     * Resolve a service provider instance from the class name.
     *
     * @param string $provider
     * @return \Illuminate\Support\ServiceProvider
     */
    public function resolveProvider($provider)
    {
        return new $provider($this);
    }

    /**
     * Boot the application's service providers.
     *
     * @return void
     */
    public function boot()
    {
        // No-op for fake
    }

    /**
     * Register a new boot listener.
     *
     * @param callable $callback
     * @return void
     */
    public function booting($callback)
    {
        // No-op for fake
    }

    /**
     * Register a new "booted" listener.
     *
     * @param callable $callback
     * @return void
     */
    public function booted($callback)
    {
        // No-op for fake
    }

    /**
     * Run the given array of bootstrap classes.
     *
     * @param array<string> $bootstrappers
     * @return void
     */
    public function bootstrapWith(array $bootstrappers)
    {
        // No-op for fake
    }

    /**
     * Get the current application locale.
     *
     * @return string
     */
    public function getLocale()
    {
        return 'en';
    }

    /**
     * Get the application namespace.
     *
     * @return string
     */
    public function getNamespace()
    {
        return 'App\\';
    }

    /**
     * Get the registered service provider instances if any exist.
     *
     * @param \Illuminate\Support\ServiceProvider|string $provider
     * @return array<\Illuminate\Support\ServiceProvider>
     */
    public function getProviders($provider)
    {
        return [];
    }

    /**
     * Determine if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped()
    {
        return true;
    }

    /**
     * Load and boot all of the remaining deferred providers.
     *
     * @return void
     */
    public function loadDeferredProviders()
    {
        // No-op for fake
    }

    /**
     * Set the current application locale.
     *
     * @param string $locale
     * @return void
     */
    public function setLocale($locale)
    {
        // No-op for fake
    }

    /**
     * Determine if middleware has been disabled for the application.
     *
     * @return bool
     */
    public function shouldSkipMiddleware()
    {
        return true;
    }

    /**
     * Terminate the application.
     *
     * @return void
     */
    public function terminate()
    {
        // No-op for fake
    }

    // Test helper methods

    /**
     * Set the environment for testing.
     *
     * @param string $environment
     * @return void
     */
    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
    }

    /**
     * Set the base path for testing.
     *
     * @param string $basePath
     * @return void
     */
    public function setBasePath(string $basePath): void
    {
        $this->basePath = $basePath;
    }

    /**
     * Set whether the application is running in console.
     *
     * @param bool $runningInConsole
     * @return void
     */
    public function setRunningInConsole(bool $runningInConsole): void
    {
        $this->runningInConsole = $runningInConsole;
    }

    /**
     * Set whether the application is down for maintenance.
     *
     * @param bool $isDownForMaintenance
     * @return void
     */
    public function setIsDownForMaintenance(bool $isDownForMaintenance): void
    {
        $this->isDownForMaintenance = $isDownForMaintenance;
    }
}
