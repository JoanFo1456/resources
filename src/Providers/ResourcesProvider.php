<?php

namespace JoanFo\Resources\Providers;

use App\Models\Server;
use Illuminate\Support\ServiceProvider;
use JoanFo\Resources\Observers\ServerEggObserver;

class ResourcesProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/resources.php', 'resources');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'resources');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        Server::observe(ServerEggObserver::class);
    }
}
