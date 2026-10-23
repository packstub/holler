<?php

namespace Packstub\Calls\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signaling message on its way to the other side of a call: offer, answer,
 * ICE candidate, a reset ("I have a new connection, build one for it") or a
 * state ("I am muted, my camera is off, I share my screen"). Read by id, so a poll with a cursor never misses or
 * repeats one; deleted with the call.
 *
 * @property int $id
 * @property int $call_id
 * @property string $to_user_id
 * @property string $type
 * @property array<string, mixed> $payload
 */
class CallSignal extends Model
{
    public const TYPES = ['offer', 'answer', 'ice', 'reset', 'state'];

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
