<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CcatBookingService;
use Illuminate\Console\Command;

class TestBookingCommand extends Command
{
    protected $signature = 'booking:check {token : The 64-char booking token}';

    protected $description = 'Test fetching booking data and QR code from the CCAT application';

    public function handle(CcatBookingService $ccat): int
    {
        $token = $this->argument('token');

        $this->info("🔍 Querying CCAT booking API for token: {$token}...");
        $booking = $ccat->getBooking($token);

        if (! $booking) {
            $this->error('❌ Booking not found or CCAT API is unreachable at '.config('telegram.ccat_api_url'));

            return self::FAILURE;
        }

        $this->info('✅ Booking found:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Type', $booking['type'] ?? ''],
                ['Badge', $booking['badge'] ?? ''],
                ['Name', $booking['name'] ?? ''],
                ['Date', $booking['date_formatted'] ?? ''],
                ['Time', $booking['time'] ?? ''],
                ['Building', $booking['building'] ?? ''],
                ['Status', $booking['status_label'] ?? ''],
                ['QR PNG Size', ! empty($booking['qr_png_base64']) ? strlen(base64_decode($booking['qr_png_base64'])).' bytes' : 'None'],
            ]
        );

        $this->info("\n📅 Querying available slots for rescheduling...");
        $slots = $ccat->getAvailableSlots($token);

        if ($slots && ! empty($slots['days'])) {
            $this->info('Available upcoming days: '.count($slots['days']));
            foreach (array_slice($slots['days'], 0, 3) as $day) {
                $slotsList = is_array($day['slots'] ?? null) ? implode(', ', array_slice($day['slots'], 0, 5)) : '';
                $this->line(" - {$day['label']}: {$slotsList}...");
            }
        } else {
            $this->warn('No available slots returned.');
        }

        return self::SUCCESS;
    }
}
