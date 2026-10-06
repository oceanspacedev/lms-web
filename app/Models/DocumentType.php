<?php

namespace App\Models;

use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;

#[Fillable(['name', 'has_expiry', 'reminder_days', 'is_active', 'reminder_template_id'])]
class DocumentType extends Model
{
    /** @return list<int> */
    public function effectiveReminderDays(): array
    {
        if (! $this->has_expiry) {
            return [];
        }

        return ReminderTemplate::globalReminderDays();
    }

    public function reminderTemplate(): BelongsTo
    {
        return $this->belongsTo(ReminderTemplate::class);
    }

    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $attributes = ['reminder_days' => '[]'];

    protected function casts(): array
    {
        return [
            'has_expiry' => 'boolean',
            'reminder_days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentType $documentType): void {
            if (! $documentType->has_expiry) {
                $documentType->reminder_days = [];

                return;
            }

            $validated = Validator::make(['reminder_days' => $documentType->reminder_days], [
                'reminder_days' => ['present', 'array'],
                'reminder_days.*' => ['integer', 'min:1', 'distinct'],
            ])->validate();

            $documentType->reminder_days = collect($validated['reminder_days'])
                ->map(fn (int|string $day): int => (int) $day)
                ->sortDesc()
                ->values()
                ->all();
        });
    }
}
