<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Events\BookingRequested;
use App\Models\LibraryBooking;
use App\Models\LibrarySlot;
use App\Models\Programme;
use App\Models\ProgrammeBooking;
use App\Models\TelegramUser;
use App\Models\VisitBooking;
use App\Models\VisitSlot;
use App\Telegram\Support\BookingCard;
use App\Telegram\TelegramWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 111222333;

    private function bot(string $language = 'ru', ChatType $type = ChatType::PRIVATE): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);

        return $bot
            ->setCommonUser(User::make(self::CHAT, false, 'Dilshod', language_code: $language))
            ->setCommonChat(Chat::make(self::CHAT, $type));
    }

    private function visitor(?string $phone = '998901234567', string $language = 'ru'): TelegramUser
    {
        return TelegramUser::query()->create(['chat_id' => self::CHAT, 'phone' => $phone, 'language_code' => $language]);
    }

    private function visit(string $phone = '+998 90 123 45 67', BookingStatus $status = BookingStatus::Confirmed, int $inDays = 3): VisitBooking
    {
        return VisitBooking::query()->create([
            'first_name' => 'Dilshod',
            'last_name' => 'Karimov',
            'email' => 'dilshod@example.com',
            'phone' => $phone,
            'position' => 'artist',
            'date' => Carbon::today()->addDays($inDays)->toDateString(),
            'time' => '11:00',
            'status' => $status,
        ]);
    }

    private function registration(BookingStatus $status, string $title = 'Public Talk'): ProgrammeBooking
    {
        $programme = Programme::query()->create([
            'title' => ['en' => $title, 'ru' => $title, 'uz' => $title],
            'slug' => 'talk-'.uniqid(),
            'quote_author' => ['en' => 'Author', 'ru' => 'Автор', 'uz' => 'Muallif'],
            'status' => true,
            'has_registration' => true,
            'date_start' => Carbon::today()->addDays(5)->setTime(18, 0),
            'date_end' => Carbon::today()->addDays(5)->setTime(20, 0),
        ]);

        return ProgrammeBooking::query()->create([
            'programme_id' => $programme->id,
            'first_name' => 'Dilshod',
            'last_name' => 'Karimov',
            'email' => 'dilshod@example.com',
            'phone' => '90 123 45 67',
            'position' => 'artist',
            'status' => $status,
        ]);
    }

    /**
     * @return list<array{method: string, data: array<string, mixed>}>
     */
    private function sent(FakeNutgram $bot): array
    {
        return array_values(array_map(function (array $call): array {
            [$request] = array_values($call);

            return ['method' => $request->getUri()->getPath(), 'data' => FakeNutgram::getActualData($request)];
        }, $bot->getRequestHistory()));
    }

    /** @return list<string> */
    private function texts(FakeNutgram $bot): array
    {
        return array_values(array_filter(array_map(fn (array $call): ?string => $call['data']['text'] ?? null, $this->sent($bot))));
    }

    /** @return list<string> */
    private function methods(FakeNutgram $bot): array
    {
        return array_column($this->sent($bot), 'method');
    }

    private function librarySlot(int $inDays, string $startsAt, int $capacity = 20): LibrarySlot
    {
        return LibrarySlot::query()->create([
            'date' => Carbon::today()->addDays($inDays)->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt)->addHour()->format('H:i'),
            'capacity' => $capacity,
            'status' => true,
        ]);
    }

    private function visitSlot(int $inDays, string $time, int $capacity = 20): VisitSlot
    {
        return VisitSlot::query()->create([
            'date' => Carbon::today()->addDays($inDays)->toDateString(),
            'time' => $time,
            'capacity' => $capacity,
            'status' => true,
        ]);
    }

    private function libraryBooking(LibrarySlot $slot, string $endsAt, string $phone = '+998 90 123 45 67'): LibraryBooking
    {
        return LibraryBooking::query()->create([
            'first_name' => 'Dilshod',
            'last_name' => 'Karimov',
            'email' => 'dilshod@example.com',
            'phone' => $phone,
            'position' => 'researcher',
            'purpose' => 'Archive',
            'library_slot_id' => $slot->id,
            'date' => $slot->date->toDateString(),
            'starts_at' => $slot->starts_at,
            'ends_at' => $endsAt,
            'status' => BookingStatus::Confirmed,
        ]);
    }

    private function bookedVisit(VisitSlot $slot, string $phone = '+998 90 123 45 67'): VisitBooking
    {
        return VisitBooking::query()->create([
            'first_name' => 'Dilshod',
            'last_name' => 'Karimov',
            'email' => 'dilshod@example.com',
            'phone' => $phone,
            'position' => 'artist',
            'visit_slot_id' => $slot->id,
            'date' => $slot->date->toDateString(),
            'time' => $slot->time,
            'status' => BookingStatus::Confirmed,
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function buttons(FakeNutgram $bot, string $field = 'callback_data'): Collection
    {
        $call = collect($this->sent($bot))->last(fn (array $call): bool => isset($call['data']['reply_markup']['inline_keyboard']));

        return collect($call['data']['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck($field);
    }

    /** @return list<string> */
    private function alerts(FakeNutgram $bot): array
    {
        return array_values(array_filter(array_map(
            fn (array $call): ?string => $call['method'] === 'answerCallbackQuery' ? ($call['data']['text'] ?? null) : null,
            $this->sent($bot),
        )));
    }

    public function test_start_greets_in_the_visitors_language_and_asks_for_their_number(): void
    {
        $bot = $this->bot('uz');
        $bot->hearText('/start')->reply();

        [$welcome, $askPhone] = $this->texts($bot);
        $this->assertStringStartsWith(__('app.bot.welcome', [], 'uz'), $welcome);
        $this->assertStringContainsString(__('app.bot.welcome', [], 'ru'), $welcome);
        $this->assertStringContainsString(__('app.bot.welcome', [], 'en'), $welcome);
        $this->assertSame(__('app.bot.ask_phone', [], 'uz'), $askPhone);
        $this->assertTrue($this->sent($bot)[1]['data']['reply_markup']['keyboard'][0][0]['request_contact']);
        $this->assertSame('uz', TelegramUser::query()->where('chat_id', self::CHAT)->sole()->language_code);
    }

    public function test_a_regional_telegram_language_picks_the_site_language(): void
    {
        $this->bot('en-GB')->hearText('/start')->reply();

        $this->assertSame('en', TelegramUser::query()->where('chat_id', self::CHAT)->sole()->language_code);
    }

    public function test_the_old_start_links_with_a_code_still_open_the_bot(): void
    {
        $bot = $this->bot();
        $bot->hearText('/start 7d8e9f')->reply();

        $this->assertStringStartsWith(__('app.bot.welcome', [], 'ru'), $this->texts($bot)[0]);
    }

    public function test_help_shows_what_the_bot_can_do(): void
    {
        $bot = $this->bot();
        $bot->hearText('/help')->reply();

        $this->assertStringStartsWith(__('app.bot.welcome', [], 'ru'), $this->texts($bot)[0]);
    }

    public function test_the_menu_buttons_work_in_every_language(): void
    {
        $this->visitor();
        $this->visit();

        foreach (['uz', 'ru', 'en'] as $language) {
            $bot = $this->bot();
            $bot->hearText(__('app.bot.menu_bookings', [], $language))->reply();

            $this->assertSame(__('app.bot.bookings_heading', [], 'ru'), $this->texts($bot)[0], "The {$language} menu button did nothing.");
        }

        $bot->hearText('/mybookings')->reply();

        $this->assertSame(__('app.bot.bookings_heading', [], 'ru'), $this->texts($bot)[0]);
    }

    public function test_sharing_their_own_number_shows_their_bookings(): void
    {
        $booking = $this->visit('90 123-45-67');

        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '+998901234567', 'first_name' => 'Dilshod', 'user_id' => self::CHAT]])->reply();

        $this->assertSame('998901234567', TelegramUser::query()->where('chat_id', self::CHAT)->sole()->phone);

        $texts = $this->texts($bot);
        $this->assertSame(__('app.bot.phone_saved', ['phone' => '998901234567'], 'ru'), $texts[0]);
        $this->assertSame(__('app.bot.bookings_heading', [], 'ru'), $texts[1]);
        $this->assertStringStartsWith('<b>'.__('app.bot.type_visit', [], 'ru').'</b>', $texts[2]);
        $this->assertStringContainsString(__('app.bot.type_visit', [], 'uz'), $texts[2]);
        $this->assertStringContainsString(__('app.bot.type_visit', [], 'en'), $texts[2]);
        $this->assertStringContainsString('Dilshod Karimov', $texts[2]);
        $this->assertSame(__('app.bot.menu_bookings', [], 'ru'), $this->sent($bot)[0]['data']['reply_markup']['keyboard'][0][0]['text']);

        $buttons = collect($this->sent($bot)[2]['data']['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data');
        $this->assertContains("qr:visit:{$booking->id}", $buttons);
        $this->assertContains("cancel:visit:{$booking->id}", $buttons);
    }

    public function test_a_contact_card_of_somebody_else_is_refused(): void
    {
        $this->visit();

        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '+998901234567', 'first_name' => 'Someone', 'user_id' => 999]])->reply();

        $this->assertSame([__('app.bot.not_own_contact', [], 'ru')], $this->texts($bot));
        $this->assertNull(TelegramUser::query()->where('chat_id', self::CHAT)->sole()->phone);
    }

    public function test_a_number_belongs_to_the_account_that_shared_it_last(): void
    {
        TelegramUser::query()->create(['chat_id' => 5, 'phone' => '998901234567']);

        $this->bot()->hearMessage(['contact' => ['phone_number' => '998901234567', 'first_name' => 'Dilshod', 'user_id' => self::CHAT]])->reply();

        $this->assertNull(TelegramUser::query()->where('chat_id', 5)->sole()->phone);
        $this->assertSame('998901234567', TelegramUser::query()->where('chat_id', self::CHAT)->sole()->phone);
    }

    public function test_bookings_made_with_another_number_stay_hidden(): void
    {
        $this->visitor();
        $this->visit('+998 91 111 11 11');

        $bot = $this->bot();
        $bot->hearText('/mybookings')->reply();

        $this->assertSame([__('app.bot.no_bookings', ['phone' => '998901234567'], 'ru')], $this->texts($bot));
    }

    public function test_the_bookings_list_asks_for_the_number_when_it_is_unknown(): void
    {
        $this->visitor(phone: null);

        $bot = $this->bot();
        $bot->hearText('/mybookings')->reply();

        $this->assertSame([__('app.bot.ask_phone', [], 'ru')], $this->texts($bot));
    }

    public function test_history_lists_past_and_cancelled_bookings(): void
    {
        $this->visitor();
        $this->visit(status: BookingStatus::Cancelled);
        $this->visit(inDays: -2);
        $this->visit();

        $bot = $this->bot();
        $bot->hearText('/history')->reply();

        $text = $this->texts($bot)[0];
        $this->assertStringStartsWith(__('app.bot.history_heading', [], 'ru'), $text);
        $this->assertSame(2, substr_count($text, '•'));
        $this->assertStringContainsString(BookingStatus::Cancelled->getLabel(), $text);
    }

    public function test_the_qr_code_is_sent_for_a_confirmed_booking(): void
    {
        $this->visitor();
        $booking = $this->visit();

        $bot = $this->bot();
        $bot->hearCallbackQueryData("qr:visit:{$booking->id}")->reply();

        $this->assertSame(['answerCallbackQuery', 'sendPhoto'], $this->methods($bot));
    }

    public function test_the_bot_offers_no_pdf_pass(): void
    {
        $this->visitor();
        $this->visit();

        $bot = $this->bot();
        $bot->hearText('/mybookings')->reply();

        $buttons = collect($this->sent($bot)[1]['data']['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data');
        $this->assertCount(3, $buttons);
        $this->assertSame([], $buttons->filter(fn (string $data): bool => str_starts_with($data, 'pass:'))->all());
    }

    public function test_a_request_awaiting_approval_has_no_pass(): void
    {
        $this->visitor();
        $request = $this->registration(BookingStatus::Pending);

        $bot = $this->bot();
        $bot->hearText('/mybookings')->reply();

        $card = $this->sent($bot)[1]['data'];
        $this->assertStringContainsString(__('app.bot.pending_note', [], 'ru'), $card['text']);
        $this->assertNotContains("qr:programme:{$request->id}", collect($card['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data'));

        $bot->hearCallbackQueryData("qr:programme:{$request->id}")->reply();

        $this->assertNotContains('sendPhoto', $this->methods($bot));
    }

    public function test_buttons_cannot_reach_somebody_elses_booking(): void
    {
        $this->visitor();
        $stranger = $this->visit('+998 93 000 00 00');

        $bot = $this->bot();
        $bot->hearCallbackQueryData("qr:visit:{$stranger->id}")->reply();

        $this->assertSame(['answerCallbackQuery'], $this->methods($bot));
        $this->assertSame(__('app.bot.not_found', [], 'ru'), $this->sent($bot)[0]['data']['text']);

        $bot->hearCallbackQueryData("cancel-yes:visit:{$stranger->id}")->reply();

        $this->assertSame(['answerCallbackQuery'], $this->methods($bot));
        $this->assertSame(BookingStatus::Confirmed, $stranger->fresh()->status);
    }

    public function test_cancelling_asks_first_and_then_frees_the_place(): void
    {
        $this->visitor();
        $booking = $this->visit();

        $bot = $this->bot();
        $bot->hearCallbackQueryData("cancel:visit:{$booking->id}")->reply();

        $this->assertStringContainsString(__('app.bot.cancel_question', [], 'ru'), $this->texts($bot)[0]);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $bot->hearCallbackQueryData("cancel-yes:visit:{$booking->id}")->reply();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->cancelled_at);
        $this->assertStringContainsString(__('app.bot.cancelled', [], 'ru'), $this->texts($bot)[array_key_last($this->texts($bot))]);
    }

    public function test_keeping_the_booking_leaves_it_as_it_was(): void
    {
        $this->visitor();
        $booking = $this->visit();

        $bot = $this->bot();
        $bot->hearCallbackQueryData("cancel-no:visit:{$booking->id}")->reply();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_visit_that_has_happened_cannot_be_cancelled(): void
    {
        $this->visitor();
        $verified = $this->visit(status: BookingStatus::Verified);
        $library = LibraryBooking::query()->create([
            'first_name' => 'Dilshod',
            'last_name' => 'Karimov',
            'email' => 'dilshod@example.com',
            'phone' => '+998901234567',
            'position' => 'researcher',
            'purpose' => 'Archive',
            'date' => Carbon::today()->subDay()->toDateString(),
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'status' => BookingStatus::Confirmed,
        ]);

        $bot = $this->bot();

        foreach (["visit:{$verified->id}", "library:{$library->id}"] as $reference) {
            $bot->hearCallbackQueryData("cancel-yes:{$reference}")->reply();

            $this->assertSame(__('app.bot.cancel_too_late', [], 'ru'), $this->sent($bot)[0]['data']['text']);
        }

        $this->assertSame(BookingStatus::Verified, $verified->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $library->fresh()->status);
    }

    public function test_visits_and_library_bookings_can_be_moved_but_programme_registrations_cannot(): void
    {
        $this->visitor();
        $visit = $this->visit();
        $library = $this->libraryBooking($this->librarySlot(2, '10:00'), '12:00');
        $registration = $this->registration(BookingStatus::Confirmed);

        $bot = $this->bot();
        $bot->hearText('/mybookings')->reply();

        $buttons = collect($this->sent($bot))->flatMap(fn (array $call): array => collect($call['data']['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data')->all());

        $this->assertContains("move:visit:{$visit->id}", $buttons);
        $this->assertContains("move:library:{$library->id}", $buttons);
        $this->assertNotContains("move:programme:{$registration->id}", $buttons);
        $this->assertContains("cancel:programme:{$registration->id}", $buttons);

        $bot->hearCallbackQueryData("move:programme:{$registration->id}")->reply();

        $this->assertSame([__('app.bot.move_closed', [], 'ru')], $this->alerts($bot));
    }

    public function test_a_library_booking_moves_to_another_day_keeps_its_length_and_the_new_pass_is_emailed(): void
    {
        Event::fake([BookingRequested::class]);
        $this->visitor();
        $booking = $this->libraryBooking($this->librarySlot(2, '10:00'), '12:00');
        $later = $this->librarySlot(5, '14:00');
        $full = $this->librarySlot(5, '16:00', capacity: 1);
        $this->libraryBooking($full, '17:00', phone: '+998 93 000 00 00');
        $newDay = $later->date->toDateString();

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move:library:{$booking->id}")->reply();

        $this->assertStringEndsWith(__('app.bot.move_choose_day', [], 'ru'), $this->texts($bot)[0]);
        $this->assertContains("move-day:library:{$booking->id}:{$newDay}", $this->buttons($bot));
        $this->assertContains("move-no:library:{$booking->id}", $this->buttons($bot));

        $bot->hearCallbackQueryData("move-day:library:{$booking->id}:{$newDay}")->reply();

        $this->assertStringEndsWith(__('app.bot.move_choose_time', ['date' => BookingCard::day($later->date, 'dddd, D MMMM', 'ru')], 'ru'), $this->texts($bot)[0]);
        $this->assertContains("move-to:library:{$booking->id}:{$later->id}", $this->buttons($bot));
        $this->assertNotContains("move-to:library:{$booking->id}:{$full->id}", $this->buttons($bot));
        $this->assertContains('14:00 – 16:00', $this->buttons($bot, 'text'));
        $this->assertStringNotContainsString('PM', $this->buttons($bot, 'text')->implode(' '));

        $bot->hearCallbackQueryData("move-to:library:{$booking->id}:{$later->id}")->reply();

        $booking->refresh();
        $this->assertSame($newDay, $booking->date->toDateString());
        $this->assertSame('14:00', $booking->starts_at);
        $this->assertSame('16:00', $booking->ends_at);
        $this->assertSame($later->id, $booking->library_slot_id);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame([__('app.bot.moved', [], 'ru')], $this->alerts($bot));
        $this->assertStringContainsString('14:00 – 16:00', $this->texts($bot)[1]);
        $this->assertContains("qr:library:{$booking->id}", $this->buttons($bot));

        Event::assertDispatchedTimes(BookingRequested::class, 1);
        Event::assertDispatched(BookingRequested::class, fn (BookingRequested $event): bool => $event->booking->is($booking));
    }

    public function test_a_visit_moves_to_another_time_on_the_same_day(): void
    {
        Event::fake([BookingRequested::class]);
        $this->visitor();
        $booking = $this->bookedVisit($this->visitSlot(3, '11:00'));
        $afternoon = $this->visitSlot(3, '15:00');

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move-day:visit:{$booking->id}:{$afternoon->date->toDateString()}")->reply();

        $this->assertSame(['15:00', __('app.bot.move_other_day', [], 'ru'), __('app.bot.move_back', [], 'ru')], $this->buttons($bot, 'text')->all());

        $bot->hearCallbackQueryData("move-to:visit:{$booking->id}:{$afternoon->id}")->reply();

        $this->assertSame('15:00', $booking->fresh()->time);
        $this->assertSame($afternoon->id, $booking->fresh()->visit_slot_id);
        Event::assertDispatched(BookingRequested::class);
    }

    public function test_a_taken_time_is_refused_and_the_booking_stays(): void
    {
        Event::fake([BookingRequested::class]);
        $this->visitor();
        $slot = $this->visitSlot(3, '11:00');
        $booking = $this->bookedVisit($slot);
        $full = $this->visitSlot(4, '12:00', capacity: 1);
        $this->bookedVisit($full, phone: '+998 93 000 00 00');

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move-to:visit:{$booking->id}:{$full->id}")->reply();

        $this->assertSame([__('app.bot.move_taken', [], 'ru')], $this->alerts($bot));
        $this->assertSame($slot->id, $booking->fresh()->visit_slot_id);
        Event::assertNotDispatched(BookingRequested::class);
    }

    public function test_a_day_the_visitor_already_has_a_booking_on_is_not_offered(): void
    {
        $this->visitor();
        $booking = $this->bookedVisit($this->visitSlot(3, '11:00'));
        $taken = $this->bookedVisit($this->visitSlot(5, '11:00'));
        $sameDay = $this->visitSlot(5, '16:00');
        $free = $this->visitSlot(6, '11:00');

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move:visit:{$booking->id}")->reply();

        $this->assertContains("move-day:visit:{$booking->id}:{$free->date->toDateString()}", $this->buttons($bot));
        $this->assertNotContains("move-day:visit:{$booking->id}:{$sameDay->date->toDateString()}", $this->buttons($bot));

        $bot->hearCallbackQueryData("move-to:visit:{$booking->id}:{$sameDay->id}")->reply();

        $this->assertSame([__('app.booking.error.daily_limit', ['limit' => 1], 'ru')], $this->alerts($bot));
        $this->assertSame(3, (int) Carbon::today()->diffInDays($booking->fresh()->date));
        $this->assertSame(BookingStatus::Confirmed, $taken->fresh()->status);
    }

    public function test_a_late_library_move_still_ends_before_midnight(): void
    {
        $this->visitor();
        $booking = $this->libraryBooking($this->librarySlot(2, '10:00'), '18:00');
        $evening = $this->librarySlot(4, '20:00');

        $this->bot()->hearCallbackQueryData("move-to:library:{$booking->id}:{$evening->id}")->reply();

        $this->assertSame('20:00', $booking->fresh()->starts_at);
        $this->assertSame('23:00', $booking->fresh()->ends_at);
    }

    public function test_the_days_to_move_to_are_offered_soonest_first(): void
    {
        $this->visitor();
        $booking = $this->bookedVisit($this->visitSlot(2, '11:00'));
        $this->visitSlot(9, '11:00');
        $this->visitSlot(4, '11:00');
        $this->visitSlot(1, '11:00');

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move:visit:{$booking->id}")->reply();

        $days = $this->buttons($bot)->filter(fn (string $data): bool => str_starts_with($data, 'move-day:'))
            ->map(fn (string $data): string => substr($data, -10))
            ->values()
            ->all();

        $this->assertSame([1, 4, 9], array_map(fn (string $day): int => (int) Carbon::today()->diffInDays(Carbon::parse($day)), $days));
    }

    public function test_an_old_library_booking_that_ran_past_midnight_keeps_its_length_when_moved(): void
    {
        $this->visitor();
        $booking = $this->libraryBooking($this->librarySlot(2, '16:00'), '00:00');
        $morning = $this->librarySlot(3, '10:00');

        $this->assertSame(8, $booking->duration_hours);

        $this->bot()->hearCallbackQueryData("move-to:library:{$booking->id}:{$morning->id}")->reply();

        $this->assertSame('10:00', $booking->fresh()->starts_at);
        $this->assertSame('18:00', $booking->fresh()->ends_at);
    }

    public function test_a_booking_cannot_be_moved_back_and_forth_without_end(): void
    {
        Event::fake([BookingRequested::class]);
        $this->visitor();
        $first = $this->visitSlot(3, '11:00');
        $second = $this->visitSlot(3, '15:00');
        $booking = $this->bookedVisit($first);

        $bot = $this->bot();

        foreach (range(1, 10) as $move) {
            $bot->hearCallbackQueryData('move-to:visit:'.$booking->id.':'.($move % 2 ? $second->id : $first->id))->reply();
        }

        $this->assertSame($first->id, $booking->fresh()->visit_slot_id);

        $bot->hearCallbackQueryData("move-to:visit:{$booking->id}:{$second->id}")->reply();

        $this->assertSame([__('app.booking.error.too_many', [], 'ru')], $this->alerts($bot));
        $this->assertSame($first->id, $booking->fresh()->visit_slot_id);
        Event::assertDispatchedTimes(BookingRequested::class, 10);
    }

    public function test_somebody_elses_or_a_finished_booking_cannot_be_moved(): void
    {
        $this->visitor();
        $slot = $this->visitSlot(4, '12:00');
        $stranger = $this->bookedVisit($this->visitSlot(3, '11:00'), phone: '+998 93 000 00 00');
        $verified = $this->visit(status: BookingStatus::Verified);
        $cancelled = $this->visit(status: BookingStatus::Cancelled);

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move-to:visit:{$stranger->id}:{$slot->id}")->reply();
        $this->assertSame([__('app.bot.not_found', [], 'ru')], $this->alerts($bot));

        foreach ([$verified, $cancelled] as $booking) {
            $bot->hearCallbackQueryData("move-to:visit:{$booking->id}:{$slot->id}")->reply();
            $this->assertSame([__('app.bot.move_closed', [], 'ru')], $this->alerts($bot));
            $this->assertNull($booking->fresh()->visit_slot_id);
        }

        $this->assertNotSame($slot->id, $stranger->fresh()->visit_slot_id);
    }

    public function test_going_back_shows_the_booking_card_again(): void
    {
        $this->visitor();
        $booking = $this->bookedVisit($this->visitSlot(3, '11:00'));

        $bot = $this->bot();
        $bot->hearCallbackQueryData("move-no:visit:{$booking->id}")->reply();

        $this->assertStringStartsWith('<b>'.__('app.bot.type_visit', [], 'ru').'</b>', $this->texts($bot)[0]);
        $this->assertContains("move:visit:{$booking->id}", $this->buttons($bot));
    }

    public function test_programme_titles_arrive_as_plain_text(): void
    {
        $this->visitor();
        $this->registration(BookingStatus::Confirmed, '<p>Talk &amp; <strong>Tea</strong></p>');

        $bot = $this->bot();
        $bot->hearText('/mybookings')->reply();

        $this->assertStringContainsString('<b>Talk &amp; Tea</b>', $this->texts($bot)[1]);
    }

    public function test_the_visitor_can_switch_the_language(): void
    {
        $this->visitor();

        $bot = $this->bot();
        $bot->hearCallbackQueryData('lang:en')->reply();

        $this->assertSame('en', TelegramUser::query()->where('chat_id', self::CHAT)->sole()->language_code);
        $this->assertSame([__('app.bot.language_saved', [], 'en'), __('app.bot.menu', [], 'en')], $this->texts($bot));
    }

    public function test_group_chats_are_ignored(): void
    {
        $bot = $this->bot(type: ChatType::GROUP);
        $bot->hearText('/start')->reply();

        $this->assertSame([], $this->sent($bot));
        $this->assertDatabaseCount('telegram_users', 0);
    }

    public function test_the_webhook_only_takes_updates_that_carry_the_secret(): void
    {
        $update = [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'date' => now()->getTimestamp(),
                'chat' => ['id' => self::CHAT, 'type' => 'private'],
                'from' => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Dilshod', 'language_code' => 'en'],
                'text' => '/start',
            ],
        ];

        $this->postJson(route('telegram.webhook'), $update)->assertForbidden();
        $this->postJson(route('telegram.webhook'), $update, ['X-Telegram-Bot-Api-Secret-Token' => 'guess'])->assertForbidden();
        $this->assertDatabaseCount('telegram_users', 0);

        $this->postJson(route('telegram.webhook'), $update, ['X-Telegram-Bot-Api-Secret-Token' => TelegramWebhook::secret()])->assertNoContent();

        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $this->assertStringStartsWith(__('app.bot.welcome', [], 'en'), $this->texts($bot)[0]);
        $this->assertDatabaseHas('telegram_users', ['chat_id' => self::CHAT, 'language_code' => 'en']);
    }

    public function test_the_webhook_command_points_telegram_at_the_site(): void
    {
        config(['nutgram.token' => '123:abc']);

        $this->artisan('telegram:webhook')->assertSuccessful();

        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $calls = $this->sent($bot);

        $this->assertSame('setWebhook', $calls[0]['method']);
        $this->assertSame(route('telegram.webhook'), $calls[0]['data']['url']);
        $this->assertSame(TelegramWebhook::secret(), $calls[0]['data']['secret_token']);
        $this->assertContains('setMyCommands', array_column($calls, 'method'));
    }

    public function test_the_webhook_command_does_nothing_without_a_token(): void
    {
        config(['nutgram.token' => null]);

        $this->artisan('telegram:webhook')->assertSuccessful();

        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $this->assertSame([], $bot->getRequestHistory());
    }
}
