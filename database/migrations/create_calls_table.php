<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table): void {
            $table->id();
            // User ids as strings so integer and UUID keys both fit. `callee_id` is the first
            // person called, for a call log; everyone on the call is in `call_participants`.
            $table->string('caller_id', 64)->index();
            $table->string('callee_id', 64)->index();
            $table->string('status', 16)->default('ringing')->index();
            $table->string('end_reason', 16)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('call_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->string('user_id', 64)->index();
            // The order people were invited in (the caller has 0): between two participants
            // the lower seat makes the WebRTC offer.
            $table->unsignedSmallInteger('seat');
            // ringing | joined | left | declined | missed | dropped
            $table->string('status', 16)->default('ringing');
            // Which browser window holds this side and when it last polled: a page that
            // reloads lets go and claims the side again, a pop-out holds it for its tab.
            $table->string('tab', 32)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unique(['call_id', 'user_id']);
        });

        Schema::create('call_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->string('from_user_id', 64);
            $table->string('to_user_id', 64)->index();
            $table->string('type', 16);
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('call_participants');
        Schema::dropIfExists('calls');
    }
};
