<?php

use Illuminate\Support\Facades\Blade;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Models\Call;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('serves the engine, the widget and its stylesheet from the package, versioned and cached', function (): void {
    $url = Calls::asset('widget.js');
    expect($url)->toStartWith('http://localhost/packstub-calls/assets/widget.js?v=');

    $served = fn ($response) => file_get_contents($response->baseResponse->getFile()->getPathname());

    $widget = $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    expect($served($widget))->toContain('export class CallWidget');
    $engine = $this->get('/packstub-calls/assets/index.js')->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
    expect($served($engine))->toContain('export class CallClient');
    $styles = $this->get(Calls::asset('widget.css'))->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    expect($served($styles))->toContain('.calls {');
    $this->get('/packstub-calls/assets/other.js')->assertNotFound();
    $this->get('/packstub-calls/assets/../composer.json')->assertNotFound();
});

it('renders the widget with the routes, the token, the labels and the pop-out window', function (): void {
    $html = $this->actingAs($this->ion)->blade('<x-calls::widget />');

    $html->assertSee(Calls::asset('widget.css'), false)
        ->assertSee("import { CallWidget } from '".Calls::asset('widget.js')."'", false)
        ->assertSee('window[\'calls\'] = new CallWidget(', false)
        ->assertSee('"poll":"http://localhost/calls/poll"', false)
        ->assertSee('"answer":"http://localhost/calls/__ID__/answer"', false)
        ->assertSee('"windowUrl":"http://localhost/calls/window?call=__ID__&callee=__USER__"', false)
        ->assertSee('"popOut":true', false)
        ->assertSee('"csrf":', false)
        ->assertSee('"maxParticipants":4', false)
        ->assertSee('"incoming":"is calling you"', false);
});

it('keeps the call in the page when the pop-out is off, and takes options and a name of your own', function (): void {
    config()->set('packstub-calls.pop_out', false);

    $this->actingAs($this->ion)->blade('<x-calls::widget />')->assertSee('"windowUrl":null', false)->assertSee('"popOut":false', false);
    $this->actingAs($this->ion)->blade('<x-calls::widget :pop-out="true" name="phone" :styles="false" :options="[\'video\' => false, \'labels\' => [\'accept\' => \'Pick up\']]" />')
        ->assertSee('"popOut":true', false)
        ->assertSee('window[\'phone\'] = new CallWidget(', false)
        ->assertDontSee('widget.css')
        ->assertSee('"video":false', false)
        ->assertSee('"accept":"Pick up"', false);
});

it('renders a call button for anyone but yourself', function (): void {
    $this->actingAs($this->ion)->blade('<x-calls::button :user="$ana" class="btn" />', ['ana' => $this->ana])
        ->assertSee('data-calls-call="'.$this->ana->getKey().'"', false)
        ->assertSee('class="calls-call-button btn"', false)
        ->assertSeeText('Call');
    $this->actingAs($this->ion)->blade('<x-calls::button :user="$ana->getKey()">Ring Ana</x-calls::button>', ['ana' => $this->ana])->assertSee('Ring Ana');
    expect(trim(Blade::render('<x-calls::button :user="$ion" />', ['ion' => $this->ion])))->toBe('');
});

it('opens the pop-out page for a call of mine only', function (): void {
    $this->actingAs($this->ion)->get(callsRoute('window', ['callee' => $this->ana->getKey()]))
        ->assertOk()
        ->assertSee('"inPopOut":true', false)
        ->assertSee('"windowUrl":"http://localhost/calls/window?call=__ID__&callee=__USER__"', false);

    $call = Calls::start($this->ion, $this->ana, 'ion-1');
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
