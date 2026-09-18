<?php

declare(strict_types=1);

namespace App\Services;

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

        if (str_starts_with($text, '/start')) {
            $parts = explode(' ', $text, 2);
            $token = isset($parts[1]) ? trim($parts[1]) : '';

            if (! empty($token)) {
                $this->sendBookingPass($chatId, $token);

                return;
            }

            $this->sendWelcomeMessage($chatId);

            return;
        }

        if ($text === '/help') {
            $this->sendWelcomeMessage($chatId);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "👋 Hello! To view your booking QR code and pass details, please use the <b>\"Get QR code via Telegram\"</b> button on your CCAT booking confirmation page.\n\nVisit <a href=\"https://ccat.uz\">ccat.uz</a> for information and bookings."
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

        if (! $chatId) {
            $this->telegram->answerCallbackQuery($queryId);

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

            $this->handleReschedule($queryId, $chatId, $ref, $param1, $param2);

            return;
        }

        match ($action) {
            'view' => $this->handleViewPass($queryId, $chatId, $ref),
            'c_conf', 'cancel_confirm' => $this->handleCancelConfirm($queryId, $chatId, $ref),
            'c_do', 'cancel_do' => $this->handleCancelDo($queryId, $chatId, $ref),
            'c_time', 'change_time' => $this->handleChangeTime($queryId, $chatId, $ref),
            'day', 'pick_day' => $this->handlePickDay($queryId, $chatId, $ref, $parts[2] ?? ''),
            default => $this->telegram->answerCallbackQuery($queryId),
        };
    }

    private function sendBookingPass(int|string $chatId, string $token): void
    {
        $booking = $this->ccat->getBooking($token);

        if (! $booking) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ <b>Booking Not Found</b>\n\nWe could not find an active booking matching reference <code>".$this->escapeHtml($token)."</code>.\n\nPlease check your confirmation on <a href=\"https://ccat.uz\">ccat.uz</a>."
            );

            return;
        }

        $ref = (string) ($booking['ref'] ?? substr((string) ($booking['token'] ?? $token), 0, 16));
        $canChangeTime = ! empty($booking['can_change_time']) && (($booking['status'] ?? '') !== 'verified');
        $canCancel = ! empty($booking['can_cancel']) && (($booking['status'] ?? '') !== 'verified');
        $caption = $this->formatBookingCaption($booking);
        $keyboard = $this->buildBookingKeyboard($ref, $canChangeTime, $canCancel);

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

    private function handleViewPass(string $queryId, int|string $chatId, string $ref): void
    {
        $this->telegram->answerCallbackQuery($queryId);
        $this->sendBookingPass($chatId, $ref);
    }

    private function handleCancelConfirm(string $queryId, int|string $chatId, string $ref): void
    {
        $this->telegram->answerCallbackQuery($queryId);

        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_cancel']) || ($booking['status'] ?? '') === 'verified')) {
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be cancelled.",
                ['inline_keyboard' => [[['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $name = $booking ? ' ('.$this->escapeHtml((string) ($booking['name'] ?? '')).')' : '';

        $text = "⚠️ <b>Cancel Booking{$name}?</b>\n\n".
            "Are you sure you want to cancel your reservation?\n".
            'Your reserved slot will be freed immediately and cannot be held.';

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Yes, Cancel Booking', 'callback_data' => "c_do:{$ref}"],
                    ['text' => '🔙 Keep Booking', 'callback_data' => "view:{$ref}"],
                ],
            ],
        ];

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    private function handleCancelDo(string $queryId, int|string $chatId, string $ref): void
    {
        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_cancel']) || ($booking['status'] ?? '') === 'verified')) {
            $this->telegram->answerCallbackQuery($queryId, 'Verified bookings cannot be cancelled', true);
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be cancelled.",
                ['inline_keyboard' => [[['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $result = $this->ccat->cancelBooking($ref);

        if ($result['success'] ?? false) {
            $this->telegram->answerCallbackQuery($queryId, 'Booking cancelled successfully', true);

            $text = "✅ <b>Booking Cancelled</b>\n\n".
                "Your reservation has been cancelled and your place has been released.\n\n".
                'If you would like to book a visit at another time, please register at <a href="https://ccat.uz">ccat.uz</a>.';

            $this->telegram->sendMessage($chatId, $text);
        } else {
            $this->telegram->answerCallbackQuery($queryId, 'Error cancelling booking', true);
            $error = $this->escapeHtml((string) ($result['message'] ?? 'Could not cancel booking.'));
            $this->telegram->sendMessage($chatId, "⚠️ <b>Cancellation Failed</b>\n\n{$error}");
        }
    }

    private function handleChangeTime(string $queryId, int|string $chatId, string $ref): void
    {
        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_change_time']) || ($booking['status'] ?? '') === 'verified')) {
            $this->telegram->answerCallbackQuery($queryId, 'Verified bookings cannot be changed', true);
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be rescheduled.",
                ['inline_keyboard' => [[['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"]]]]
            );

            return;
        }

        $slotsData = $this->ccat->getAvailableSlots($ref);

        if (! $slotsData || empty($slotsData['days'])) {
            $msg = $slotsData['message'] ?? 'No available slots found';
            $this->telegram->answerCallbackQuery($queryId, $msg, true);
            $this->telegram->sendMessage(
                $chatId,
                "ℹ️ <b>Cannot Change Time</b>\n\n".$this->escapeHtml((string) ($slotsData['message'] ?? 'There are currently no open slots available for rescheduling. Please check back later or visit ccat.uz.')),
                ['inline_keyboard' => [[['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"]]]]
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
            $inlineKeyboard[] = [['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"]];

            $this->telegram->sendMessage(
                $chatId,
                '📅 <b>Select an Upcoming Programme:</b>',
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
            ['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"],
        ];

        $this->telegram->sendMessage(
            $chatId,
            "📅 <b>Change Booking Time</b>\n\nPlease select a new date for your visit:",
            ['inline_keyboard' => $inlineKeyboard]
        );
    }

    private function handlePickDay(string $queryId, int|string $chatId, string $ref, string $date): void
    {
        $booking = $this->ccat->getBooking($ref);
        if ($booking && (empty($booking['can_change_time']) || ($booking['status'] ?? '') === 'verified')) {
            $this->telegram->answerCallbackQuery($queryId, 'Verified bookings cannot be changed', true);
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be rescheduled.",
                ['inline_keyboard' => [[['text' => '🔙 Back to Booking', 'callback_data' => "view:{$ref}"]]]]
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
            ['text' => '🔙 Back to Dates', 'callback_data' => "c_time:{$ref}"],
            ['text' => '❌ Cancel', 'callback_data' => "view:{$ref}"],
        ];

        $this->telegram->sendMessage(
            $chatId,
            "⏰ <b>Select Time for {$date}</b>\n\nChoose an available slot:",
            ['inline_keyboard' => $inlineKeyboard]
        );
    }

    private function handleReschedule(string $queryId, int|string $chatId, string $ref, string $param1, string $param2): void
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
            $building = $this->escapeHtml((string) ($updated['building'] ?? 'Center for Contemporary Art Tashkent (Building B, 6 Amir Temur str., Tashkent)'));
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

            $this->sendBookingPass($chatId, $ref);
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
        $building = $this->escapeHtml((string) ($booking['building'] ?? 'Center for Contemporary Art Tashkent (Building B, 6 Amir Temur str., Tashkent)'));
        $status = $this->escapeHtml((string) ($booking['status_label'] ?? $statusText));

        $lines = [
            '🏛 <b>Center for Contemporary Art Tashkent</b>',
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

        $bldg = (string) ($trans['building'] ?? '');
        if (empty($bldg)) {
            $bldg = __('pass.visit_building_default', [], $locale);
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

        $lines[] = '📍 <b>'.__('pass.visit_building', [], $locale).":</b> {$building}";
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
    private function buildBookingKeyboard(string $ref, bool $canChangeTime = true, bool $canCancel = true): ?array
    {
        $buttons = [];
        if ($canChangeTime) {
            $buttons[] = ['text' => '📅 Change Time', 'callback_data' => "c_time:{$ref}"];
        }
        if ($canCancel) {
            $buttons[] = ['text' => '❌ Cancel Booking', 'callback_data' => "c_conf:{$ref}"];
        }

        if (empty($buttons)) {
            return null;
        }

        return [
            'inline_keyboard' => [
                $buttons,
            ],
        ];
    }

    private function sendWelcomeMessage(int|string $chatId): void
    {
        $text = "🏛 <b>Welcome to CCAT Booking Bot!</b>\n\n".
            "This bot provides instant access to your <b>Center for Contemporary Art Tashkent</b> digital QR entrance passes.\n\n".
            "✨ <b>Features:</b>\n".
            "• 🎟 Instant QR Code pass in PNG format\n".
            "• 📅 Change your visit or reading room booking time\n".
            "• ❌ Cancel your booking anytime with one tap\n".
            "• 🌐 Works for all CCAT bookings: General Visits, Library, and Programme Events\n\n".
            "📲 <b>To get started:</b>\n".
            'Book your slot on <a href="https://ccat.uz">ccat.uz</a> and tap <b>"Get QR code via Telegram"</b> on your confirmation popup!';

        $this->telegram->sendMessage($chatId, $text);
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
