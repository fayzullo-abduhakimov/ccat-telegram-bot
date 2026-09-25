<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TelegramBotUpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly CcatBookingService $ccat,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! $chatId) {
            return;
        }

        $from = $message['from'] ?? [];
        $locale = $this->resolveLanguageCode($chatId, $from['language_code'] ?? null);

        if (in_array($text, ['/language', '/lang', '🌐 Language', '🌐 Язык', '🌐 Til'], true) || str_starts_with($text, '🌐')) {
            $this->sendLanguageSelection($chatId);

            return;
        }

        if (str_starts_with($text, '/start')) {
            $parts = explode(' ', $text, 2);
            $token = isset($parts[1]) ? trim($parts[1]) : '';

            if (! empty($token)) {
                $this->ccat->linkTelegramBooking($chatId, $token, [
                    'username' => $from['username'] ?? null,
                    'first_name' => $from['first_name'] ?? null,
                    'last_name' => $from['last_name'] ?? null,
                    'language_code' => $locale,
                ]);

                $this->sendBookingPass($chatId, $token, $locale);

                return;
            }

            // Check if user has active bookings
            $bookings = $this->ccat->getTelegramBookings($chatId, 'upcoming');
            if (! empty($bookings)) {
                $this->renderBookingsList($chatId, $bookings, $locale);

                return;
            }

            $this->sendWelcomeMessage($chatId, $locale);

            return;
        }

        if (in_array($text, ['/mybookings', '/bookings', '📋 My Bookings', '📋 Мои бронирования', '📋 Mening bandliklarim'], true) || str_starts_with($text, '📋')) {
            $this->handleMyBookings($chatId, $locale);

            return;
        }

        if ($text === '/help') {
            $this->sendWelcomeMessage($chatId, $locale);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('bot.welcome_body', [], $locale),
            $this->getPersistentReplyMarkup($locale)
        );
    }

    /**
     * @param  array<string, mixed>  $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $from = $callbackQuery['from'] ?? [];
        $locale = $this->resolveLanguageCode($chatId, $from['language_code'] ?? null);

        if (! $chatId) {
            $this->telegram->answerCallbackQuery($queryId);

            return;
        }

        if (str_starts_with($data, 'lang:') || str_starts_with($data, 'set_lang:')) {
            $parts = explode(':', $data);
            $newLang = $parts[1] ?? 'en';
            $this->handleSetLanguage($queryId, $chatId, $newLang);

            return;
        }

        if (in_array($data, ['my_bookings', 'my', 'bookings'], true)) {
            $this->handleMyBookings($chatId, $locale, $queryId);

            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $ref = $parts[1] ?? '';

        if (in_array($action, ['res', 'reschedule'], true)) {
            $param1 = $parts[2] ?? '';
            if ($param1 === 'prog') {
                $param2 = $parts[3] ?? '';
            } else {
                if (isset($parts[4]) && $parts[4] !== '') {
                    $param2 = "{$parts[3]}:{$parts[4]}";
                } else {
                    $param2 = str_replace('-', ':', $parts[3] ?? '');
                }
            }

            $this->handleReschedule($queryId, $chatId, $ref, $param1, $param2, $locale);

            return;
        }

        match ($action) {
            'view' => $this->handleViewPass($queryId, $chatId, $ref, $locale),
            'c_conf', 'cancel_confirm' => $this->handleCancelConfirm($queryId, $chatId, $ref, $locale),
            'c_do', 'cancel_do' => $this->handleCancelDo($queryId, $chatId, $ref, $locale),
            'c_time', 'change_time' => $this->handleChangeTime($queryId, $chatId, $ref, $locale),
            'day', 'pick_day' => $this->handlePickDay($queryId, $chatId, $ref, $parts[2] ?? '', $locale),
            default => $this->telegram->answerCallbackQuery($queryId),
        };
    }

    private function sendBookingPass(int|string $chatId, string $token, string $locale = 'en'): void
    {
        $booking = $this->ccat->getBooking($token);

        if (! $booking) {
            $notFoundMsg = $locale === 'en'
                ? "❌ <b>Booking Not Found</b>\n\nWe could not find an active booking matching reference <code>".$this->escapeHtml($token)."</code>.\n\nPlease check your confirmation on <a href=\"https://ccat.uz\">ccat.uz</a>."
                : __('bot.booking_not_found', ['ref' => $this->escapeHtml($token)], $locale);

            $this->telegram->sendMessage(
                $chatId,
                $notFoundMsg,
                $this->getPersistentReplyMarkup($locale)
            );

            return;
        }

        $ref = (string) ($booking['ref'] ?? substr((string) ($booking['token'] ?? $token), 0, 16));
        $canChangeTime = ! empty($booking['can_change_time']) && (($booking['status'] ?? '') !== 'verified') && (($booking['status'] ?? '') !== 'cancelled');
        $canCancel = ! empty($booking['can_cancel']) && (($booking['status'] ?? '') !== 'verified') && (($booking['status'] ?? '') !== 'cancelled');
        $caption = $this->formatBookingCaption($booking);
        $keyboard = $this->buildBookingKeyboard($ref, $canChangeTime, $canCancel, $locale);

        $pngBytes = ! empty($booking['qr_png_base64'])
            ? base64_decode($booking['qr_png_base64'])
            : $this->ccat->getQrPng($token);

        $sent = null;
        if ($pngBytes) {
            if (mb_strlen(strip_tags($caption)) <= 1024) {
                $sent = $this->telegram->sendPhoto(
                    $chatId,
                    $pngBytes,
                    "ccat-qr-{$ref}.png",
                    $caption,
                    $keyboard
                );
            } else {
                $this->telegram->sendPhoto(
                    $chatId,
                    $pngBytes,
                    "ccat-qr-{$ref}.png"
                );
                $sent = $this->telegram->sendMessage(
                    $chatId,
                    $caption,
                    $keyboard
                );
            }
        }

        if (! $sent) {
            $this->telegram->sendMessage(
                $chatId,
                $caption,
                $keyboard
            );
        }
    }

    private function handleViewPass(string $queryId, int|string $chatId, string $ref, string $locale = 'en'): void
    {
        $this->telegram->answerCallbackQuery($queryId);
        $this->sendBookingPass($chatId, $ref, $locale);
    }

    private function handleCancelConfirm(string $queryId, int|string $chatId, string $ref, string $locale = 'en'): void
    {
        $this->telegram->answerCallbackQuery($queryId);

        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_cancel']) || ($booking['status'] ?? '') === 'verified' || ($booking['status'] ?? '') === 'cancelled')) {
            $this->telegram->sendMessage(
                $chatId,
                __('bot.action_not_allowed_cancel_verified', [], $locale),
                ['inline_keyboard' => [[['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $name = $booking ? ' ('.$this->escapeHtml((string) ($booking['name'] ?? '')).')' : '';

        $text = $locale === 'en'
            ? "⚠️ <b>Cancel Booking{$name}?</b>\n\nAre you sure you want to cancel your reservation?\nYour reserved slot will be freed immediately and cannot be held."
            : __('bot.cancel_confirm_prompt', ['ref' => $ref.$name], $locale);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => __('bot.btn_confirm_cancel', [], $locale), 'callback_data' => "c_do:{$ref}"],
                    ['text' => __('bot.btn_keep_booking', [], $locale), 'callback_data' => "view:{$ref}"],
                ],
            ],
        ];

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    private function handleCancelDo(string $queryId, int|string $chatId, string $ref, string $locale = 'en'): void
    {
        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_cancel']) || ($booking['status'] ?? '') === 'verified' || ($booking['status'] ?? '') === 'cancelled')) {
            $this->telegram->answerCallbackQuery($queryId, __('bot.action_not_allowed_cancel_verified', [], $locale), true);
            $this->telegram->sendMessage(
                $chatId,
                __('bot.action_not_allowed_cancel_verified', [], $locale),
                ['inline_keyboard' => [[['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $result = $this->ccat->cancelBooking($ref);

        if ($result['success'] ?? false) {
            $this->telegram->answerCallbackQuery($queryId, 'Booking cancelled successfully', true);

            $text = $locale === 'en'
                ? "✅ <b>Booking Cancelled</b>\n\nYour reservation has been cancelled and your place has been released.\n\nIf you would like to book a visit at another time, please register at <a href=\"https://ccat.uz\">ccat.uz</a>."
                : __('bot.cancel_success', [], $locale);

            $this->telegram->sendMessage(
                $chatId,
                $text,
                $this->getPersistentReplyMarkup($locale)
            );
        } else {
            $this->telegram->answerCallbackQuery($queryId, 'Error cancelling booking', true);
            $error = $this->escapeHtml((string) ($result['message'] ?? 'Could not cancel booking.'));
            $this->telegram->sendMessage($chatId, __('bot.cancel_failed', ['error' => $error], $locale));
        }
    }

    private function handleChangeTime(string $queryId, int|string $chatId, string $ref, string $locale = 'en'): void
    {
        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_change_time']) || ($booking['status'] ?? '') === 'verified' || ($booking['status'] ?? '') === 'cancelled')) {
            $this->telegram->answerCallbackQuery($queryId, __('bot.action_not_allowed_verified', [], $locale), true);
            $this->telegram->sendMessage(
                $chatId,
                __('bot.action_not_allowed_verified', [], $locale),
                ['inline_keyboard' => [[['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $slotsData = $this->ccat->getAvailableSlots($ref);

        if (! $slotsData || empty($slotsData['days'])) {
            $msg = $slotsData['message'] ?? __('bot.reschedule_no_slots', [], $locale);
            $this->telegram->answerCallbackQuery($queryId, strip_tags($msg), true);
            $this->telegram->sendMessage(
                $chatId,
                __('bot.reschedule_no_slots', [], $locale),
                ['inline_keyboard' => [[['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $this->telegram->answerCallbackQuery($queryId);

        $type = $slotsData['type'] ?? 'visit';

        if ($type === 'programme') {
            $inlineKeyboard = [];
            foreach (array_slice($slotsData['days'], 0, 8) as $prog) {
                $rawTitle = trim(strip_tags((string) ($prog['title'] ?? 'Event')));
                $btnText = '🎭 '.mb_substr($rawTitle, 0, 24);
                if (! empty($prog['date'])) {
                    $btnText .= " ({$prog['date']})";
                }
                $inlineKeyboard[] = [
                    ['text' => $btnText, 'callback_data' => "res:{$ref}:prog:{$prog['id']}"],
                ];
            }
            $inlineKeyboard[] = [['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"]];

            $this->telegram->sendMessage(
                $chatId,
                '📅 <b>'.__('bot.reschedule_title', [], $locale).'</b>',
                ['inline_keyboard' => $inlineKeyboard]
            );

            return;
        }

        $inlineKeyboard = [];
        $row = [];

        foreach (array_slice($slotsData['days'], 0, 10) as $day) {
            $label = $day['label'] ?? $day['date'];
            $row[] = ['text' => "📅 {$label}", 'callback_data' => "day:{$ref}:{$day['date']}"];

            if (count($row) === 2) {
                $inlineKeyboard[] = $row;
                $row = [];
            }
        }

        if (! empty($row)) {
            $inlineKeyboard[] = $row;
        }

        $inlineKeyboard[] = [
            ['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"],
        ];

        $title = $locale === 'en'
            ? "📅 <b>Change Booking Time</b>\n\nPlease select a new date for your visit:"
            : __('bot.reschedule_title', [], $locale);

        $this->telegram->sendMessage(
            $chatId,
            $title,
            ['inline_keyboard' => $inlineKeyboard]
        );
    }

    private function handlePickDay(string $queryId, int|string $chatId, string $ref, string $date, string $locale = 'en'): void
    {
        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_change_time']) || ($booking['status'] ?? '') === 'verified' || ($booking['status'] ?? '') === 'cancelled')) {
            $this->telegram->answerCallbackQuery($queryId, __('bot.action_not_allowed_verified', [], $locale), true);
            $this->telegram->sendMessage(
                $chatId,
                __('bot.action_not_allowed_verified', [], $locale),
                ['inline_keyboard' => [[['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $slotsData = $this->ccat->getAvailableSlots($ref);

        $daySlots = null;
        if ($slotsData && ! empty($slotsData['days'])) {
            foreach ($slotsData['days'] as $day) {
                if (($day['date'] ?? '') === $date) {
                    $daySlots = $day['slots'] ?? [];
                    break;
                }
            }
        }

        if (empty($daySlots)) {
            $this->telegram->answerCallbackQuery($queryId, 'No slots available for this date', true);

            return;
        }

        $this->telegram->answerCallbackQuery($queryId);

        $inlineKeyboard = [];
        $row = [];

        foreach ($daySlots as $time) {
            $timeLabel = substr((string) $time, 0, 5);
            $timeCode = str_replace(':', '-', $timeLabel);
            $row[] = ['text' => "⏰ {$timeLabel}", 'callback_data' => "res:{$ref}:{$date}:{$timeCode}"];

            if (count($row) === 3) {
                $inlineKeyboard[] = $row;
                $row = [];
            }
        }

        if (! empty($row)) {
            $inlineKeyboard[] = $row;
        }

        $inlineKeyboard[] = [
            ['text' => __('bot.btn_back_to_dates', [], $locale), 'callback_data' => "c_time:{$ref}"],
            ['text' => __('bot.btn_back_to_booking', [], $locale), 'callback_data' => "view:{$ref}"],
        ];

        $title = $locale === 'en'
            ? "⏰ <b>Select Time for {$date}</b>\n\nChoose an available slot:"
            : __('bot.reschedule_select_time', ['date' => $date], $locale);

        $this->telegram->sendMessage(
            $chatId,
            $title,
            ['inline_keyboard' => $inlineKeyboard]
        );
    }

    private function handleReschedule(string $queryId, int|string $chatId, string $ref, string $param1, string $param2, string $locale = 'en'): void
    {
        if ($param1 === 'prog') {
            $result = $this->ccat->rescheduleProgramme($ref, (int) $param2);
        } else {
            $time = trim(str_replace(['-', '.'], ':', $param2));
            if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
                $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            } elseif (preg_match('/^\d{1,2}$/', $time)) {
                $time = sprintf('%02d:00', (int) $time);
            }
            $result = $this->ccat->rescheduleBooking($ref, $param1, $time);
        }

        if ($result['success'] ?? false) {
            $this->telegram->answerCallbackQuery($queryId, 'Booking successfully updated!', true);

            $updated = $result['data'] ?? null;
            $dateFormatted = $this->escapeHtml((string) ($updated['date_formatted'] ?? $param1));
            $time = $this->escapeHtml((string) ($updated['time'] ?? ($param1 === 'prog' ? '' : $param2)));
            $building = $this->escapeHtml((string) ($updated['building'] ?? 'Centre for Contemporary Art Tashkent (Building B, 6 Amir Temur str., Tashkent)'));
            $eventTitle = ! empty($updated['event_title']) ? $this->escapeHtml((string) $updated['event_title']) : null;

            $lines = [
                '🎉 <b>Booking Successfully Updated!</b>',
                '',
                'Your reservation has been rescheduled:',
            ];

            if (! empty($eventTitle)) {
                $lines[] = "🎨 <b>Event:</b> {$eventTitle}";
            }

            $lines[] = "📅 <b>New Date:</b> {$dateFormatted}";

            if (! empty($time)) {
                $lines[] = "⏰ <b>New Time:</b> {$time}";
            }

            $lines[] = "📍 <b>Building:</b> {$building}";
            $lines[] = '';
            $lines[] = 'Here is your updated digital pass:';

            $this->telegram->sendMessage($chatId, implode("\n", $lines));

            $this->sendBookingPass($chatId, $ref, $locale);
        } else {
            $this->telegram->answerCallbackQuery($queryId, 'Rescheduling failed', true);
            $error = $this->escapeHtml((string) ($result['message'] ?? 'Could not reschedule to this slot.'));
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ <b>Update Failed</b>\n\n{$error}\n\nPlease choose another option.",
                ['inline_keyboard' => [[['text' => '📅 Choose Another Option', 'callback_data' => "c_time:{$ref}"]]]]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $booking
     */
    private function formatBookingCaption(array $booking): string
    {
        $type = (string) ($booking['type'] ?? 'visit');
        $isVerified = ($booking['status'] ?? '') === 'verified';
        $statusText = $isVerified ? 'Verified' : 'Confirmed';

        $first = trim((string) ($booking['first_name'] ?? ''));
        $last = trim((string) ($booking['last_name'] ?? ''));
        $name = $this->escapeHtml(trim("{$first} {$last}"));
        if (empty($name)) {
            $name = $this->escapeHtml((string) ($booking['name'] ?? ''));
        }

        $date = $this->escapeHtml((string) ($booking['date_formatted'] ?? ($booking['date'] ?? '')));
        $ref = $this->escapeHtml((string) ($booking['ref'] ?? substr((string) ($booking['token'] ?? ''), 0, 16)));
        $token = $this->escapeHtml((string) ($booking['token'] ?? ''));

        if ($type === 'programme') {
            $locales = ['en', 'uz', 'ru'];
            $blocks = array_map(
                fn (string $loc) => $this->formatProgrammeBlock($booking, $loc, $name, $token, $isVerified),
                $locales
            );

            return implode("\n\n~~~\n\n", $blocks);
        }

        if ($type === 'visit') {
            $locales = ['en', 'uz', 'ru'];
            $blocks = array_map(
                fn (string $loc) => $this->formatVisitBlock($booking, $loc, $name, $token, $isVerified),
                $locales
            );

            return implode("\n\n~~~\n\n", $blocks);
        }

        if ($type === 'library') {
            $locales = ['en', 'uz', 'ru'];
            $blocks = array_map(
                fn (string $loc) => $this->formatLibraryBlock($booking, $loc, $name, $token, $isVerified),
                $locales
            );

            return implode("\n\n~~~\n\n", $blocks);
        }

        $badge = $this->escapeHtml((string) ($booking['badge'] ?? 'Visit Pass'));
        $time = $this->escapeHtml((string) ($booking['time'] ?? ''));
        $building = $this->escapeHtml((string) ($booking['building'] ?? 'Centre for Contemporary Art Tashkent (Building B, 6 Amir Temur str., Tashkent)'));
        $status = $this->escapeHtml((string) ($booking['status_label'] ?? $statusText));

        $lines = [
            '🏛 <b>Centre for Contemporary Art Tashkent</b>',
            "🎟 <b>{$badge}</b>",
            '',
            "👤 <b>Visitor:</b> {$name}",
            "📅 <b>Date:</b> {$date}",
        ];

        if (! empty($time)) {
            $lines[] = "⏰ <b>Time:</b> {$time}";
        }

        $lines[] = "📍 <b>Building:</b> {$building}";

        if (! empty($booking['event_title'])) {
            $eventTitle = $this->escapeHtml((string) $booking['event_title']);
            $lines[] = "🎨 <b>Event:</b> {$eventTitle}";
        }

        if ($isVerified) {
            $lines[] = '📌 <b>Status:</b> ✅ Verified';
            $lines[] = "🔖 <b>Ref:</b> <code>{$token}</code>";
            $lines[] = '';
            $lines[] = '✅ <i>Admission verified at the entrance. Enjoy your visit!</i>';
        } else {
            $lines[] = "📌 <b>Status:</b> {$status}";
            $lines[] = "🔖 <b>Ref:</b> <code>{$token}</code>";
            $lines[] = '';
            $lines[] = '📲 <i>Show this QR code at the entrance for admission.</i>';
        }

        return implode("\n", $lines);
    }

    /**
     * Format a single language block for a programme booking pass using translation files.
     *
     * @param  array<string, mixed>  $booking
     */
    private function formatProgrammeBlock(array $booking, string $locale, string $name, string $token, bool $isVerified): string
    {
        $trans = (array) ($booking['translations'][$locale] ?? []);

        $header = __('pass.header', [], $locale);
        $passTitle = $isVerified
            ? __('pass.pass_title_verified', [], $locale)
            : __('pass.pass_title_confirmed', [], $locale);
        $statusText = $isVerified
            ? __('pass.status_verified', [], $locale)
            : __('pass.status_confirmed', [], $locale);
        $footer = $isVerified
            ? '✅ <i>'.__('pass.footer_verified', [], $locale).'</i>'
            : '📲 '.__('pass.footer_confirmed', [], $locale);

        $date = $this->escapeHtml((string) ($trans['date_formatted'] ?? ($booking['date_formatted'] ?? ($booking['date'] ?? ''))));

        $venue = (string) ($trans['event_venue'] ?? ($booking['event_venue'] ?? 'CCA Tashkent'));
        $bldg = (string) ($trans['building'] ?? '');
        if (empty($bldg)) {
            $bldg = ($venue === 'CCA Tashkent' || empty($venue))
                ? __('pass.building_default', [], $locale)
                : (string) ($booking['building'] ?? $venue);
        }
        $building = $this->escapeHtml($bldg);

        $eventName = (string) ($trans['event_title'] ?? ($booking['event_title'] ?? ''));
        $eventType = (string) ($trans['event_type'] ?? ($booking['event_type'] ?? ''));
        $eventDisplay = (! empty($eventName) && ! empty($eventType))
            ? "{$eventName} | {$eventType}"
            : ($eventName ?: $eventType);
        $eventDisplay = $this->escapeHtml($eventDisplay);

        $lines = [
            "🏛 <b>{$header}</b>",
            "🎟 <b>{$passTitle}</b>",
            '',
            '👤 <b>'.__('pass.visitor', [], $locale).":</b> {$name}",
            '📅 <b>'.__('pass.date', [], $locale).":</b> {$date}",
        ];

        $time = trim((string) ($booking['time'] ?? ''));
        if (! empty($time) && $time !== '00:00') {
            $lines[] = '🕒 <b>'.__('pass.time', [], $locale).':</b> '.$this->escapeHtml($time);
        }

        $lines[] = '📍 <b>'.__('pass.building', [], $locale).":</b> {$building}";
        if (! empty($eventDisplay)) {
            $lines[] = '🎨 <b>'.__('pass.event', [], $locale).":</b> {$eventDisplay}";
        }
        $lines[] = '✅ <b>'.__('pass.status', [], $locale).":</b> {$statusText}";
        $lines[] = '🔖 <b>'.__('pass.ref', [], $locale).":</b> <code>{$token}</code>";
        $lines[] = '';
        $lines[] = $footer;

        return implode("\n", $lines);
    }

    /**
     * Format a single language block for a general visit booking pass using translation files.
     *
     * @param  array<string, mixed>  $booking
     */
    private function formatVisitBlock(array $booking, string $locale, string $name, string $token, bool $isVerified): string
    {
        $trans = (array) ($booking['translations'][$locale] ?? []);

        $header = __('pass.visit_header', [], $locale);
        $passTitle = $isVerified
            ? __('pass.visit_pass_title_verified', [], $locale)
            : __('pass.visit_pass_title_confirmed', [], $locale);
        $statusText = $isVerified
            ? __('pass.status_verified', [], $locale)
            : __('pass.status_confirmed', [], $locale);
        $footer = $isVerified
            ? '✅ <i>'.__('pass.visit_footer_verified', [], $locale).'</i>'
            : '📲 '.__('pass.visit_footer_confirmed', [], $locale);

        $date = $this->escapeHtml((string) ($trans['date_formatted'] ?? ($booking['date_formatted'] ?? ($booking['date'] ?? ''))));
        $time = $this->escapeHtml((string) ($booking['time'] ?? ''));

        $bldg = __('pass.visit_building_default', [], $locale);

        $building = $this->escapeHtml($bldg);
        $mapUrl = 'https://yandex.com/maps/org/toshkent_zamonaviy_san_at_markazi/137769933130?si=6rjq924p4qvrv7t8abkdnqzef0';
        $escapedMapUrl = htmlspecialchars($mapUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $buildingDisplay = "<a href=\"{$escapedMapUrl}\">{$building}</a>";
        $lines = [
            "🏛 <b>{$header}</b>",
            "🎟 <b>{$passTitle}</b>",
            '',
            '👤 <b>'.__('pass.visitor', [], $locale).":</b> {$name}",
            '📅 <b>'.__('pass.date', [], $locale).":</b> {$date}",
        ];

        if (! empty($time)) {
            $lines[] = '⏰ <b>'.__('pass.time', [], $locale).":</b> {$time}";
        }

        $lines[] = '📍 <b>'.__('pass.visit_building', [], $locale).":</b> {$buildingDisplay}";
        $lines[] = '✅ <b>'.__('pass.status', [], $locale).":</b> {$statusText}";
        $lines[] = '🔖 <b>'.__('pass.ref', [], $locale).":</b> <code>{$token}</code>";
        $lines[] = '';
        $lines[] = $footer;

        return implode("\n", $lines);
    }

    /**
     * Format a single language block for a library booking pass using translation files.
     *
     * @param  array<string, mixed>  $booking
     */
    private function formatLibraryBlock(array $booking, string $locale, string $name, string $token, bool $isVerified): string
    {
        $trans = (array) ($booking['translations'][$locale] ?? []);

        $header = __('pass.library_header', [], $locale);
        $passTitle = $isVerified
            ? __('pass.library_pass_title_verified', [], $locale)
            : __('pass.library_pass_title_confirmed', [], $locale);
        $statusText = $isVerified
            ? __('pass.status_verified', [], $locale)
            : __('pass.status_confirmed', [], $locale);
        $footer = $isVerified
            ? '✅ <i>'.__('pass.library_footer_verified', [], $locale).'</i>'
            : '📲 '.__('pass.library_footer_confirmed', [], $locale);

        $date = $this->escapeHtml((string) ($trans['date_formatted'] ?? ($booking['date_formatted'] ?? ($booking['date'] ?? ''))));
        $time = $this->escapeHtml((string) ($booking['time'] ?? ''));

        $bldg = (string) ($trans['building'] ?? '');
        if (empty($bldg)) {
            $bldg = __('pass.library_location_default', [], $locale);
        }
        $building = $this->escapeHtml($bldg);

        $lines = [
            "🏛 <b>{$header}</b>",
            "🎟 <b>{$passTitle}</b>",
            '',
            '👤 <b>'.__('pass.visitor', [], $locale).":</b> {$name}",
            '📅 <b>'.__('pass.date', [], $locale).":</b> {$date}",
        ];

        if (! empty($time)) {
            $lines[] = '⏰ <b>'.__('pass.time', [], $locale).":</b> {$time}";
        }

        $lines[] = '📍 <b>'.__('pass.library_location', [], $locale).":</b> {$building}";
        $lines[] = '✅ <b>'.__('pass.status', [], $locale).":</b> {$statusText}";
        $lines[] = '🔖 <b>'.__('pass.ref', [], $locale).":</b> <code>{$token}</code>";
        $lines[] = '';
        $lines[] = $footer;

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBookingKeyboard(string $ref, bool $canChangeTime = true, bool $canCancel = true, string $locale = 'en'): ?array
    {
        $buttons = [];
        if ($canChangeTime) {
            $buttons[] = ['text' => __('bot.btn_change_time', [], $locale), 'callback_data' => "c_time:{$ref}"];
        }
        if ($canCancel) {
            $buttons[] = ['text' => __('bot.btn_cancel_booking', [], $locale), 'callback_data' => "c_conf:{$ref}"];
        }

        $rows = [];
        if (! empty($buttons)) {
            $rows[] = $buttons;
        }

        $rows[] = [
            ['text' => __('bot.btn_my_bookings', [], $locale), 'callback_data' => 'my_bookings'],
        ];

        return [
            'inline_keyboard' => $rows,
        ];
    }

    private function handleMyBookings(int|string $chatId, string $locale = 'en', ?string $queryId = null): void
    {
        if ($queryId !== null) {
            $this->telegram->answerCallbackQuery($queryId);
        }

        $bookings = $this->ccat->getTelegramBookings($chatId, 'upcoming');

        if (empty($bookings)) {
            $this->telegram->sendMessage(
                $chatId,
                __('bot.my_bookings_empty', [], $locale),
                $this->getPersistentReplyMarkup($locale)
            );

            return;
        }

        $this->renderBookingsList($chatId, $bookings, $locale);
    }

    /**
     * @param  array<int, array<string, mixed>>  $bookings
     */
    private function renderBookingsList(int|string $chatId, array $bookings, string $locale): void
    {
        $lines = [
            __('bot.my_bookings_list', [], $locale),
            '',
        ];

        $buttons = [];

        foreach ($bookings as $idx => $b) {
            $ref = (string) ($b['ref'] ?? substr((string) ($b['token'] ?? ''), 0, 16));
            $type = (string) ($b['type'] ?? 'visit');
            $typeEmoji = match ($type) {
                'programme' => '🎭',
                'library' => '📚',
                default => '🏛',
            };
            $date = (string) ($b['date_formatted'] ?? ($b['date'] ?? ''));
            $time = ! empty($b['time']) ? " {$b['time']}" : '';
            $title = ! empty($b['event_title']) ? " — {$b['event_title']}" : '';
            $visitor = ! empty($b['name']) ? " ({$b['name']})" : '';

            $lines[] = sprintf('%d. %s <b>%s</b>%s%s', $idx + 1, $typeEmoji, $date.$time, $title, $visitor);

            $btnLabel = sprintf('%s %s%s', $typeEmoji, $date, $time);
            $buttons[] = [
                ['text' => mb_substr($btnLabel, 0, 36), 'callback_data' => "view:{$ref}"],
            ];
        }

        $keyboard = ['inline_keyboard' => $buttons];

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $lines),
            $keyboard
        );
    }

    private function sendWelcomeMessage(int|string $chatId, string $locale = 'en'): void
    {
        $header = $locale === 'en' ? 'Welcome to CCAT Booking Bot!' : __('bot.welcome_header', [], $locale);
        $text = "🏛 <b>{$header}</b>\n\n".__('bot.welcome_body', [], $locale);

        $this->telegram->sendMessage(
            $chatId,
            $text,
            $this->getPersistentReplyMarkup($locale)
        );
    }

    private function sendLanguageSelection(int|string $chatId): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru'],
                    ['text' => '🇺🇿 O‘zbekcha', 'callback_data' => 'lang:uz'],
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang:en'],
                ],
            ],
        ];

        $this->telegram->sendMessage(
            $chatId,
            '🌐 <b>Choose language / Выберите язык / Tilni tanlang:</b>',
            $keyboard
        );
    }

    private function handleSetLanguage(string $queryId, int|string $chatId, string $newLang): void
    {
        $locale = $this->normalizeLanguageCode($newLang);
        try {
            Cache::put("tg_lang_{$chatId}", $locale, now()->addDays(90));
        } catch (\Throwable) {
        }

        $this->ccat->updateTelegramLanguage($chatId, $locale);

        $alert = match ($locale) {
            'ru' => '✅ Язык изменён на Русский',
            'uz' => '✅ Til O‘zbekchaga o‘zgartirildi',
            default => '✅ Language changed to English',
        };

        $this->telegram->answerCallbackQuery($queryId, $alert, true);

        $msg = match ($locale) {
            'ru' => "✅ <b>Язык успешно изменён на Русский.</b>\n\nИспользуйте меню ниже для навигации.",
            'uz' => "✅ <b>Til muvaffaqiyatli O‘zbekchaga o‘zgartirildi.</b>\n\nQuyidagi menyudan foydalanishingiz mumkin.",
            default => "✅ <b>Language successfully changed to English.</b>\n\nYou can use the menu below to navigate.",
        };

        $this->telegram->sendMessage(
            $chatId,
            $msg,
            $this->getPersistentReplyMarkup($locale)
        );
    }

    private function resolveLanguageCode(int|string $chatId, ?string $telegramLang): string
    {
        try {
            $cached = Cache::get("tg_lang_{$chatId}");
            if ($cached && in_array($cached, ['ru', 'uz', 'en'], true)) {
                return $cached;
            }
        } catch (\Throwable) {
        }

        return $this->normalizeLanguageCode($telegramLang);
    }

    private function normalizeLanguageCode(?string $code): string
    {
        if (empty($code)) {
            return 'en';
        }

        $short = strtolower(substr(trim($code), 0, 2));

        return in_array($short, ['uz', 'ru'], true) ? $short : 'en';
    }

    /**
     * @return array<string, mixed>
     */
    private function getPersistentReplyMarkup(string $locale): array
    {
        $label = __('bot.menu_my_bookings', [], $locale);
        $langBtn = match ($locale) {
            'ru' => '🌐 Язык',
            'uz' => '🌐 Til',
            default => '🌐 Language',
        };

        return [
            'keyboard' => [
                [
                    ['text' => $label],
                    ['text' => $langBtn],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function escapeHtml(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $clean = strip_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
