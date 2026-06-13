<?php

namespace JoanFo\Resources\Providers;

use Illuminate\Support\ServiceProvider;

class ResourcesProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/resources.php', 'resources');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'resources');
    }
}
