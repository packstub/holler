<?php

namespace Packstub\Calls\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Models\Call;

/**
 * The widget's pop-out: a page with nothing on it but the call widget, opened in its
 * own browser window so the call outlives whatever the main tab does. `?callee=`
 * rings someone from here, `?call=` answers (or continues) that call. (Filament Calls
 * has a WindowController of its own in this namespace, hence the name.)
 */
class PopOutController
{
    public function __invoke(Request $request): View
    {
        $callId = (int) $request->query('call', 0);

        if ($callId) {
            $call = Call::query()->findOrFail($callId);

            abort_unless($call->involves((string) Calls::currentUserId()), 403);
        }

        return view('calls::window', ['routes' => $request->route()->getName() ? preg_replace('/window$/', '', $request->route()->getName()) : 'calls.']);
    }
}
