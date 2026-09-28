<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TelegramBotUpdateHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public int $tries = 3;


    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(
        public readonly array $update,
    ) {}


    public function handle(TelegramBotUpdateHandler $handler): void
    {
        try {
            $handler->handle($this->update);
        } catch (\Throwable $e) {
            Log::error('ProcessTelegramUpdate failed: ' . $e->getMessage(), [
                'update' => $this->update,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
