<?php

namespace Packstub\Calls\Exceptions;

/** Yourself, nobody, or someone the context says no to. */
class CallNotAllowed extends CallException
{
    public int $status = 422;
}
