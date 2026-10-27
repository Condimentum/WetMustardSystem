<?php

namespace Tests\Unit\Domains\WinMan\Support;

use App\Domains\WinMan\Support\Sscc;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SsccTest extends TestCase
{
    private const REAL_SSCC = '350607255023009858';

    public function test_keeps_a_bare_18_digit_sscc(): void
    {
        $this->assertSame(self::REAL_SSCC, Sscc::extract(self::REAL_SSCC));
    }

    public function test_strips_the_gs1_128_wrapper_down_to_the_18_digits(): void
    {
        $this->assertSame(self::REAL_SSCC, Sscc::extract('00'.self::REAL_SSCC.'37000000010'));
        $this->assertSame(self::REAL_SSCC, Sscc::extract(']C100'.self::REAL_SSCC."\x1d".'3700000001'));
        $this->assertSame(self::REAL_SSCC, Sscc::extract('(00)'.self::REAL_SSCC));
    }

    #[DataProvider('rejected')]
    public function test_rejects_non_sscc_scans(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sscc::extract($raw);
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'too few digits' => ['3506072550230098'],
            'qr label' => ['P100^Product^LOT1'],
            'ibc serial barcode' => ['020512345678901234'],
        ];
    }

    public function test_recognises_whether_a_scan_looks_like_an_sscc(): void
    {
        $this->assertTrue(Sscc::looksLikeSscc('00'.self::REAL_SSCC));
        $this->assertFalse(Sscc::looksLikeSscc('P100^Product^LOT1'));
    }

    public function test_validates_the_gs1_check_digit(): void
    {
        $this->assertTrue(Sscc::checkDigitValid(self::REAL_SSCC));
        $this->assertFalse(Sscc::checkDigitValid('350607255023009857'));
    }
}
