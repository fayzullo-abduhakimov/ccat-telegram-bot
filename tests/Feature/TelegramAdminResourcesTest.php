<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TelegramUsers\TelegramUserResource;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\VisitBooking;
use App\Policies\TelegramUserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TelegramAdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->superAdmin();
    }

    public function test_telegram_users_have_a_policy_bound(): void
    {
        $this->assertInstanceOf(TelegramUserPolicy::class, Gate::getPolicyFor(TelegramUser::class));
    }

    public function test_telegram_users_sit_under_administration(): void
    {
        filament()->setCurrentPanel('admin');

        $this->assertSame(__('app.label.administration'), TelegramUserResource::getNavigationGroup());
        $this->assertSame(5, TelegramUserResource::getNavigationSort());
    }

    public function test_admin_can_view_telegram_users_index_and_view_page(): void
    {
        $admin = $this->admin();

        $telegramUser = TelegramUser::query()->create([
            'chat_id' => 123456789,
            'username' => 'testuser',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '998901234567',
            'language_code' => 'en',
            'last_seen_at' => now(),
        ]);

        VisitBooking::query()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+998 90 123 45 67',
            'position' => 'other',
            'date' => now()->addDay()->toDateString(),
            'time' => '14:00',
            'status' => 'confirmed',
        ]);

        $this->assertSame(1, $telegramUser->bookingsCount());

        $this->actingAs($admin)
            ->get(TelegramUserResource::getUrl('index', panel: 'admin'))
            ->assertOk()
            ->assertSee('testuser')
            ->assertSee('+998901234567');

        $this->actingAs($admin)
            ->get(TelegramUserResource::getUrl('view', ['record' => $telegramUser], panel: 'admin'))
            ->assertOk()
            ->assertSee('testuser')
            ->assertSee('123456789');
    }

    public function test_the_list_counts_bookings_without_a_query_per_row(): void
    {
        $phones = ['998901111111', '998902222222', '998903333333', '998904444444'];

        foreach ($phones as $index => $phone) {
            TelegramUser::query()->create(['chat_id' => 1000 + $index, 'first_name' => "User {$index}", 'phone' => $phone]);
        }

        TelegramUser::query()->create(['chat_id' => 2000, 'first_name' => 'No phone']);

        foreach (range(1, 2) as $day) {
            VisitBooking::query()->create([
                'first_name' => 'John', 'last_name' => 'Doe', 'email' => "john{$day}@example.com", 'phone' => '+998 90 111 11 11',
                'position' => 'other', 'date' => now()->addDays($day)->toDateString(), 'time' => '14:00', 'status' => 'confirmed',
            ]);
        }

        $listed = TelegramUser::query()->withBookingsCount()->orderBy('chat_id')->get();

        DB::enableQueryLog();
        $counts = $listed->map(fn (TelegramUser $user): int => $user->bookingsCount())->all();

        $this->assertSame([2, 0, 0, 0, 0], $counts);
        $this->assertSame([], DB::getQueryLog());

        DB::flushQueryLog();

        $this->actingAs($this->admin())
            ->get(TelegramUserResource::getUrl('index', panel: 'admin'))
            ->assertOk();

        $perRow = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_ends_with($entry['query'], 'where "phone_normalized" = ?'));

        $this->assertCount(0, $perRow);
    }
}
