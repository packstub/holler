<?php

namespace Packstub\Calls\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Calls\Models\Call;

class CallAnswered
{
    use Dispatchable;

    public function __construct(public Call $call) {}
}
