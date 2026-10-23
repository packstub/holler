<?php

namespace Packstub\Calls\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Calls\Models\Call;

class CallEnded
{
    use Dispatchable;

    public function __construct(public Call $call) {}
}
