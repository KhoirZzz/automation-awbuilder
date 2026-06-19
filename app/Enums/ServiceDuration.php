<?php

namespace App\Enums;

use Carbon\Carbon;

enum ServiceDuration: string
{
    case ONE_WEEK = '1_minggu';
    case ONE_MONTH = '1_bulan';
    case THREE_MONTHS = '3_bulan';
    case SIX_MONTHS = '6_bulan';
    case ONE_YEAR = '1_tahun';

    /**
     * Calculate expiry date from now based on the duration.
     */
    public function calculateExpiry(): Carbon
    {
        return match ($this) {
            self::ONE_WEEK => now()->addWeek(),
            self::ONE_MONTH => now()->addMonth(),
            self::THREE_MONTHS => now()->addMonths(3),
            self::SIX_MONTHS => now()->addMonths(6),
            self::ONE_YEAR => now()->addYear(),
        };
    }
}
