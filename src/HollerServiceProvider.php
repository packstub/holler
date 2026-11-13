<?php

namespace Packstub\Holler;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Packstub\Holler\Commands\PruneCommand;
use Packstub\Holler\Contracts\CallContext;
use Packstub\Holler\Http\AssetController;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HollerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'holler';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('holler')
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_holler_tables')
            ->hasCommand(PruneCommand::class)
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Holler::class);
        $this->app->singleton(AppContext::class);

        // A package that knows better (a Filament panel) binds its own context; this one is the fallback.
        if (! $this->app->bound(CallContext::class)) {
            $this->app->bind(CallContext::class, fn ($app) => $app->make(AppContext::class));
        }
    }

    public function packageBooted(): void
    {
        // The engine, the widget and its stylesheet, served from the package: nothing to publish or bundle.
        Route::get('holler/assets/{file}', AssetController::class)->where('file', '[a-z]+\.(js|css)')->name('holler.asset');

        // Signals are relayed byte for byte: a session description ends in CRLF and the parser wants
        // it there, and Firefox ends its candidates with `candidate: ""`, which must not become null.
        // Matches the default routes (`calls/{id}/signals`) and any prefix that ends in "calls".
        $signals = fn (Request $request): bool => $request->is('*calls/*/signals');
        TrimStrings::skipWhen($signals);
        ConvertEmptyStringsToNull::skipWhen($signals);

        // Nobody rings people, or walks through the user list, faster than a person would.
        foreach (['calls' => 30, 'people' => 60] as $name => $default) {
            RateLimiter::for('holler-'.$name, function (Request $request) use ($name, $default): Limit {
                $perMinute = (int) config('holler.rate_limits.'.$name, $default);
                $holler = $this->app->make(Holler::class);

                return $perMinute > 0
                    ? Limit::perMinute($perMinute)->by($holler->scope().'|'.($holler->currentUserId() ?? $request->ip()))
                    : Limit::none();
            });
        }
    }
}
