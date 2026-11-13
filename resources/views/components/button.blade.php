{{-- A button that rings a user; during a call the widget asks whether to add them, hold the call or end it. `<x-holler::widget />` does the rest. --}}
@props(['user', 'name' => null])

@php
    $id = $user instanceof \Illuminate\Contracts\Auth\Authenticatable ? $user->getAuthIdentifier() : $user;
    $mine = (string) $id === (string) \Packstub\Holler\Facades\Holler::currentUserId();
    // Shown with the widget's question when the button is pressed during a call.
    $name ??= $user instanceof \Illuminate\Contracts\Auth\Authenticatable ? \Packstub\Holler\Facades\Holler::who($user)['name'] : null;
@endphp

@unless ($mine)
    <button type="button" {{ $attributes->merge(['class' => 'holler-call-button'])->merge(['title' => __('holler::ui.call')]) }} data-holler-call="{{ $id }}" @if (filled($name)) data-holler-name="{{ $name }}" @endif>
        {{ $slot->isEmpty() ? __('holler::ui.call') : $slot }}
    </button>
@endunless
