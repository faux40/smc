<?php

namespace Tests\Unit\Support;

use App\Models\StdFrequency;
use App\Models\Training;
use App\Support\ExpiryCalculator;
use Tests\TestCase;

/**
 * forTraining() instantiates Training/StdFrequency models (to exercise the
 * stdFrequency relation without a DB round-trip), so this extends the
 * Laravel-bootstrapped Tests\TestCase rather than a bare PHPUnit TestCase —
 * BelongsToOrganization's global scope registration touches app()/config()
 * on first boot of the model class.
 */
class ExpiryCalculatorTest extends TestCase
{
    public function test_adds_repeat_days_to_completion_date(): void
    {
        $this->assertSame(
            '2027-06-01',
            ExpiryCalculator::fromRepeatDays('2026-06-01', repeating: true, repeatDays: 365),
        );
    }

    public function test_crosses_a_leap_day_correctly(): void
    {
        // 2024 is a leap year (366 days), so +365 days from Jan 1 lands one
        // day short of the next Jan 1 — proof this is real day arithmetic,
        // not a naive "same date next year" shortcut.
        $this->assertSame(
            '2024-12-31',
            ExpiryCalculator::fromRepeatDays('2024-01-01', repeating: true, repeatDays: 365),
        );
    }

    public function test_crosses_a_year_boundary(): void
    {
        $this->assertSame(
            '2027-01-01',
            ExpiryCalculator::fromRepeatDays('2026-12-31', repeating: true, repeatDays: 1),
        );
    }

    public function test_not_repeating_is_never_expiring(): void
    {
        $this->assertNull(ExpiryCalculator::fromRepeatDays('2026-06-01', repeating: false, repeatDays: 365));
    }

    public function test_repeating_with_no_repeat_days_is_never_expiring(): void
    {
        $this->assertNull(ExpiryCalculator::fromRepeatDays('2026-06-01', repeating: true, repeatDays: null));
        $this->assertNull(ExpiryCalculator::fromRepeatDays('2026-06-01', repeating: true, repeatDays: 0));
    }

    public function test_for_training_resolves_repeat_days_from_std_frequency(): void
    {
        $freq = new StdFrequency(['repeat_days' => 90]);
        $training = new Training(['repeating' => true]);
        $training->setRelation('stdFrequency', $freq);

        $this->assertSame('2026-09-01', ExpiryCalculator::forTraining($training, '2026-06-03'));
    }

    public function test_for_training_with_no_std_frequency_is_never_expiring(): void
    {
        $training = new Training(['repeating' => true]);
        $training->setRelation('stdFrequency', null);

        $this->assertNull(ExpiryCalculator::forTraining($training, '2026-06-03'));
    }
}
