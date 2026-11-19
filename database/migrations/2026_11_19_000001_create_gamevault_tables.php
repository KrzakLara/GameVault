<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('platform', 24)->index();
            $table->unsignedInteger('price_cents');
            $table->string('cover_url')->nullable();
            $table->string('cheapshark_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('price_cents');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('game_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->text('code'); // Eloquent encrypted cast (non-deterministic ciphertext)
            $table->string('status', 16)->default('available');
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['game_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_keys');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('games');
    }
};
