<?php

namespace Packstub\Holler\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Everything the signaling backend needs to know about the place a call
 * happens in: who is signed in, how to find the other person, how people
 * are named, who may call whom, and what the browsers should use to connect.
 *
 * The package binds AppContext (config and closures) for a plain Laravel app;
 * packstub/filament-holler binds a context that answers from the current
 * panel's plugin.
 */
interface CallContext
{
    /**
     * Whose ids these are: the guard (or panel) the users come from. Calls are kept
     * apart by scope, so user 7 of one guard is never rung for user 7 of another.
     */
    public function scope(): string;

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
     * The users matching what someone typed into the widget's "Add someone" (everyone to
     * suggest when `$query` is empty), at most `$limit`. `canCall()` is asked for each
     * one afterwards, and only until enough people are found: `$limit` is far above what is
     * shown, so narrow the query to the people the caller may call where you can, and return
     * a lazy collection or a cursor rather than loading them all. Return nothing to offer no search.
     *
     * @return iterable<Authenticatable>
     */
    public function searchUsers(Authenticatable $caller, string $query, int $limit): iterable;

    /**
     * Passed to RTCPeerConnection as is: STUN, and TURN where a direct path fails.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iceServers(): array;

    /** Whether calls start with the camera on (either side can still turn it off or share a screen). */
    public function hasVideo(): bool;

    /** People on one call, the caller included; a full mesh is good for about four. */
    public function maxParticipants(): int;
}
