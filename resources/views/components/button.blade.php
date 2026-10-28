{{-- A button that rings a user, or adds them to the call you are on; `<x-calls::widget />` does the rest. --}}
@props(['user'])

@php
    $id = $user instanceof \Illuminate\Contracts\Auth\Authenticatable ? $user->getAuthIdentifier() : $user;
    $mine = (string) $id === (string) \Packstub\Calls\Facades\Calls::currentUserId();
@endphp

@unless ($mine)
    <button type="button" {{ $attributes->merge(['class' => 'calls-call-button'])->merge(['title' => __('calls::ui.call')]) }} data-calls-call="{{ $id }}">
        {{ $slot->isEmpty() ? __('calls::ui.call') : $slot }}
    </button>
@endunless
