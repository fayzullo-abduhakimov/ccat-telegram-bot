<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CcatBookingService
{
    private string $baseUrl;

    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('telegram.ccat_api_url', 'http://localhost:8000/api/bot'), '/');
        $this->apiToken = trim((string) config('telegram.ccat_api_token', ''));
    }

    private function http(): PendingRequest
    {
        $client = Http::timeout(10)->acceptJson();

        if (! empty($this->apiToken)) {
            $client = $client->withToken($this->apiToken);
        }

        return $client;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBooking(string $token): ?array
    {
        try {
            $response = $this->http()->get("{$this->baseUrl}/booking/{$token}");

            if ($response->successful()) {
                $data = $response->json();

                return $data['data'] ?? null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('CcatBookingService: getBooking failed: '.$e->getMessage());

            return null;
        }
    }

    public function getQrPng(string $token): ?string
    {
        try {
            $response = $this->http()->get("{$this->baseUrl}/booking/{$token}/qr.png");

            if ($response->successful()) {
                return $response->body();
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('CcatBookingService: getQrPng failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAvailableSlots(string $token): ?array
    {
        try {
            $response = $this->http()->get("{$this->baseUrl}/booking/{$token}/available-slots");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('CcatBookingService: getAvailableSlots failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function rescheduleBooking(string $token, string $date, string $time): array
    {
        try {
            $timeNormalized = trim(str_replace(['-', '.'], ':', $time));
            if (preg_match('/^(\d{1,2}):(\d{2})/', $timeNormalized, $m)) {
                $timeNormalized = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            } elseif (preg_match('/^\d{1,2}$/', $timeNormalized)) {
                $timeNormalized = sprintf('%02d:00', (int) $timeNormalized);
            }

            $response = $this->http()->post("{$this->baseUrl}/booking/{$token}/reschedule", [
                'date' => $date,
                'time' => $timeNormalized,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?? 'Failed to reschedule booking.',
            ];
        } catch (\Throwable $e) {
            Log::error('CcatBookingService: rescheduleBooking failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error connecting to booking service.',
            ];
        }
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function rescheduleProgramme(string $token, int $programmeId): array
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/booking/{$token}/reschedule", [
                'programme_id' => $programmeId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?? 'Failed to reschedule programme booking.',
            ];
        } catch (\Throwable $e) {
            Log::error('CcatBookingService: rescheduleProgramme failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error connecting to booking service.',
            ];
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cancelBooking(string $token): array
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/booking/{$token}/cancel");

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?? 'Failed to cancel booking.',
            ];
        } catch (\Throwable $e) {
            Log::error('CcatBookingService: cancelBooking failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error connecting to booking service.',
            ];
        }
    }
}
