<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Infrastructure\Traffic\Contracts\ConsumerResolverInterface;
use App\Infrastructure\Traffic\Resolvers\HierarchicalConsumerResolver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            ConsumerResolverInterface::class,
            HierarchicalConsumerResolver::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
