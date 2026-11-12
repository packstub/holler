<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** `holler.connection`: under database-per-tenant, one table everybody's calls meet in. */
    public function getConnection(): ?string
    {
        return config('holler.connection') ?? $this->connection;
    }

    public function up(): void
    {
        Schema::create('holler_calls', function (Blueprint $table): void {
            $table->id();
            // User ids as strings so integer and UUID keys both fit. `callee_id` is the first
            // person called, for a call log; everyone on the call is in `holler_call_participants`.
            $table->string('caller_id', 64)->index();
            $table->string('callee_id', 64)->index();
            // `call` from the widgets; another package names its own (`assist`) so a widget
            // polling with `kind=call` never sees it.
            $table->string('kind', 16)->default('call');
            // Whose user ids these are (the guard): two guards with overlapping ids never share a call.
            $table->string('scope', 64)->default('');
            $table->string('status', 16)->default('ringing')->index();
            $table->string('end_reason', 16)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('holler_call_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('holler_calls')->cascadeOnDelete();
            $table->string('user_id', 64)->index();
            // Set for a participant made in code (`guest:<uuid>`), whom no context can look up.
            $table->string('name')->nullable();
            $table->string('avatar', 2048)->nullable();
            // The order people were invited in (the caller has 0): between two participants
            // the lower seat makes the WebRTC offer.
            $table->unsignedSmallInteger('seat');
            // ringing | joined | left | declined | missed | dropped
            $table->string('status', 16)->default('ringing');
            $table->index(['user_id', 'status']);
            // Which browser window holds this side and when it last polled: a page that
            // reloads lets go and claims the side again, a pop-out holds it for its tab.
            $table->string('tab', 32)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unique(['call_id', 'user_id']);
        });

        Schema::create('holler_call_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('holler_calls')->cascadeOnDelete();
            $table->string('from_user_id', 64);
            $table->string('to_user_id', 64)->index();
            $table->string('type', 16);
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
            // What every poll reads: my signals on my call after a cursor.
            $table->index(['call_id', 'to_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holler_call_signals');
        Schema::dropIfExists('holler_call_participants');
        Schema::dropIfExists('holler_calls');
    }
};
