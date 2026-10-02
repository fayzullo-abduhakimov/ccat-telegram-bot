<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $bookingTables = ['visit_bookings', 'library_bookings', 'programme_bookings'];

    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->index()->after('last_name');
        });

        foreach ($this->bookingTables as $bookingTable) {
            Schema::table($bookingTable, function (Blueprint $table) {
                $table->string('phone_normalized', 20)->nullable()->index()->after('phone');
            });

            DB::table($bookingTable)->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($bookings) use ($bookingTable): void {
                foreach ($bookings as $booking) {
                    DB::table($bookingTable)
                        ->where('id', $booking->id)
                        ->update(['phone_normalized' => PhoneNumber::normalize($booking->phone)]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->bookingTables as $bookingTable) {
            Schema::table($bookingTable, function (Blueprint $table) {
                $table->dropIndex(['phone_normalized']);
                $table->dropColumn('phone_normalized');
            });
        }

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn('phone');
        });
    }
};
