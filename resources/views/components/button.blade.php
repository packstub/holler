{{-- A button that rings a user, or adds them to the call you are on; `<x-holler::widget />` does the rest. --}}
@props(['user'])

@php
    $id = $user instanceof \Illuminate\Contracts\Auth\Authenticatable ? $user->getAuthIdentifier() : $user;
    $mine = (string) $id === (string) \Packstub\Holler\Facades\Holler::currentUserId();
@endphp

@unless ($mine)
    <button type="button" {{ $attributes->merge(['class' => 'holler-call-button'])->merge(['title' => __('holler::ui.call')]) }} data-holler-call="{{ $id }}">
        {{ $slot->isEmpty() ? __('holler::ui.call') : $slot }}
    </button>
@endunless
