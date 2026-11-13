<?php

use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;
use Packstub\Holler\Tests\Browser\BrowserTestCase;
use Packstub\Holler\Tests\Fixtures\User;
use Packstub\Holler\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(BrowserTestCase::class)->in('Browser');

pest()->browser()->timeout(20_000);

function user(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret']);
}

function callsRoute(string $name, array $parameters = []): string
{
    return route("holler.{$name}", $parameters);
}

/** A person's fresh record on a call. */
function participantOf(Call $call, User $user): CallParticipant
{
    return CallParticipant::query()->where('call_id', $call->getKey())->where('user_id', (string) $user->getKey())->firstOrFail();
}
