<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Maps a completion's expiry date to a human status + a colour-band key, using
 * the org's "expiring soon" window. Shared by the reports (Status column + row
 * banding) so the wording/thresholds match the compliance screens.
 */
class ExpiryStatus
{
    /**
     * @return array{key: string, label: string}  key ∈ expired|due_soon|current
     */
    public static function for(?string $expireDate, int $soonDays, string $today): array
    {
        // No expiry on record → the credit doesn't lapse; treat as current.
        if ($expireDate === null || $expireDate === '') {
            return ['key' => 'current', 'label' => 'Current'];
        }

        if ($expireDate < $today) {
            return ['key' => 'expired', 'label' => 'Expired'];
        }

        $boundary = Carbon::parse($today)->addDays($soonDays)->toDateString();
        if ($expireDate <= $boundary) {
            return ['key' => 'due_soon', 'label' => 'Expires soon'];
        }

        return ['key' => 'current', 'label' => 'Current'];
    }
}
