<?php

namespace Tests\Unit\Support;

use App\Support\ExpiryStatus;
use PHPUnit\Framework\TestCase;

class ExpiryStatusTest extends TestCase
{
    private const TODAY = '2026-06-21';

    public function test_past_expiry_is_expired(): void
    {
        $s = ExpiryStatus::for('2026-06-20', 30, self::TODAY);
        $this->assertSame('expired', $s['key']);
        $this->assertSame('Expired', $s['label']);
    }

    public function test_within_the_window_is_expires_soon(): void
    {
        // today + 10 days, window 30 → soon. Boundary day itself counts.
        $this->assertSame('due_soon', ExpiryStatus::for('2026-07-01', 30, self::TODAY)['key']);
        $this->assertSame('due_soon', ExpiryStatus::for('2026-07-21', 30, self::TODAY)['key']);
        $this->assertSame('Expires soon', ExpiryStatus::for('2026-07-01', 30, self::TODAY)['label']);
    }

    public function test_beyond_the_window_is_current(): void
    {
        $this->assertSame('current', ExpiryStatus::for('2026-08-01', 30, self::TODAY)['key']);
    }

    public function test_today_is_current_not_expired(): void
    {
        // Expires today → still valid today.
        $this->assertSame('due_soon', ExpiryStatus::for(self::TODAY, 30, self::TODAY)['key']);
    }

    public function test_no_expiry_is_current(): void
    {
        $this->assertSame('current', ExpiryStatus::for(null, 30, self::TODAY)['key']);
        $this->assertSame('current', ExpiryStatus::for('', 30, self::TODAY)['key']);
    }
}
