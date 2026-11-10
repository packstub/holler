<?php

namespace Packstub\Calls;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Packstub\Calls\Commands\PruneCommand;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Http\AssetController;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CallsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'calls';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('packstub-calls')
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_calls_table')
            ->hasCommand(PruneCommand::class)
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Calls::class);
        $this->app->singleton(AppContext::class);

        // A package that knows better (a Filament panel) binds its own context; this one is the fallback.
        if (! $this->app->bound(CallContext::class)) {
            $this->app->bind(CallContext::class, fn ($app) => $app->make(AppContext::class));
        }
    }

    public function packageBooted(): void
    {
        // The engine, the widget and its stylesheet, served from the package: nothing to publish or bundle.
        Route::get('packstub-calls/assets/{file}', AssetController::class)->where('file', '[a-z]+\.(js|css)')->name('calls.asset');

        // Signals are relayed byte for byte: a session description ends in CRLF and the parser wants
        // it there, and Firefox ends its candidates with `candidate: ""`, which must not become null.
        // Matches the default routes (`calls/{id}/signals`) and any prefix that ends in "calls".
        $signals = fn (Request $request): bool => $request->is('*calls/*/signals');
        TrimStrings::skipWhen($signals);
        ConvertEmptyStringsToNull::skipWhen($signals);
    }
}
