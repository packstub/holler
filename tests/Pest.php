<?php

use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;
use Packstub\Calls\Tests\Fixtures\User;
use Packstub\Calls\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function user(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret']);
}

function callsRoute(string $name, array $parameters = []): string
{
    return route("calls.{$name}", $parameters);
}

/** A person's fresh record on a call. */
function participantOf(Call $call, User $user): CallParticipant
{
    return CallParticipant::query()->where('call_id', $call->getKey())->where('user_id', (string) $user->getKey())->firstOrFail();
}
