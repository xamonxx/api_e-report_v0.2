<?php

namespace App\Support;

final class PendingConfirmation
{
    public const LABEL = 'Belum ada konfirmasi';

    /**
     * True when the value is the pending-confirmation placeholder rather than a
     * real region name. Matching ignores case and spacing because the label
     * round-trips through the UI, which title-cases free-text region input.
     */
    public static function matches(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $value));

        return mb_strtolower($clean) === mb_strtolower(self::LABEL);
    }

    /**
     * Collapses an empty or pending-confirmation value to the canonical label so
     * downstream comparisons can rely on an exact match.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null || trim($value) === '' || self::matches($value)) {
            return self::LABEL;
        }

        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
