<?php

namespace App\Providers;

use App\Repositories\Contracts\KeyValueRepositoryInterface;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(KeyValueRepositoryInterface::class, EloquentKeyValueRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
