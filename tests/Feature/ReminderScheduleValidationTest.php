<?php

namespace Tests\Feature;

use App\Models\ReminderTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReminderScheduleValidationTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<int|string|float> $days */
    #[DataProvider('invalidScheduledDays')]
    public function test_invalid_scheduled_days_are_rejected(array $days): void
    {
        $this->expectException(ValidationException::class);
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => $days]);
    }

    public static function invalidScheduledDays(): array
    {
        return [
            'negative' => [[-7]],
            'decimal' => [['1.5']],
            'text' => [['besok']],
            'too large' => [[3651]],
            'duplicate' => [[30, 30]],
            'duplicate numeric strings' => [[30, '30']],
            'empty list' => [[]],
        ];
    }

    public function test_valid_schedule_accepts_zero_for_expiry_day_and_sorts_descending(): void
    {
        $template = ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => ['30', '90', 0, '60']]);

        $this->assertSame([90, 60, 30, 0], $template->reminderOffsets());
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_interval_schedule_is_rejected(array $attributes): void
    {
        $this->expectException(ValidationException::class);
        ReminderTemplate::factory()->create(['schedule_mode' => 'interval', ...$attributes]);
    }

    public static function invalidIntervals(): array
    {
        return [
            'zero interval' => [['start_before_days' => 30, 'interval_days' => 0]],
            'zero start' => [['start_before_days' => 0, 'interval_days' => 7]],
            'too large' => [['start_before_days' => 4000, 'interval_days' => 7]],
        ];
    }

    public function test_interval_schedule_expands_down_to_the_expiry_day(): void
    {
        $template = ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 10]);

        $this->assertSame([30, 20, 10, 0], $template->reminderOffsets());
    }

    public function test_inherit_mode_has_no_offsets(): void
    {
        $template = ReminderTemplate::factory()->create(['schedule_mode' => 'inherit']);

        $this->assertNull($template->reminderOffsets());
        $this->assertSame([], ReminderTemplate::globalReminderDays());
    }
}
