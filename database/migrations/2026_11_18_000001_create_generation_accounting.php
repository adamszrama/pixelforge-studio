<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('reserved')->default(0);
        });
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->default('queued')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->json('pricing_snapshot');
            $table->bigInteger('estimated_cost');
            $table->bigInteger('reserved_cost');
            $table->bigInteger('provider_cost')->nullable();
            $table->bigInteger('actual_cost')->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('submission_attempts')->default(0);
            $table->boolean('acceptance_uncertain')->default(false);
            $table->boolean('needs_review')->default(false);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->uuid('generation_id')->nullable()->unique();
            $table->foreign('generation_id')->references('id')->on('generations');
            $table->uuid('purchase_id')->nullable()->unique();
            $table->string('kind');
            $table->bigInteger('amount');
            $table->timestamp('created_at');
        });
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->string('provider_id')->nullable()->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('generations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'balance', 'reserved']));
    }
};
