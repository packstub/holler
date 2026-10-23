<?php

namespace Packstub\Calls;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Packstub\Calls\Contracts\CallContext;
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
            ->hasMigration('create_calls_table')
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
        // A session description ends in CRLF and the parser wants it there: no trimming on signals.
        // Matches the default routes (`calls/{id}/signals`) and any prefix that ends in "calls".
        TrimStrings::skipWhen(fn (Request $request): bool => $request->is('*calls/*/signals'));
    }
}
