<?php

namespace Tests\Feature\Services;

use App\Models\TrainingAssignment;
use App\Services\TrainingStatusService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * J3 — the canonical status bucketing. Buckets are mutually exclusive and
 * complete: every TA lands in exactly one of overdue / due_soon / current /
 * not_started / as_needed. The amber window is the org's expiring-soon
 * threshold (TrainingAssignmentPill parity).
 */
class TrainingStatusServiceTest extends TestCase
{
    private TrainingStatusService $service;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrainingStatusService;
        $this->today = CarbonImmutable::parse('2026-06-10');
    }

    private function ta(array $attrs): TrainingAssignment
    {
        // No factory: statusFor is a pure function of attributes, no DB needed.
        return (new TrainingAssignment)->forceFill(array_merge([
            'as_needed_only' => false,
        ], $attrs));
    }

    private function bucket(array $attrs, int $dueSoonDays = 30): string
    {
        return $this->service->statusFor($this->ta($attrs), $dueSoonDays, $this->today);
    }

    public function test_never_completed_is_not_started(): void
    {
        $this->assertSame('not_started', $this->bucket([
            'last_completed_at' => null,
            'expires_at' => null,
        ]));
    }

    public function test_expired_yesterday_is_overdue(): void
    {
        $this->assertSame('overdue', $this->bucket([
            'last_completed_at' => '2025-06-01',
            'expires_at' => '2026-06-09',
        ]));
    }

    public function test_expiring_today_is_due_soon_not_overdue(): void
    {
        $this->assertSame('due_soon', $this->bucket([
            'last_completed_at' => '2025-06-01',
            'expires_at' => '2026-06-10',
        ]));
    }

    public function test_expiring_on_the_window_edge_is_due_soon(): void
    {
        $this->assertSame('due_soon', $this->bucket([
            'last_completed_at' => '2025-06-01',
            'expires_at' => '2026-07-10', // today + 30
        ]));
    }

    public function test_expiring_past_the_window_is_current(): void
    {
        $this->assertSame('current', $this->bucket([
            'last_completed_at' => '2025-06-01',
            'expires_at' => '2026-07-11', // today + 31
        ]));
    }

    public function test_completed_with_no_expiry_is_current(): void
    {
        $this->assertSame('current', $this->bucket([
            'last_completed_at' => '2025-06-01',
            'expires_at' => null,
        ]));
    }

    public function test_as_needed_only_wins_regardless_of_completion(): void
    {
        $this->assertSame('as_needed', $this->bucket([
            'as_needed_only' => true,
            'last_completed_at' => null,
            'expires_at' => null,
        ]));

        $this->assertSame('as_needed', $this->bucket([
            'as_needed_only' => true,
            'last_completed_at' => '2025-06-01',
            'expires_at' => null,
        ]));
    }

    public function test_custom_window_moves_the_due_soon_edge(): void
    {
        $attrs = [
            'last_completed_at' => '2025-06-01',
            'expires_at' => '2026-07-10', // today + 30
        ];

        $this->assertSame('due_soon', $this->bucket($attrs, 30));
        $this->assertSame('current', $this->bucket($attrs, 29));
    }

    public function test_days_until_due_is_signed_and_null_without_expiry(): void
    {
        $this->assertNull($this->service->daysUntilDue($this->ta(['expires_at' => null]), $this->today));
        $this->assertSame(5, $this->service->daysUntilDue($this->ta(['expires_at' => '2026-06-15']), $this->today));
        $this->assertSame(-3, $this->service->daysUntilDue($this->ta(['expires_at' => '2026-06-07']), $this->today));
        $this->assertSame(0, $this->service->daysUntilDue($this->ta(['expires_at' => '2026-06-10']), $this->today));
    }
}
