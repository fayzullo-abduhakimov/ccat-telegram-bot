<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Support\PhoneNumber;
use App\Telegram\Middleware\IdentifyVisitor;
use App\Telegram\Support\Keyboards;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;

final class ShareContact
{
    public function __construct(private readonly ShowBookings $bookings) {}

    public function __invoke(Nutgram $bot): void
    {
        $contact = $bot->message()?->contact;

        if ($contact === null || $contact->user_id !== $bot->userId()) {
            $bot->sendMessage(__('app.bot.not_own_contact'), reply_markup: Keyboards::sharePhone());

            return;
        }

        $phone = PhoneNumber::normalize($contact->phone_number);

        if ($phone === null) {
            $bot->sendMessage(__('app.bot.invalid_phone'), reply_markup: Keyboards::sharePhone());

            return;
        }

        $visitor = IdentifyVisitor::visitor($bot);

        DB::transaction(function () use ($visitor, $phone): void {
            TelegramUser::query()->where('phone', $phone)->whereKeyNot($visitor->getKey())->update(['phone' => null]);
            $visitor->update(['phone' => $phone]);
        });

        $bot->sendMessage(__('app.bot.phone_saved', ['phone' => $phone]), reply_markup: Keyboards::menu());

        $this->bookings->upcoming($bot);
    }
}
