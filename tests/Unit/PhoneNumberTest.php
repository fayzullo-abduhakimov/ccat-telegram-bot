<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function numbers(): array
    {
        return [
            'as Telegram reports it' => ['998901234567', '998901234567'],
            'international with spaces' => ['+998 90 123 45 67', '998901234567'],
            'with dashes and brackets' => ['+998 (90) 123-45-67', '998901234567'],
            'local without country code' => ['90 123 45 67', '998901234567'],
            'with the 00 prefix' => ['00998901234567', '998901234567'],
            'another country' => ['+7 912 345 67 89', '79123456789'],
            'old domestic format with 8' => ['8 (90) 123-45-67', '998901234567'],
            'two numbers in one field' => ['+998 90 123 45 67, +998 91 765 43 21', '998901234567'],
            'longer than any real number' => ['1234567890123456', null],
            'too short to be a number' => ['12-34', null],
            'nothing' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_phone_numbers_are_written_one_way(?string $typed, ?string $normalized): void
    {
        $this->assertSame($normalized, PhoneNumber::normalize($typed));
    }
}
