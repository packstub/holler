<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('serves the engine, the widget and its stylesheet from the package, versioned and cached', function (): void {
    $url = Holler::asset('widget.js');
    expect($url)->toStartWith('http://localhost/holler/assets/widget.js?v=');

    $served = fn ($response) => file_get_contents($response->baseResponse->getFile()->getPathname());

    $widget = $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    expect($served($widget))->toStartWith('/*! @packstub/holler ')->toContain('as CallWidget');
    $engine = $this->get('/holler/assets/index.js')->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
    expect($served($engine))->toContain('as CallClient');
    $styles = $this->get(Holler::asset('widget.css'))->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    expect($served($styles))->toContain('.holler{');
    $this->get('/holler/assets/other.js')->assertNotFound();
    $this->get('/holler/assets/../composer.json')->assertNotFound();
});

it('renders the widget with the routes, the token, the labels and the pop-out window', function (): void {
    $html = $this->actingAs($this->ion)->blade('<x-holler::widget />');

    $html->assertSee(Holler::asset('widget.css'), false)
        ->assertSee("import { CallWidget } from '".Holler::asset('widget.js')."'", false)
        ->assertSee("const name = 'holler'", false)
        ->assertSee('window[name] = new CallWidget(', false)
        ->assertDontSee('nonce=', false)
        ->assertSee('"poll":"http://localhost/calls/poll"', false)
        ->assertSee('"answer":"http://localhost/calls/__ID__/answer"', false)
        ->assertSee('"windowUrl":"http://localhost/calls/window?call=__ID__&callee=__USER__"', false)
        ->assertSee('"popOut":true', false)
        ->assertSee('"csrf":', false)
        ->assertSee('"maxParticipants":4', false)
        ->assertSee('"incoming":"is calling you"', false);
});

it('keeps the call in the page when the pop-out is off, and takes options and a name of your own', function (): void {
    config()->set('holler.pop_out', false);

    $this->actingAs($this->ion)->blade('<x-holler::widget />')->assertSee('"windowUrl":null', false)->assertSee('"popOut":false', false);
    $this->actingAs($this->ion)->blade('<x-holler::widget :pop-out="true" name="phone" :styles="false" :options="[\'video\' => false, \'labels\' => [\'accept\' => \'Pick up\']]" />')
        ->assertSee('"popOut":true', false)
        ->assertSee("const name = 'phone'", false)
        ->assertDontSee('widget.css')
        ->assertSee('"video":false', false)
        ->assertSee('"accept":"Pick up"', false);
});

it('keeps the other translated texts when options change only some labels', function (): void {
    $html = (string) $this->actingAs($this->ion)->blade('<x-holler::widget :options="[\'labels\' => [\'accept\' => \'Pick up\']]" />');

    preg_match('/new CallWidget\((\{.*\})\)/', $html, $match);
    $labels = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['labels'];

    expect($labels['accept'])->toBe('Pick up')
        ->and($labels['decline'])->toBe(__('holler::ui.decline'))
        ->and(array_keys($labels))->toEqualCanonicalizing(array_keys(__('holler::ui')));
});

it('renders a call button for anyone but yourself', function (): void {
    $this->actingAs($this->ion)->blade('<x-holler::button :user="$ana" class="btn" />', ['ana' => $this->ana])
        ->assertSee('data-holler-call="'.$this->ana->getKey().'"', false)
        ->assertSee('class="holler-call-button btn"', false)
        ->assertSeeText('Call');
    $this->actingAs($this->ion)->blade('<x-holler::button :user="$ana->getKey()">Ring Ana</x-holler::button>', ['ana' => $this->ana])->assertSee('Ring Ana');
    expect(trim(Blade::render('<x-holler::button :user="$ion" />', ['ion' => $this->ion])))->toBe('');
});

it('opens the pop-out page for a call of mine only', function (): void {
    $this->actingAs($this->ion)->get(callsRoute('window', ['callee' => $this->ana->getKey()]))
        ->assertOk()
        ->assertSee('"inPopOut":true', false)
        ->assertSee('"windowUrl":"http://localhost/calls/window?call=__ID__&callee=__USER__"', false);

    $call = Holler::start($this->ion, $this->ana, 'ion-1');
    $this->actingAs($this->ion)->get(callsRoute('window', ['call' => $call->id]))->assertOk();
    $this->actingAs($this->ana)->get(callsRoute('window', ['call' => $call->id]))->assertOk();
    $this->actingAs(user('Dana'))->get(callsRoute('window', ['call' => $call->id]))->assertForbidden();
    $this->actingAs($this->ion)->get(callsRoute('window', ['call' => 999]))->assertNotFound();
    expect(Call::query()->count())->toBe(1);
});

it('keeps the pop-out page out of other sites\' frames', function (): void {
    $this->actingAs($this->ion)->get(callsRoute('window'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
});

it('renders no widget for a visitor who is not signed in', function (): void {
    expect(trim(Blade::render('<x-holler::widget />')))->toBe('');
});

it('ships the same labels and messages in every language', function (): void {
    foreach (['ui', 'messages'] as $name) {
        $english = require __DIR__."/../../resources/lang/en/{$name}.php";

        foreach (glob(__DIR__."/../../resources/lang/*/{$name}.php") as $file) {
            $texts = require $file;

            expect(array_keys($texts))->toBe(array_keys($english), $file);

            foreach ($english as $key => $text) {
                preg_match_all('/:[a-z]+/', $text, $placeholders);
                expect($texts[$key])->toContain(...$placeholders[0]);
            }
        }
    }
});

it('puts the Content-Security-Policy nonce on the script and the stylesheet', function (): void {
    $this->actingAs($this->ion)->blade('<x-holler::widget nonce="abc123" />')
        ->assertSee('<script type="module" data-holler-widget  nonce="abc123" >', false)
        ->assertSee('widget.css')->assertSee('nonce="abc123" >', false);

    Vite::useCspNonce('from-vite');

    $this->actingAs($this->ion)->blade('<x-holler::widget />')->assertSee('nonce="from-vite"', false);
});
