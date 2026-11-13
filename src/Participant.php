<?php

namespace Packstub\Holler;

use InvalidArgumentException;

/**
 * Someone on a call who is not a user of the guard: a visitor a support agent
 * helps, for example. Code passes one to `Holler::start()`; its name and avatar
 * are stored on the call, since no context can look the id up. The id must
 * carry a prefix with a colon (`guest:<uuid>`), which no route ever resolves,
 * so nobody can ring a participant from the HTTP endpoints.
 */
final class Participant
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $avatar = null,
    ) {
        if (! self::isGuestId($id) || strlen($id) > 64) {
            throw new InvalidArgumentException('A participant id is a prefix, a colon and up to 64 characters in all, like "guest:<uuid>".');
        }

        if (mb_strlen($name) > 255 || strlen((string) $avatar) > 2048) {
            throw new InvalidArgumentException('A participant name is up to 255 characters, an avatar URL up to 2048.');
        }
    }

    /** Whether this id names a participant made in code rather than a user (it has a colon). */
    public static function isGuestId(string $id): bool
    {
        return str_contains($id, ':');
    }

    /** @return array{id: string, name: string, avatar: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'avatar' => $this->avatar];
    }
}
