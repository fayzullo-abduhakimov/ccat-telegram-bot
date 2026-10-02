<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('telegram_booking_links');
        Schema::dropIfExists('personal_access_tokens');

        $botUserIds = DB::table('users')->where('email', 'bot-service@ccat.uz')->pluck('id');

        if ($botUserIds->isEmpty()) {
            return;
        }

        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table(config("permission.table_names.{$table}", $table))
                ->where('model_type', User::class)
                ->whereIn(config('permission.column_names.model_morph_key', 'model_id'), $botUserIds)
                ->delete();
        }

        DB::table('users')->whereIn('id', $botUserIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('telegram_booking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->cascadeOnDelete();
            $table->morphs('bookable');
            $table->timestamps();

            $table->unique(['telegram_user_id', 'bookable_type', 'bookable_id'], 'uq_telegram_user_bookable');
        });
    }
};
