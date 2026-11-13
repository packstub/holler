<?php

use Packstub\Holler\Tests\Fixtures\User;

/** The page of a person, signed in in a browser context of their own, once the widget polls. */
function pageOf(User $user)
{
    $page = visit('/_holler/as/'.$user->getKey());
    $page->assertSee('Signed in as '.$user->name);
    eventually($page, '() => window.holler?.client?.polling === true');

    return $page;
}

/** Wait (without blocking the in-process server) until the function returns something truthy in the page. */
function eventually($page, string $script, float $seconds = 20): void
{
    $deadline = microtime(true) + $seconds;

    while (! $page->script($script)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Still false after {$seconds} s: {$script}");
        }

        $page->wait(0.2);
    }

    expect(true)->toBeTrue();
}

/** Click the visible control for this widget action (the chip and the call window both have a hang-up button). */
function act($page, string $action): void
{
    $page->script("() => [...document.querySelectorAll('[data-holler-action=\"{$action}\"]')].find((el) => el.offsetParent !== null).click()");
}

const STATE = '() => window.holler.client.state';

const REMOTE_VIDEO = "() => [...document.querySelectorAll('video.holler-remote')].some((video) => video.videoWidth > 0 && video.srcObject?.getVideoTracks().some((track) => track.readyState === 'live'))";

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('rings, connects two browsers with live video both ways, and hangs up', function (): void {
    $ion = pageOf($this->ion);
    $ana = pageOf($this->ana);

    $ion->click('[data-holler-call="'.$this->ana->getKey().'"]');
    eventually($ana, STATE." === 'ringing'");
    eventually($ana, "() => document.querySelector('div.holler').innerText.includes('Ion')");

    act($ana, 'accept');
    eventually($ion, STATE." === 'active'");
    eventually($ana, STATE." === 'active'");
    eventually($ion, REMOTE_VIDEO);
    eventually($ana, REMOTE_VIDEO);

    act($ion, 'hangUp');
    $ana->assertSee('Call ended');
    eventually($ana, STATE." === 'idle'");
    $ion->assertNoJavaScriptErrors();
    $ana->assertNoJavaScriptErrors();
});

it('tells the caller the call was declined', function (): void {
    $ion = pageOf($this->ion);
    $ana = pageOf($this->ana);

    $ion->click('[data-holler-call="'.$this->ana->getKey().'"]');
    eventually($ana, STATE." === 'ringing'");
    act($ana, 'decline');

    $ion->assertSee('Call declined');
    eventually($ion, STATE." === 'idle'");
});

it('adds a third person from the search in the call window', function (): void {
    $mihai = user('Mihai');
    $ion = pageOf($this->ion);
    $ana = pageOf($this->ana);
    $third = pageOf($mihai);

    $ion->click('[data-holler-call="'.$this->ana->getKey().'"]');
    eventually($ana, STATE." === 'ringing'");
    act($ana, 'accept');
    eventually($ion, STATE." === 'active'");

    act($ion, 'togglePeople');
    $ion->type('[data-holler-people-search]', 'mih');
    eventually($ion, "() => [...document.querySelectorAll('[data-holler-add]')].some((el) => el.offsetParent !== null && el.textContent.includes('Mihai'))");
    $ion->script("() => [...document.querySelectorAll('[data-holler-add]')].find((el) => el.textContent.includes('Mihai')).click()");

    eventually($third, STATE." === 'ringing'");
    act($third, 'accept');
    eventually($third, '() => window.holler.client.peers.filter((peer) => peer.connected).length === 2');
    eventually($ana, '() => window.holler.client.peers.filter((peer) => peer.connected).length === 2');
});

it('ends the call with a notice when the session expires mid-call', function (): void {
    $ion = pageOf($this->ion);
    $ana = pageOf($this->ana);

    $ion->click('[data-holler-call="'.$this->ana->getKey().'"]');
    eventually($ana, STATE." === 'ringing'");
    act($ana, 'accept');
    eventually($ion, STATE." === 'active'");

    expect($ion->script("() => fetch('/_holler/sign-out', { credentials: 'same-origin' }).then((response) => response.status)"))->toBe(204);

    $ion->assertSee('You were signed out. Sign in again to call.');
    eventually($ion, STATE." === 'idle'");
    // The camera is off again: nothing left running on the page.
    expect($ion->script('() => window.holler.client.cameraStream'))->toBeNull();
    // Ana's side notices the quiet window and no longer counts it as connected.
    eventually($ana, STATE." !== 'active'", 30);
});
