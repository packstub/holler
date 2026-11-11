<?php

namespace Packstub\Holler\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;

/**
 * The widget's pop-out: a page with nothing on it but the call widget, opened in its
 * own browser window so the call outlives whatever the main tab does. `?callee=`
 * rings someone from here, `?call=` answers (or continues) that call. (Filament Holler
 * has a WindowController of its own, hence the name.)
 */
class PopOutController
{
    public function __invoke(Request $request): Response
    {
        $callId = (int) $request->query('call', 0);

        if ($callId) {
            $call = Call::query()->findOrFail($callId);

            $me = (string) Holler::currentUserId();

            abort_unless($call->involves($me) && Holler::inScope($call, $me), 403);
        }

        $routes = $request->route()->getName() ? preg_replace('/window$/', '', $request->route()->getName()) : 'holler.';

        // Never inside another site's frame: a hidden frame could make a click answer a call.
        return response()->view('holler::window', ['routes' => $routes])
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'");
    }
}
