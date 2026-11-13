<?php

use Packstub\Holler\AppContext;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->mihai = user('Mihai');
    $this->dana = user('Dana');
});

afterEach(function (): void {
    app(AppContext::class)->canCallUsing(null)->searchUsersUsing(null);
});

function peopleAs(User $user, array $query = [])
{
    return test()->actingAs($user)->getJson(callsRoute('people', $query));
}

it('lists the people I can call, by name, and finds them by what I type', function (): void {
    peopleAs($this->ion)
        ->assertOk()
        ->assertJsonCount(3, 'people')
        ->assertJsonPath('people.0', ['id' => (string) $this->ana->getKey(), 'name' => 'Ana', 'avatar' => null])
        ->assertJsonPath('people.1.name', 'Dana')
        ->assertJsonPath('people.2.name', 'Mihai');

    // Whatever the case, in the name; never myself, and never by an email address.
    peopleAs($this->ion, ['q' => 'AN'])->assertJsonCount(2, 'people')->assertJsonPath('people.0.name', 'Ana')->assertJsonPath('people.1.name', 'Dana');
    peopleAs($this->ion, ['q' => 'mihai@'])->assertJsonCount(0, 'people');
    peopleAs($this->ion, ['q' => 'example.com'])->assertJsonCount(0, 'people');
    peopleAs($this->ion, ['q' => 'ion'])->assertJsonCount(0, 'people');
    // A wildcard typed by a person is not one.
    peopleAs($this->ion, ['q' => '%'])->assertJsonCount(3, 'people');
    peopleAs($this->ion, ['q' => ['not', 'text']])->assertJsonCount(3, 'people');
});

it('leaves out the people the context keeps me from calling', function (): void {
    app(AppContext::class)->canCallUsing(fn (User $caller, User $callee): bool => $callee->name !== 'Dana');

    peopleAs($this->ion)->assertJsonCount(2, 'people')->assertJsonMissing(['name' => 'Dana']);
});

it('leaves out the people already on the call, and anyone kept apart from someone on it', function (): void {
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])->assertCreated();
    $call = Call::query()->latest('id')->firstOrFail();

    peopleAs($this->ion, ['call' => $call->id])->assertJsonCount(2, 'people')->assertJsonMissing(['name' => 'Ana']);

    // Ana and Dana may not meet: Dana is not offered for a call Ana is on.
    app(AppContext::class)->canCallUsing(fn (User $caller, User $callee): bool => ! ($caller->name === 'Ana' && $callee->name === 'Dana'));

    peopleAs($this->ion, ['call' => $call->id])->assertJsonCount(1, 'people')->assertJsonPath('people.0.name', 'Mihai');

    // Someone else's call tells a stranger nothing.
    peopleAs($this->mihai, ['call' => $call->id])->assertForbidden();
});

it('takes the search from a closure, searches other columns, and can be turned off', function (): void {
    app(AppContext::class)->searchUsersUsing(fn (User $caller, string $query, int $limit) => User::query()->where('name', 'Mihai')->get());

    peopleAs($this->ion, ['q' => 'anything'])->assertJsonCount(1, 'people')->assertJsonPath('people.0.name', 'Mihai');

    app(AppContext::class)->searchUsersUsing(null);
    config()->set('holler.search_columns', ['name', 'email']);

    peopleAs($this->ion, ['q' => 'mihai@'])->assertJsonCount(1, 'people')->assertJsonPath('people.0.name', 'Mihai');

    config()->set('holler.people_search', false);

    peopleAs($this->ion)->assertOk()->assertJsonCount(0, 'people');
});

it('shows a handful of people at most, and nobody to a visitor', function (): void {
    foreach (range(1, 12) as $n) {
        user("Person{$n}");
    }

    peopleAs($this->ion)->assertJsonCount(8, 'people');

    auth()->logout();
    $this->getJson(callsRoute('people'))->assertUnauthorized();
});

it('finds the people I may call behind many I may not', function (): void {
    // Fifty strangers sort before the one colleague: the search walks past them.
    foreach (range(1, 50) as $i) {
        user(sprintf('Aaron%02d', $i));
    }

    $colleague = user('Zoe');
    app(AppContext::class)->canCallUsing(fn (User $caller, User $callee): bool => ! str_starts_with($callee->name, 'Aaron'));

    peopleAs($this->ion)->assertJsonCount(4, 'people')->assertJsonPath('people.3.id', (string) $colleague->getKey());
});
