<?php

namespace Packstub\Holler\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Holler\Models\Call;

class CallStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Call $call) {}
}
