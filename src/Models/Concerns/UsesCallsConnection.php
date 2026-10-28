<?php

namespace Packstub\Calls\Models\Concerns;

/** The tables live on `packstub-calls.connection` (null: the default connection). */
trait UsesCallsConnection
{
    public function getConnectionName(): ?string
    {
        return config('packstub-calls.connection') ?? $this->connection;
    }
}
