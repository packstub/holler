<?php

namespace Packstub\Calls\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Calls\CallsServiceProvider;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('avatar_url')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $this->artisan('migrate')->run();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CallsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktv6fZ7c7ZQfF1Zt6Q0Wd9jz5bJ6rKq8nX0m3Yk=');
        $app['config']->set('cache.default', 'array');
    }

    /** The routes a plain app would have: the package's own, behind `web` and `auth`. */
    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Calls::routes();
        });
    }
}
