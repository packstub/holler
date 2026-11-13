<?php

namespace Packstub\Holler\Tests\Browser;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Packstub\Holler\Tests\Fixtures\User;
use Packstub\Holler\Tests\TestCase;

/**
 * Real browsers on the plain widget (`<x-holler::widget />`), through Pest's browser plugin:
 * each `visit()` is a browser context of its own, so two people sign in side by side.
 * `composer test:browser` (`npm install` and `npx playwright install chromium` once).
 */
abstract class BrowserTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The array driver forgets the session between two requests of the in-process server.
        $app['config']->set('session.driver', 'cookie');
        $app['config']->set('holler.pop_out', false);
        $app['config']->set('holler.poll', ['idle' => 500, 'active' => 300]);

        // The in-process server keeps one container for every request, where PHP-FPM starts afresh:
        // without this, the guard would remember the last person signed in, and the session store
        // the last session's data, so the next request (another person's poll) would be theirs.
        $app['events']->listen(RequestHandled::class, function () use ($app): void {
            $app['auth']->forgetGuards();
            $app['session']->forgetDrivers();
            $app->forgetInstance('session.store');
        });
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::middleware('web')->group(function (): void {
            // Signs a person in and opens the page: one browser context per person.
            Route::get('/_holler/as/{user}', function (User $user) {
                Auth::login($user);

                return redirect('/_holler/page');
            });

            // What a session that expired looks like to the widget's next poll.
            Route::get('/_holler/sign-out', function () {
                Auth::logout();

                return response()->noContent();
            });

            Route::get('/_holler/page', fn () => Blade::render(<<<'BLADE'
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <title>Team</title>
                    <script>
                        // No camera here: a canvas and an oscillator stand in for one, real tracks for a real connection.
                        navigator.mediaDevices.getUserMedia = async ({ audio, video }) => {
                            const stream = new MediaStream()
                            if (video) {
                                const canvas = Object.assign(document.createElement('canvas'), { width: 160, height: 120 })
                                const paint = canvas.getContext('2d')
                                setInterval(() => { paint.fillStyle = `hsl(${Date.now() / 10 % 360} 70% 50%)`; paint.fillRect(0, 0, 160, 120) }, 50)
                                canvas.captureStream(10).getVideoTracks().forEach((track) => stream.addTrack(track))
                            }
                            if (audio) {
                                const context = new AudioContext()
                                const destination = context.createMediaStreamDestination()
                                context.createOscillator().connect(destination)
                                destination.stream.getAudioTracks().forEach((track) => stream.addTrack(track))
                            }

                            return stream
                        }
                    </script>
                </head>
                <body>
                    <h1>Signed in as {{ auth()->user()->name }}</h1>
                    <ul>
                        @foreach (\Packstub\Holler\Tests\Fixtures\User::query()->whereKeyNot(auth()->id())->orderBy('name')->get() as $person)
                            <li>{{ $person->name }} <x-holler::button :user="$person" /></li>
                        @endforeach
                    </ul>
                    <x-holler::widget />
                </body>
                </html>
                BLADE));
        });
    }
}
