<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    public function test_service_health_dashboard_returns_active_status(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('bot_username', 'ccat_booking_bot');
    }

    public function test_webhook_endpoint_handles_start_with_booking_token(): void
    {
        $token = 'test_token_1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        $chatId = 12345678;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'visit',
                    'badge' => 'Visit Pass',
                    'token' => $token,
                    'name' => 'Alisher Navoiy',
                    'first_name' => 'Alisher',
                    'last_name' => 'Navoiy',
                    'date' => '2026-09-18',
                    'date_formatted' => '18 September 2026',
                    'time' => '10:00',
                    'building' => 'Building B, 6 Amir Temur str., Tashkent',
                    'status' => 'pending',
                    'status_label' => 'Pending',
                    'qr_png_base64' => base64_encode('fake-png-bytes'),
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 1001,
            'message' => [
                'message_id' => 50,
                'from' => ['id' => $chatId, 'first_name' => 'Alisher'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($chatId) {
            return str_contains($request->url(), 'sendPhoto')
                && str_contains($request->body(), (string) $chatId);
        });
    }

    public function test_webhook_endpoint_handles_cancel_booking_callback(): void
    {
        $token = 'test_token_cancel_123';
        $chatId = 87654321;
        $queryId = 'query_abc_123';

        Http::fake([
            "*/booking/{$token}/cancel" => Http::response([
                'success' => true,
                'message' => 'Booking has been successfully cancelled.',
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 1002,
            'callback_query' => [
                'id' => $queryId,
                'from' => ['id' => $chatId, 'first_name' => 'User'],
                'message' => [
                    'message_id' => 60,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "cancel_do:{$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($queryId) {
            return str_contains($request->url(), 'answerCallbackQuery')
                && $request['callback_query_id'] === $queryId;
        });

        Http::assertSent(function ($request) use ($chatId) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] == $chatId
                && str_contains($request['text'], 'Cancelled');
        });
    }

    public function test_webhook_endpoint_handles_change_time_callback(): void
    {
        $token = 'test_token_reschedule_123';
        $chatId = 87654321;
        $queryId = 'query_slots_123';

        Http::fake([
            "*/booking/{$token}/available-slots" => Http::response([
                'success' => true,
                'type' => 'visit',
                'days' => [
                    [
                        'date' => '2026-09-19',
                        'label' => 'Sat, 19 Sep',
                        'slots' => ['10:00', '11:00', '14:00'],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 1003,
            'callback_query' => [
                'id' => $queryId,
                'from' => ['id' => $chatId, 'first_name' => 'User'],
                'message' => [
                    'message_id' => 70,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "change_time:{$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($chatId) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] == $chatId
                && str_contains($request['text'], 'Change Booking Time');
        });
    }

    public function test_webhook_handles_start_without_token_with_welcome_guide(): void
    {
        $chatId = 999999;

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 1004,
            'message' => [
                'message_id' => 80,
                'from' => ['id' => $chatId, 'first_name' => 'Visitor'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => '/start',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($chatId) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] == $chatId
                && str_contains($request['text'], 'Welcome to CCAT Booking Bot');
        });
    }

    public function test_webhook_handles_reschedule_callback_and_sends_correct_time(): void
    {
        $ref = 'phH9S0S5Sv7zom7y';
        $chatId = 12345678;
        $queryId = 'query_res_123';

        Http::fake([
            "*/booking/{$ref}/reschedule" => Http::response([
                'success' => true,
                'message' => 'Booking has been successfully updated.',
                'data' => [
                    'date_formatted' => '25 September 2026',
                    'time' => '18:00',
                    'building' => 'Building B, 6 Amir Temur str., Tashkent',
                ],
            ], 200),
            "*/booking/{$ref}" => Http::response([
                'success' => true,
                'data' => [
                    'ref' => $ref,
                    'token' => $ref.'extra123456',
                    'name' => 'Fayzullo Abdukhakimov',
                    'first_name' => 'Fayzullo',
                    'last_name' => 'Abdukhakimov',
                    'date_formatted' => '25 September 2026',
                    'time' => '18:00',
                    'status_label' => 'Pending',
                    'building' => 'Building B, 6 Amir Temur str., Tashkent',
                    'qr_png_base64' => base64_encode('fake-qr-bytes'),
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config([
            'telegram.bot_token' => 'fake_bot_token',
            'telegram.ccat_api_token' => 'test-sanctum-token-xyz',
        ]);

        $update = [
            'update_id' => 1005,
            'callback_query' => [
                'id' => $queryId,
                'from' => ['id' => $chatId, 'first_name' => 'Fayzullo'],
                'message' => [
                    'message_id' => 90,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "res:{$ref}:2026-09-25:18-00",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($ref) {
            if (str_contains($request->url(), "/booking/{$ref}/reschedule")) {
                $body = json_decode($request->body(), true);

                return ($body['time'] ?? null) === '18:00'
                    && ($body['date'] ?? null) === '2026-09-25'
                    && $request->hasHeader('Authorization', 'Bearer test-sanctum-token-xyz');
            }

            return false;
        });

        $updateLegacy = [
            'update_id' => 1006,
            'callback_query' => [
                'id' => 'query_legacy_456',
                'from' => ['id' => $chatId, 'first_name' => 'Fayzullo'],
                'message' => [
                    'message_id' => 91,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "res:{$ref}:2026-09-25:18:00",
            ],
        ];

        $responseLegacy = $this->postJson(route('telegram.webhook'), $updateLegacy);

        $responseLegacy->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($ref) {
            if (str_contains($request->url(), "/booking/{$ref}/reschedule")) {
                $body = json_decode($request->body(), true);

                return ($body['time'] ?? null) === '18:00';
            }

            return false;
        });
    }

    public function test_webhook_handles_programme_booking_and_strips_html_tags(): void
    {
        $token = 'AlDsLQpI86uQIhEQ0RCgEcGCGqmOssmG7UuSP7RZ0U2oP9H5i9qIpZTI9ESujQqI';
        $chatId = 12345678;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'programme',
                    'badge' => 'Programme Event Pass',
                    'token' => $token,
                    'ref' => substr($token, 0, 16),
                    'name' => 'Fayzullo Abdukhakimov',
                    'first_name' => 'Fayzullo',
                    'last_name' => 'Abdukhakimov',
                    'date' => '2026-09-06',
                    'date_formatted' => '06 September 2026 — 31 January 2027',
                    'time' => '',
                    'building' => 'CCA Tashkent (Building B, 6 Amir Temur str., Tashkent)',
                    'event_title' => '<p>HIKMAH</p>',
                    'event_type' => 'Exhibition',
                    'event_venue' => 'CCA Tashkent',
                    'status' => 'confirmed',
                    'status_label' => 'Confirmed',
                    'qr_png_base64' => base64_encode('fake-programme-qr'),
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 1007,
            'message' => [
                'message_id' => 101,
                'from' => ['id' => $chatId, 'first_name' => 'Fayzullo'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });

        Http::assertSent(function ($request) use ($token) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = (string) ($request['text'] ?? '');

                return str_contains($text, 'Centre for Contemporary Arts Tashkent (CCA Tashkent)')
                    && str_contains($text, 'Programme Event Pass — Confirmed')
                    && str_contains($text, '<b>Visitor:</b> Fayzullo Abdukhakimov')
                    && str_contains($text, '<b>Building:</b> CCA Tashkent (Building B, 6 Amir Temur str., Tashkent)')
                    && str_contains($text, '<b>Event:</b> HIKMAH | Exhibition')
                    && str_contains($text, '<b>Status:</b> Confirmed')
                    && str_contains($text, "<b>Ref:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Show this QR code at the entrance for admission.')
                    && str_contains($text, '~~~')
                    && str_contains($text, 'Toshkent Zamonaviy san’at markazi')
                    && str_contains($text, 'Dastur tadbiri chiptasi – Tasdiqlangan')
                    && str_contains($text, '<b>Mehmon:</b> Fayzullo Abdukhakimov')
                    && str_contains($text, '<b>Bino:</b> CCA Tashkent (Toshkent shahri, Amir Temur ko‘chasi, 6-uy, B bino)')
                    && str_contains($text, '<b>Holati:</b> Tasdiqlangan')
                    && str_contains($text, "<b>Iqt:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Ushbu QR-kodni kirishda taqdim eting.')
                    && str_contains($text, 'Центр современного искусства в Ташкенте (CCA Tashkent)')
                    && str_contains($text, 'Пропуск на мероприятие — регистрация подтверждена')
                    && str_contains($text, '<b>Посетитель:</b> Fayzullo Abdukhakimov')
                    && str_contains($text, '<b>Место проведения:</b> CCA Tashkent (Здание B, ул. Амира Темура, 6, Ташкент)')
                    && str_contains($text, '<b>Статус:</b> Регистрация подтверждена')
                    && str_contains($text, "<b>Номер регистрации:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Для прохода на мероприятие предъявите QR-код на входе.')
                    && ! str_contains($text, '<p>')
                    && ! str_contains($text, '</p>')
                    && ! str_contains($text, '<b>Time:</b>');
            }

            return false;
        });
    }

    public function test_webhook_handles_verified_programme_booking(): void
    {
        $token = 'AlDsLQpI86uQIhEQ0RCgEcGCGqmOssmG7UuSP7RZ0U2oP9H5i9qIpZTI9ESujQqI';
        $chatId = 12345678;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'programme',
                    'badge' => 'Programme Event Pass',
                    'token' => $token,
                    'ref' => substr($token, 0, 16),
                    'name' => 'Fayzullo Abdukhakimov',
                    'first_name' => 'Fayzullo',
                    'last_name' => 'Abdukhakimov',
                    'date' => '2026-09-06',
                    'date_formatted' => '06 September 2026 — 31 January 2027',
                    'time' => '',
                    'building' => 'CCA Tashkent (Building B, 6 Amir Temur str., Tashkent)',
                    'event_title' => 'HIKMAH',
                    'event_type' => 'Exhibition',
                    'event_venue' => 'CCA Tashkent',
                    'status' => 'verified',
                    'status_label' => 'Verified',
                    'qr_png_base64' => base64_encode('fake-programme-qr'),
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 1008,
            'message' => [
                'message_id' => 102,
                'from' => ['id' => $chatId, 'first_name' => 'Fayzullo'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });

        Http::assertSent(function ($request) use ($token) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = (string) ($request['text'] ?? '');

                return str_contains($text, 'Programme Event Pass — Verified')
                    && str_contains($text, '<b>Status:</b> Verified')
                    && str_contains($text, "<b>Ref:</b> <code>{$token}</code>")
                    && str_contains($text, '✅ <i>Admission verified at the entrance. Enjoy your visit!</i>')
                    && str_contains($text, 'Dastur tadbiri chiptasi – Tekshirilgan')
                    && str_contains($text, '<b>Holati:</b> Tekshirilgan')
                    && str_contains($text, "<b>Iqt:</b> <code>{$token}</code>")
                    && str_contains($text, '✅ <i>Kirishda tasdiqlangan. Tashrifingiz maroqli o‘tsin!</i>')
                    && str_contains($text, 'Пропуск на мероприятие — проверено')
                    && str_contains($text, '<b>Статус:</b> Проверено')
                    && str_contains($text, "<b>Номер регистрации:</b> <code>{$token}</code>")
                    && str_contains($text, '✅ <i>Вход подтвержден. Приятного визита!</i>');
            }

            return false;
        });
    }

    public function test_webhook_handles_programme_reschedule_callback(): void
    {
        $ref = 'AlDsLQpI86uQIhEQ';
        $chatId = 12345678;
        $queryId = 'query_prog_res_123';

        Http::fake([
            "*/booking/{$ref}/reschedule" => Http::response([
                'success' => true,
                'message' => 'Booking has been successfully updated.',
                'data' => [
                    'event_title' => '<p>New Programme Title</p>',
                    'date_formatted' => '01 October 2026',
                    'time' => '',
                    'building' => 'Centre for Contemporary Art Tashkent',
                ],
            ], 200),
            "*/booking/{$ref}" => Http::response([
                'success' => true,
                'data' => [
                    'ref' => $ref,
                    'token' => $ref.'extra123',
                    'name' => 'Fayzullo Abdukhakimov',
                    'first_name' => 'Fayzullo',
                    'last_name' => 'Abdukhakimov',
                    'date_formatted' => '01 October 2026',
                    'time' => '',
                    'status_label' => 'Pending',
                    'building' => 'Centre for Contemporary Art Tashkent',
                    'event_title' => 'New Programme Title',
                    'qr_png_base64' => base64_encode('fake-qr'),
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config([
            'telegram.bot_token' => 'fake_bot_token',
            'telegram.ccat_api_token' => 'test-token',
        ]);

        $update = [
            'update_id' => 1008,
            'callback_query' => [
                'id' => $queryId,
                'from' => ['id' => $chatId, 'first_name' => 'Fayzullo'],
                'message' => [
                    'message_id' => 102,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "res:{$ref}:prog:2",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) use ($ref) {
            if (str_contains($request->url(), "/booking/{$ref}/reschedule")) {
                $body = json_decode($request->body(), true);

                return ($body['programme_id'] ?? null) === 2;
            }

            return false;
        });

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request['text'] ?? '';
                if (str_contains($text, 'Booking Successfully Updated!')) {
                    return str_contains($text, 'New Programme Title')
                        && ! str_contains($text, '<p>');
                }
            }

            return false;
        });
    }

    public function test_verified_booking_has_no_action_buttons_and_blocks_reschedule_and_cancel(): void
    {
        $token = 'verified_token_1234567890abcdef1234567890abcdef1234567890abcdef1234';
        $ref = substr($token, 0, 16);
        $chatId = 888888;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'visit',
                    'badge' => 'Visit Pass',
                    'token' => $token,
                    'ref' => $ref,
                    'name' => 'Alisher Navoiy',
                    'first_name' => 'Alisher',
                    'last_name' => 'Navoiy',
                    'date' => '2026-09-17',
                    'date_formatted' => '17 September 2026',
                    'time' => '10:00',
                    'building' => 'Building B, 6 Amir Temur str., Tashkent',
                    'status' => 'verified',
                    'status_label' => 'Verified',
                    'can_change_time' => false,
                    'can_cancel' => false,
                    'qr_png_base64' => base64_encode('fake-verified-qr'),
                ],
            ], 200),
            "*/booking/{$ref}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'visit',
                    'badge' => 'Visit Pass',
                    'token' => $token,
                    'ref' => $ref,
                    'name' => 'Alisher Navoiy',
                    'first_name' => 'Alisher',
                    'last_name' => 'Navoiy',
                    'date' => '2026-09-17',
                    'date_formatted' => '17 September 2026',
                    'time' => '10:00',
                    'building' => 'Building B, 6 Amir Temur str., Tashkent',
                    'status' => 'verified',
                    'status_label' => 'Verified',
                    'can_change_time' => false,
                    'can_cancel' => false,
                    'qr_png_base64' => base64_encode('fake-verified-qr'),
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $updateStart = [
            'update_id' => 2001,
            'message' => [
                'message_id' => 201,
                'from' => ['id' => $chatId, 'first_name' => 'Alisher'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $updateStart);
        $response->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = (string) ($request['text'] ?? '');
                $body = $request->body();

                return str_contains($text, 'Verified')
                    && str_contains($text, 'Admission verified at the entrance')
                    && ! str_contains($body, 'Change Time')
                    && ! str_contains($body, 'Cancel Booking');
            }

            return false;
        });

        $updateChangeTime = [
            'update_id' => 2002,
            'callback_query' => [
                'id' => 'query_time_reject',
                'from' => ['id' => $chatId, 'first_name' => 'Alisher'],
                'message' => [
                    'message_id' => 202,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "c_time:{$ref}",
            ],
        ];

        $resChange = $this->postJson(route('telegram.webhook'), $updateChangeTime);
        $resChange->assertOk();

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request['text'] ?? '';

                return str_contains($text, 'Action Not Allowed')
                    && str_contains($text, 'already been verified');
            }

            return false;
        });

        $updateCancel = [
            'update_id' => 2003,
            'callback_query' => [
                'id' => 'query_cancel_reject',
                'from' => ['id' => $chatId, 'first_name' => 'Alisher'],
                'message' => [
                    'message_id' => 203,
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                ],
                'data' => "c_conf:{$ref}",
            ],
        ];

        $resCancel = $this->postJson(route('telegram.webhook'), $updateCancel);
        $resCancel->assertOk();

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request['text'] ?? '';

                return str_contains($text, 'Action Not Allowed')
                    && str_contains($text, 'already been verified and attendance recorded. It cannot be cancelled');
            }

            return false;
        });
    }

    public function test_webhook_handles_programme_booking_with_rich_translations_and_time(): void
    {
        $token = '7890abcdef1234567890abcdef1234567890abcdef1234567890abcdef12345678';
        $chatId = 99887766;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'programme',
                    'badge' => 'Programme Event Pass',
                    'token' => $token,
                    'ref' => substr($token, 0, 16),
                    'name' => 'Dilshod Rahmatov',
                    'first_name' => 'Dilshod',
                    'last_name' => 'Rahmatov',
                    'date' => '2026-10-15',
                    'date_formatted' => '15 October 2026',
                    'time' => '14:30',
                    'building' => 'CCA Tashkent (Building B, 6 Amir Temur str., Tashkent)',
                    'event_title' => 'Masterclass: Contemporary Sculpture',
                    'event_type' => 'Masterclass',
                    'event_venue' => 'CCA Tashkent',
                    'status' => 'confirmed',
                    'status_label' => 'Confirmed',
                    'qr_png_base64' => base64_encode('fake-sculpture-qr'),
                    'translations' => [
                        'en' => [
                            'event_title' => 'Masterclass: Contemporary Sculpture',
                            'event_type' => 'Masterclass',
                            'event_venue' => 'CCA Tashkent',
                            'building' => 'CCA Tashkent (Building B, 6 Amir Temur str., Tashkent)',
                            'date_formatted' => '15 October 2026',
                        ],
                        'uz' => [
                            'event_title' => 'Mahorat darsi: Zamonaviy haykaltaroshlik',
                            'event_type' => 'Mahorat darsi',
                            'event_venue' => 'CCA Tashkent',
                            'building' => 'CCA Tashkent (Toshkent shahri, Amir Temur ko‘chasi, 6-uy, B bino)',
                            'date_formatted' => '15-oktabr, 2026',
                        ],
                        'ru' => [
                            'event_title' => 'Мастер-класс: Современная скульптура',
                            'event_type' => 'Мастер-класс',
                            'event_venue' => 'CCA Tashkent',
                            'building' => 'CCA Tashkent (Здание B, ул. Амира Темура, 6, Ташкент)',
                            'date_formatted' => '15 октября 2026 г.',
                        ],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 3001,
            'message' => [
                'message_id' => 301,
                'from' => ['id' => $chatId, 'first_name' => 'Dilshod'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);
        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });

        Http::assertSent(function ($request) use ($token) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = (string) ($request['text'] ?? '');

                return str_contains($text, 'Centre for Contemporary Arts Tashkent (CCA Tashkent)')
                    && str_contains($text, 'Programme Event Pass — Confirmed')
                    && str_contains($text, '<b>Visitor:</b> Dilshod Rahmatov')
                    && str_contains($text, '<b>Date:</b> 15 October 2026')
                    && str_contains($text, '🕒 <b>Time:</b> 14:30')
                    && str_contains($text, '<b>Building:</b> CCA Tashkent (Building B, 6 Amir Temur str., Tashkent)')
                    && str_contains($text, '<b>Event:</b> Masterclass: Contemporary Sculpture | Masterclass')
                    && str_contains($text, '<b>Status:</b> Confirmed')
                    && str_contains($text, "<b>Ref:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Show this QR code at the entrance for admission.')
                    && str_contains($text, '~~~')
                    && str_contains($text, 'Toshkent Zamonaviy san’at markazi')
                    && str_contains($text, 'Dastur tadbiri chiptasi – Tasdiqlangan')
                    && str_contains($text, '<b>Mehmon:</b> Dilshod Rahmatov')
                    && str_contains($text, '<b>Sana:</b> 15-oktabr, 2026')
                    && str_contains($text, '🕒 <b>Vaqti:</b> 14:30')
                    && str_contains($text, '<b>Bino:</b> CCA Tashkent (Toshkent shahri, Amir Temur ko‘chasi, 6-uy, B bino)')
                    && str_contains($text, '<b>Tadbir:</b> Mahorat darsi: Zamonaviy haykaltaroshlik | Mahorat darsi')
                    && str_contains($text, '<b>Holati:</b> Tasdiqlangan')
                    && str_contains($text, "<b>Iqt:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Ushbu QR-kodni kirishda taqdim eting.')
                    && str_contains($text, 'Центр современного искусства в Ташкенте (CCA Tashkent)')
                    && str_contains($text, 'Пропуск на мероприятие — регистрация подтверждена')
                    && str_contains($text, '<b>Посетитель:</b> Dilshod Rahmatov')
                    && str_contains($text, '<b>Дата:</b> 15 октября 2026 г.')
                    && str_contains($text, '🕒 <b>Время:</b> 14:30')
                    && str_contains($text, '<b>Место проведения:</b> CCA Tashkent (Здание B, ул. Амира Темура, 6, Ташкент)')
                    && str_contains($text, '<b>Мероприятие:</b> Мастер-класс: Современная скульптура | Мастер-класс')
                    && str_contains($text, '<b>Статус:</b> Регистрация подтверждена')
                    && str_contains($text, "<b>Номер регистрации:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Для прохода на мероприятие предъявите QR-код на входе.');
            }

            return false;
        });
    }

    public function test_webhook_handles_general_visit_booking_with_3_languages(): void
    {
        $token = 'visit_token_1234567890abcdef1234567890abcdef1234567890abcdef12345678';
        $chatId = 11223344;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'visit',
                    'badge' => 'General Visit Pass',
                    'token' => $token,
                    'ref' => substr($token, 0, 16),
                    'name' => 'Alisher Navoiy',
                    'first_name' => 'Alisher',
                    'last_name' => 'Navoiy',
                    'date' => '2026-09-18',
                    'date_formatted' => '18 September 2026',
                    'time' => '10:00',
                    'building' => 'CCA Tashkent',
                    'status' => 'confirmed',
                    'status_label' => 'Confirmed',
                    'qr_png_base64' => base64_encode('fake-visit-qr'),
                    'translations' => [
                        'en' => [
                            'building' => 'CCA Tashkent',
                            'date_formatted' => '18 September 2026',
                        ],
                        'uz' => [
                            'building' => 'Toshkent Zamonaviy san’at markazi (Toshkent shahri, Amir Temur ko‘chasi, 6-uy, B bino)',
                            'date_formatted' => '18-sentabr, 2026',
                        ],
                        'ru' => [
                            'building' => 'CCA Tashkent (Здание B, ул. Амира Темура, 6, Ташкент)',
                            'date_formatted' => '18 сентября 2026 г.',
                        ],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 4001,
            'message' => [
                'message_id' => 401,
                'from' => ['id' => $chatId, 'first_name' => 'Alisher'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);
        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });

        Http::assertSent(function ($request) use ($token) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = (string) ($request['text'] ?? '');

                return str_contains($text, 'Centre for Contemporary Arts Tashkent (CCA Tashkent)')
                    && str_contains($text, 'General Visit Pass — Confirmed')
                    && str_contains($text, '<b>Visitor:</b> Alisher Navoiy')
                    && str_contains($text, '<b>Date:</b> 18 September 2026')
                    && str_contains($text, '⏰ <b>Time:</b> 10:00')
                    && str_contains($text, '<b>Building:</b> CCA Tashkent')
                    && str_contains($text, '<b>Status:</b> Confirmed')
                    && str_contains($text, "<b>Ref:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Please present this QR code at the entrance for admission.')
                    && str_contains($text, '~~~')
                    && str_contains($text, 'Toshkent Zamonaviy san’at markazi')
                    && str_contains($text, 'Umumiy tashrif chiptasi – Tasdiqlangan')
                    && str_contains($text, '<b>Mehmon:</b> Alisher Navoiy')
                    && str_contains($text, '<b>Sana:</b> 18-sentabr, 2026')
                    && str_contains($text, '⏰ <b>Vaqti:</b> 10:00')
                    && str_contains($text, '<b>Bino:</b> Toshkent Zamonaviy san’at markazi (Toshkent shahri, Amir Temur ko‘chasi, 6-uy, B bino)')
                    && str_contains($text, '<b>Holati:</b> Tasdiqlangan')
                    && str_contains($text, "<b>Iqt:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Ushbu QR-kodni kirishda taqdim eting.')
                    && str_contains($text, 'Центр современного искусства в Ташкенте (CCA Tashkent)')
                    && str_contains($text, 'Пропуск для общего посещения — регистрация подтверждена')
                    && str_contains($text, '<b>Посетитель:</b> Alisher Navoiy')
                    && str_contains($text, '<b>Дата:</b> 18 сентября 2026 г.')
                    && str_contains($text, '⏰ <b>Время:</b> 10:00')
                    && str_contains($text, '<b>Место:</b> CCA Tashkent (Здание B, ул. Амира Темура, 6, Ташкент)')
                    && str_contains($text, '<b>Статус:</b> Регистрация подтверждена')
                    && str_contains($text, "<b>Номер регистрации:</b> <code>{$token}</code>")
                    && str_contains($text, '📲 Для прохода в Центр предъявите данный QR-код на входе.');
            }

            return false;
        });
    }

    public function test_webhook_handles_library_booking_with_3_languages(): void
    {
        $token = 'lib_token_1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        $chatId = 33445566;

        Http::fake([
            "*/booking/{$token}" => Http::response([
                'success' => true,
                'data' => [
                    'type' => 'library',
                    'badge' => 'CCA Tashkent Library Pass',
                    'token' => $token,
                    'ref' => substr($token, 0, 16),
                    'name' => 'Nodir Sodiq',
                    'first_name' => 'Nodir',
                    'last_name' => 'Sodiq',
                    'date' => '2026-09-18',
                    'date_formatted' => '18 September 2026',
                    'time' => '10:00 – 12:00',
                    'building' => 'CCA Tashkent Library (Service Building, 3rd Floor)',
                    'status' => 'confirmed',
                    'status_label' => 'Confirmed',
                    'qr_png_base64' => base64_encode('fake-library-qr'),
                    'translations' => [
                        'en' => [
                            'building' => 'CCA Tashkent Library (Service Building, 3rd Floor)',
                            'date_formatted' => '18 September 2026',
                        ],
                        'uz' => [
                            'building' => 'Toshkent Zamonaviy san’at markazi kutubxonasi (Xizmat ko‘rsatish binosi, 3-qavat)',
                            'date_formatted' => '18-sentabr, 2026',
                        ],
                        'ru' => [
                            'building' => 'Библиотека CCA Tashkent (Служебное здание, 3-й этаж)',
                            'date_formatted' => '18 сентября 2026 г.',
                        ],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config(['telegram.bot_token' => 'fake_bot_token']);

        $update = [
            'update_id' => 5001,
            'message' => [
                'message_id' => 501,
                'from' => ['id' => $chatId, 'first_name' => 'Nodir'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $update);
        $response->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });

        Http::assertSent(function ($request) use ($token) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = (string) ($request['text'] ?? '');

                $checks = [
                    'c1' => str_contains($text, 'Centre for Contemporary Arts Tashkent (CCA Tashkent)'),
                    'c2' => str_contains($text, 'CCA Tashkent Library Pass — Confirmed'),
                    'c3' => str_contains($text, '<b>Visitor:</b> Nodir Sodiq'),
                    'c4' => str_contains($text, '<b>Date:</b> 18 September 2026'),
                    'c5' => str_contains($text, '⏰ <b>Time:</b> 10:00 – 12:00'),
                    'c6' => str_contains($text, '<b>Location:</b> CCA Tashkent Library (Service Building, 3rd Floor)'),
                    'c7' => str_contains($text, '<b>Status:</b> Confirmed'),
                    'c8' => str_contains($text, "<b>Ref:</b> <code>{$token}</code>"),
                    'c9' => str_contains($text, '📲 Please present this QR code at the entrance for admission.'),
                    'c10' => str_contains($text, '~~~'),
                    'c11' => str_contains($text, 'Toshkent Zamonaviy san’at markazi'),
                    'c12' => str_contains($text, 'CCA kutubxonasi chiptasi – Tasdiqlangan'),
                    'c13' => str_contains($text, '<b>Mehmon:</b> Nodir Sodiq'),
                    'c14' => str_contains($text, '<b>Sana:</b> 18-sentabr, 2026'),
                    'c15' => str_contains($text, '⏰ <b>Vaqti:</b> 10:00 – 12:00'),
                    'c16' => str_contains($text, '<b>Manzil:</b> Toshkent Zamonaviy san’at markazi kutubxonasi (Xizmat ko‘rsatish binosi, 3-qavat)'),
                    'c17' => str_contains($text, '<b>Holati:</b> Tasdiqlangan'),
                    'c18' => str_contains($text, "<b>Iqt:</b> <code>{$token}</code>"),
                    'c19' => str_contains($text, '📲 Ushbu QR-kodni kirishda taqdim eting.'),
                    'c20' => str_contains($text, 'Центр современного искусства в Ташкенте (CCA Tashkent)'),
                    'c21' => str_contains($text, 'Пропуск в библиотеку CCA Tashkent — регистрация подтверждена'),
                    'c22' => str_contains($text, '<b>Посетитель:</b> Nodir Sodiq'),
                    'c23' => str_contains($text, '<b>Дата:</b> 18 сентября 2026 г.'),
                    'c24' => str_contains($text, '⏰ <b>Время:</b> 10:00 – 12:00'),
                    'c25' => str_contains($text, '<b>Место:</b> Библиотека CCA Tashkent (Служебное здание, 3-й этаж)'),
                    'c26' => str_contains($text, '<b>Статус:</b> Регистрация подтверждена'),
                    'c27' => str_contains($text, "<b>Номер регистрации:</b> <code>{$token}</code>"),
                    'c28' => str_contains($text, '📲 Для прохода в библиотеку предъявите данный QR-код на входе.'),
                ];

                return ! in_array(false, $checks, true);
            }

            return false;
        });
    }
}
