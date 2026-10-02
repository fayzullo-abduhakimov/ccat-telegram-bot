<?php

declare(strict_types=1);

use App\Telegram\Handlers\BookingPass;
use App\Telegram\Handlers\CancelBooking;
use App\Telegram\Handlers\ChooseLanguage;
use App\Telegram\Handlers\RescheduleBooking;
use App\Telegram\Handlers\ShareContact;
use App\Telegram\Handlers\ShowBookings;
use App\Telegram\Handlers\Start;
use App\Telegram\Middleware\IdentifyVisitor;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/** @var Nutgram $bot */
$bot->middleware(IdentifyVisitor::class);

$actions = [
    'bookings' => [ShowBookings::class, 'upcoming'],
    'history' => [ShowBookings::class, 'past'],
    'language' => [ChooseLanguage::class, 'show'],
];

$commands = [
    'start' => [Start::class, 'app.bot.command_start'],
    'help' => [Start::class, 'app.bot.command_help'],
    'mybookings' => [$actions['bookings'], 'app.bot.command_bookings'],
    'history' => [$actions['history'], 'app.bot.command_history'],
    'language' => [$actions['language'], 'app.bot.command_language'],
];

foreach ($commands as $command => [$handler, $description]) {
    $bot->onCommand($command, $handler)
        ->description(__($description, [], (string) config('app.locale')))
        ->description(collect(available_locales())->mapWithKeys(fn (string $locale): array => [$locale => __($description, [], $locale)])->all());
}

$bot->onCommand('start {payload}', Start::class);

foreach (Keyboards::MENU as $action => $label) {
    foreach (available_locales() as $locale) {
        $bot->onText(preg_quote(__($label, [], $locale)), $actions[$action]);
    }
}

$bot->onContact(ShareContact::class);

$bot->onCallbackQueryData('lang:{code}', [ChooseLanguage::class, 'set']);
$bot->onCallbackQueryData('qr:{type}:{id}', [BookingPass::class, 'qr']);
$bot->onCallbackQueryData('cancel:{type}:{id}', [CancelBooking::class, 'ask']);
$bot->onCallbackQueryData('cancel-yes:{type}:{id}', [CancelBooking::class, 'confirm']);
$bot->onCallbackQueryData('cancel-no:{type}:{id}', [CancelBooking::class, 'keep']);
$bot->onCallbackQueryData('move:{type}:{id}', [RescheduleBooking::class, 'days']);
$bot->onCallbackQueryData('move-day:{type}:{id}:{date}', [RescheduleBooking::class, 'times']);
$bot->onCallbackQueryData('move-to:{type}:{id}:{slot}', [RescheduleBooking::class, 'move']);
$bot->onCallbackQueryData('move-no:{type}:{id}', [RescheduleBooking::class, 'back']);

$bot->fallback([Start::class, 'menu']);

$bot->onApiError(function (Nutgram $bot, TelegramException $exception): void {
    report($exception);
});

$bot->onException(function (Nutgram $bot, Throwable $exception): void {
    report($exception);

    try {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        if ($bot->chatId() !== null) {
            $bot->sendMessage(__('app.bot.error'));
        }
    } catch (Throwable $unreachable) {
        report($unreachable);
    }
});
