<?php

namespace Packstub\Calls\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Everything the signaling backend needs to know about the place a call
 * happens in: who is signed in, how to find the other person, how people
 * are named, who may call whom, and what the browsers should use to connect.
 *
 * The package binds AppContext (config and closures) for a plain Laravel app;
 * packstub/filament-calls binds a context that answers from the current
 * panel's plugin.
 */
interface CallContext
{
    /** The signed-in user's id as a string, or null for a guest. */
    public function currentUserId(): ?string;

    /** The user with this id, through the guard that signs people in here. */
    public function findUser(string $id): ?Authenticatable;

    /**
     * How a user appears to the other side of a call.
     *
     * @return array{id: string, name: string, avatar: ?string}
     */
    public function who(Authenticatable $user): array;

    /** Whether the caller may ring the callee. Calling yourself is refused before this is asked. */
    public function canCall(Authenticatable $caller, Authenticatable $callee): bool;

    /**
     * Passed to RTCPeerConnection as is: STUN, and TURN where a direct path fails.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iceServers(): array;

    /** Whether calls start with the camera on (either side can still turn it off or share a screen). */
    public function hasVideo(): bool;
}
