<?php

namespace Packstub\Calls\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Calls\Models\Call;

class CallEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Call $call) {}
}
