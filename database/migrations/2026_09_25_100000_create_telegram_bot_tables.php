<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique()->index();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 10)->default('en');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_booking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')
                ->constrained('telegram_users')
                ->cascadeOnDelete();
            $table->morphs('bookable');
            $table->timestamps();

            $table->unique(['telegram_user_id', 'bookable_type', 'bookable_id'], 'uq_telegram_user_bookable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_booking_links');
        Schema::dropIfExists('telegram_users');
    }
};
