<?php

namespace App\Models;

use App\Services\WaghubService;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Fillable(['name', 'has_expiry', 'reminder_days', 'is_active', 'reminder_template_id', 'request_fields', 'request_attachments', 'request_reviewer_id', 'request_approver_id', 'request_pic_id'])]
class DocumentType extends Model
{
    public function resolveRequestPic(): ?User
    {
        foreach ([$this->request_pic_id, $this->request_reviewer_id, $this->request_approver_id] as $userId) {
            $user = $userId ? User::find($userId) : null;
            if ($user?->can('Review:DocumentRequest')) {
                return $user;
            }
        }
        $reviewers = User::permission('Review:DocumentRequest')->orderBy('id')->get();

        return $reviewers->first(fn (User $user): bool => app(WaghubService::class)->normalizePhone($user->phone) !== null) ?? $reviewers->first();
    }

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

    protected $attributes = ['reminder_days' => '[]', 'request_fields' => '[]', 'request_attachments' => '[]'];

    protected function casts(): array
    {
        return [
            'has_expiry' => 'boolean',
            'reminder_days' => 'array',
            'is_active' => 'boolean',
            'request_fields' => 'array',
            'request_attachments' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentType $documentType): void {
            if ($documentType->request_pic_id !== null) {
                $pic = User::find($documentType->request_pic_id);
                Validator::make(['request_pic_id' => $pic && $pic->can('Review:DocumentRequest') && app(WaghubService::class)->normalizePhone($pic->phone) ? $pic->id : null],
                    ['request_pic_id' => ['required']], ['required' => 'Pilih PIC dengan izin pemeriksaan dan nomor WhatsApp valid.'])->validate();
            }
            foreach (['request_fields', 'request_attachments'] as $attribute) {
                $items = $documentType->{$attribute} ?? [];
                Validator::make([$attribute => $items], [
                    $attribute => ['array', 'max:20'],
                    $attribute.'.*.label' => ['required', 'string', 'max:100', 'distinct'],
                    $attribute.'.*.required' => ['required', 'boolean'],
                    $attribute.'.*.key' => ['nullable', 'uuid', 'distinct'],
                    ...($attribute === 'request_fields' ? [$attribute.'.*.type' => ['required', 'in:text,textarea,date,number']] : [$attribute.'.*.when' => ['required', 'in:always,cost,renewal']]),
                ])->validate();
                $documentType->{$attribute} = array_values(array_map(function (array $item): array {
                    $item['key'] = filled($item['key'] ?? null) ? $item['key'] : (string) Str::uuid();

                    return $item;
                }, $items));
            }

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
