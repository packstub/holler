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
            // User ids as strings so integer and UUID keys both fit.
            $table->string('caller_id', 64)->index();
            $table->string('callee_id', 64)->index();
            $table->string('status', 16)->default('ringing')->index();
            $table->string('end_reason', 16)->nullable();
            // Which browser window holds each side and when it last polled: a page that
            // reloads lets go and claims the side again, a pop-out holds it for its tab.
            $table->string('caller_tab', 32)->nullable();
            $table->string('callee_tab', 32)->nullable();
            $table->timestamp('caller_seen_at')->nullable();
            $table->timestamp('callee_seen_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('call_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->string('to_user_id', 64)->index();
            $table->string('type', 16);
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('calls');
    }
};
