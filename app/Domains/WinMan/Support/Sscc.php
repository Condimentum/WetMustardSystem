<?php

namespace App\Domains\WinMan\Support;

use InvalidArgumentException;

/**
 * SSCC (pallet / IBC) barcode handling, matching the StockTakeApp and MintSystemNew rules.
 *
 * A GS1-128 pallet label scans as "00" + the 18-digit SSCC + more data; only the 18 digits are kept.
 * In WinMan the SSCC of a pallet / IBC is the stock line's lot number (Inventory.LotNumber).
 */
final class Sscc
{
    public const LENGTH = 18;

    /**
     * The 18-digit SSCC inside what a scanner (or a person) delivered.
     *
     * Accepts "00" + SSCC + anything, a bare 18-digit SSCC, and tolerates a scanner prefix such as ]C1,
     * group separators and "(00)" brackets.
     *
     * @throws InvalidArgumentException when the text is not an SSCC
     */
    public static function extract(string $raw): string
    {
        $text = trim($raw);

        if ($text === '') {
            throw new InvalidArgumentException('The scan is empty. Scan the SSCC barcode again.');
        }

        if (str_contains($text, '^')) {
            throw new InvalidArgumentException('That is a QR label, not an SSCC barcode. Switch the scanner to QR, or scan the SSCC barcode.');
        }

        $text = (string) preg_replace('/^\][A-Za-z][0-9A-Za-z]/', '', $text);
        $text = str_replace(["\x1d", '(', ')', ' '], '', $text);

        if (preg_match('/^00(\d{18})/', $text, $m) === 1) {
            return $m[1];
        }

        if (str_starts_with($text, '0205')) {
            throw new InvalidArgumentException('That looks like the IBC serial barcode. Scan the SSCC barcode instead (the bottom one).');
        }

        if (preg_match('/^\d{18}$/', $text) === 1) {
            return $text;
        }

        throw new InvalidArgumentException('That is not an SSCC barcode: expected 00 followed by 18 digits (or the 18-digit SSCC). Scan it again.');
    }

    public static function looksLikeSscc(string $raw): bool
    {
        try {
            self::extract($raw);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** GS1 mod-10 check digit for the first 17 digits: weights 3 and 1 from the right. */
    public static function checkDigit(string $first17): int
    {
        $sum = 0;
        $length = strlen($first17);

        for ($i = 0; $i < $length; $i++) {
            $sum += (int) $first17[$i] * ((($length - $i) % 2 === 1) ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function checkDigitValid(string $sscc): bool
    {
        return preg_match('/^\d{18}$/', $sscc) === 1 && self::checkDigit(substr($sscc, 0, 17)) === (int) $sscc[17];
    }
}
