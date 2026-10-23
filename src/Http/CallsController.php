<?php

namespace Packstub\Calls\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Packstub\Calls\Calls;
use Packstub\Calls\Exceptions\CallException;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallSignal;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The signaling endpoints: a few JSON routes behind the app's auth. The
 * browsers exchange offer, answer and ICE candidates through here and then
 * talk to each other directly; no media ever comes through this controller.
 */
class CallsController
{
    public function __construct(protected Calls $calls) {}

    /** Ring someone. Any call the caller still has open is ended first. */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['callee' => ['required'], 'tab' => ['nullable', 'string', 'max:32']]);
        $calleeId = $request->input('callee');

        if (! is_scalar($calleeId) || strlen((string) $calleeId) > 64) {
            throw new HttpException(422, __('calls::messages.cannot_call'));
        }

        $tab = $request->input('tab');
        $call = $this->attempt(fn (): Call => $this->calls->start($request->user(), (string) $calleeId, $tab));

        return response()->json($this->calls->present($call, $this->me(), $tab), 201);
    }

    public function answer(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);
        $request->validate(['tab' => ['nullable', 'string', 'max:32']]);
        $tab = $request->input('tab');

        $this->attempt(fn (): Call => $this->calls->answer($call, $me, $tab));

        return response()->json($this->calls->present($call, $me, $tab));
    }

    public function decline(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        $this->calls->decline($call, $me);

        return response()->json($this->calls->present($call, $me));
    }

    public function hangup(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        $this->calls->hangup($call, $me);

        return response()->json($this->calls->present($call, $me));
    }

    /**
     * The window is going away (a beacon on unload): its side is free for the
     * next page to claim at once, and the call lives on until the reconnect
     * timeout if nobody does.
     */
    public function leave(Request $request, Call $call): Response
    {
        $me = $this->participant($call);

        $call->release($me, (string) $request->input('tab'));

        return response()->noContent();
    }

    /** Queue one signaling message for the other side. */
    public function signal(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        $data = $request->validate([
            'type' => ['required', Rule::in(CallSignal::TYPES)],
            'payload' => ['required', 'array'],
        ]);

        $signal = $this->attempt(fn (): CallSignal => $this->calls->signal($call, $me, $data['type'], $data['payload']));

        return response()->json(['id' => $signal->id], 201);
    }

    /**
     * The one request every window repeats: an incoming call if someone is
     * ringing me, the call I am in (held by this window from then on when it
     * asks to `hold` it, which is also its heartbeat), and the signals sent to
     * me since `after`.
     */
    public function poll(Request $request): JsonResponse
    {
        return response()->json($this->calls->poll(
            me: $this->me(),
            after: (int) $request->query('after', 0),
            currentId: (int) $request->query('call', 0) ?: null,
            tab: substr((string) $request->query('tab', ''), 0, 32) ?: null,
            // Only a window that runs the connection takes the side; a tab showing the chip just watches.
            hold: $request->boolean('hold'),
        ));
    }

    protected function me(): string
    {
        $me = $this->calls->currentUserId();

        abort_if($me === null, 401);

        return $me;
    }

    protected function participant(Call $call): string
    {
        $me = $this->me();

        abort_unless($call->involves($me), 403);

        return $me;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (CallException $exception) {
            throw new HttpException($exception->status, $exception->getMessage(), $exception);
        }
    }
}
