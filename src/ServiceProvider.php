<?php

namespace Chrisvasey\StatamicBacklinks;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => ['resources/js/addon.js', 'resources/css/addon.css'],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(BacklinkIndexer::class, fn () => new BacklinkIndexer());
    }

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-backlinks');

        $this->publishes([
            __DIR__.'/../config/backlinks.php' => config_path('backlinks.php'),
        ], 'statamic-backlinks-config');

        $this->mergeConfigFrom(__DIR__.'/../config/backlinks.php', 'backlinks');
    }
}
