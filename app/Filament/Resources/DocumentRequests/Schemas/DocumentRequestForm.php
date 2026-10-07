<?php

namespace App\Filament\Resources\DocumentRequests\Schemas;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class DocumentRequestForm
{
    private static function isReadOnly(?DocumentRequest $record): bool
    {
        return $record && ! $record->editableBy(auth()->user());
    }

    /** @return array<string, mixed> */
    private static function requirements(Get $get, ?DocumentRequest $record): array
    {
        if ($record) {
            return $record->requirements;
        }
        $type = DocumentType::find($get('document_type_id'));

        return ['fields' => $type?->request_fields ?? [], 'attachments' => $type?->request_attachments ?? []];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Ringkasan')->compact()->columns(3)->visible(fn (?DocumentRequest $record): bool => self::isReadOnly($record))->schema([
                TextEntry::make('status_label')->label('Status')->state(fn (DocumentRequest $record): string => DocumentRequest::STATUSES[$record->status])->badge(),
                TextEntry::make('documentType.name')->label('Jenis dokumen')->wrap(),
                TextEntry::make('business_name')->label('Badan usaha')->state(fn (DocumentRequest $record): ?string => $record->company?->name ?? $record->other_business_name)->wrap(),
                TextEntry::make('pic.name')->label('PIC')->placeholder('—'),
                TextEntry::make('applicant_name')->label('Pengaju')->state(fn (DocumentRequest $record): ?string => $record->requester_name ?? $record->requester?->name)->wrap(),
                TextEntry::make('requester_phone')->label('WhatsApp')->visible(fn (DocumentRequest $record): bool => filled($record->requester_phone)),
                TextEntry::make('requester_division')->label('Divisi')->visible(fn (DocumentRequest $record): bool => filled($record->requester_division)),
                TextEntry::make('submission_reason')->label('Keperluan')->state(fn (DocumentRequest $record): string => $record->request_reason ?: $record->title)->columnSpanFull()->wrap(),
                TextEntry::make('review_note')->label('Catatan pemeriksa')->visible(fn (DocumentRequest $record): bool => in_array($record->status, ['revision', 'rejected'], true))
                    ->state(fn (DocumentRequest $record): ?string => collect($record->history)->last()['note'] ?? null)->columnSpanFull()->wrap(),
            ]),
            TextEntry::make('editable_status')->label('Status')->visible(fn (?DocumentRequest $record): bool => $record && ! self::isReadOnly($record))->state(fn (DocumentRequest $record): string => DocumentRequest::STATUSES[$record->status])->badge(),
            Section::make('Pengaju')->visible(fn (?DocumentRequest $record): bool => (bool) $record?->public_token && ! self::isReadOnly($record))->columns(2)->schema([
                TextEntry::make('requester_name')->label('Nama'), TextEntry::make('requester_phone')->label('WhatsApp'),
                TextEntry::make('requester_division')->label('Divisi'), TextEntry::make('request_reason')->label('Keperluan')->columnSpanFull(),
                TextEntry::make('other_business_name')->label('Badan usaha lainnya')->visible(fn (?DocumentRequest $record): bool => filled($record?->other_business_name)),
            ]),
            TextEntry::make('editable_review_note')->label('Catatan pemeriksa')->visible(fn (?DocumentRequest $record): bool => $record && ! self::isReadOnly($record) && in_array($record->status, ['revision', 'rejected'], true))
                ->state(fn (DocumentRequest $record): ?string => collect($record->history)->last()['note'] ?? null)->extraAttributes(['style' => 'white-space: pre-line']),
            Section::make('Data pengajuan')->visible(fn (?DocumentRequest $record): bool => ! self::isReadOnly($record))->columns(2)->schema([
                Select::make('company_id')->label('Badan Usaha')->options(fn (): array => Company::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()->disabledOn('edit'),
                Select::make('document_type_id')->label('Jenis Dokumen')->options(fn (): array => DocumentType::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()->live()->disabledOn('edit')
                    ->afterStateUpdated(function (Set $set): void {
                        $set('details', []);
                        $set('attachments', []);
                    }),
                TextInput::make('title')->label('Judul')->required()->maxLength(255),
                Select::make('purpose')->label('Pengajuan')->options(['new' => 'Baru', 'renewal' => 'Perpanjangan', 'amendment' => 'Adendum'])->default('new')->required()->live(),
                TextInput::make('partner_name')->label('Mitra')->required()->maxLength(255),
                Select::make('pic_user_id')->label('PIC internal')->options(fn (): array => User::pluck('name', 'id')->all())->default(fn (): int => auth()->id())->searchable()->required(),
                TextInput::make('partner_pic')->label('PIC mitra')->maxLength(255),
                TextInput::make('partner_contact')->label('Kontak mitra')->maxLength(255),
            ])->disabled(fn (?DocumentRequest $record): bool => $record && ! $record->editableBy(auth()->user())),
            Section::make('Rencana')->visible(fn (?DocumentRequest $record): bool => ! self::isReadOnly($record))->columns(2)->schema([
                DatePicker::make('start_date')->label('Mulai'),
                DatePicker::make('expiry_date')->label('Berakhir')->afterOrEqual('start_date'),
                DatePicker::make('target_date')->label('Target selesai'),
                Toggle::make('has_cost')->label('Ada biaya')->default(false)->live(),
                TextInput::make('amount')->label('Nilai (Rp)')->numeric()->minValue(0)->maxValue(9999999999999999)->visible(fn (Get $get): bool => (bool) $get('has_cost')),
                Textarea::make('payment_terms')->label('Pembayaran')->rows(2)->maxLength(4000)->visible(fn (Get $get): bool => (bool) $get('has_cost')),
                Section::make()->contained(false)->columnSpanFull()->schema(function (Get $get, ?DocumentRequest $record): array {
                    return array_map(function (array $item): TextInput|Textarea|DatePicker {
                        $name = 'details.'.$item['key'];
                        $field = match ($item['type']) {
                            'textarea' => Textarea::make($name)->rows(2)->maxLength(4000),
                            'date' => DatePicker::make($name),
                            'number' => TextInput::make($name)->numeric(),
                            default => TextInput::make($name)->maxLength(4000),
                        };

                        return $field->label($item['label'])->markAsRequired((bool) $item['required']);
                    }, self::requirements($get, $record)['fields'] ?? []);
                }),
            ])->disabled(fn (?DocumentRequest $record): bool => $record && ! $record->editableBy(auth()->user())),
            Section::make('Lampiran')->compact()->schema(function (Get $get, ?DocumentRequest $record): array {
                $requirements = self::requirements($get, $record)['attachments'] ?? [];
                $components = [];
                $optionalComponents = [];
                $requiredCount = 0;
                $completeCount = 0;
                foreach ($requirements as $item) {
                    $needed = match ($item['when'] ?? 'always') {
                        'cost' => (bool) $get('has_cost'),
                        'renewal' => in_array($get('purpose'), ['renewal', 'amendment'], true),
                        default => true,
                    };
                    if (! $needed) {
                        continue;
                    }
                    $required = (bool) $item['required'];
                    if ($required) {
                        $requiredCount++;
                        if (filled(($get('attachments') ?? [])[$item['key']] ?? null)) {
                            $completeCount++;
                        }
                    }
                    if (! $record || $record->editableBy(auth()->user())) {
                        $component = FileUpload::make('attachments.'.$item['key'])->label($item['label'].($required ? ' *' : ''))->disk('local')->visibility('private')->storeFiles(false)
                            ->preventFilePathTampering(allowFilePathUsing: fn (string $file, ?DocumentRequest $record): bool => $record && ($record->attachments[$item['key']] ?? null) === $file)
                            ->getUploadedFileUsing(function (string $file, ?DocumentRequest $record) use ($item): ?array {
                                if (! $record || ($record->attachments[$item['key']] ?? null) !== $file || ! Storage::disk('local')->exists($file)) {
                                    return null;
                                }

                                return ['name' => $item['label'].'.'.pathinfo($file, PATHINFO_EXTENSION), 'size' => Storage::disk('local')->size($file), 'type' => Storage::disk('local')->mimeType($file), 'url' => route('requests.attachment', ['documentRequest' => $record, 'key' => $item['key']])];
                            })
                            ->acceptedFileTypes(config('lms.allowed_mime_types'))->maxSize(config('lms.max_upload_size_kb'))->live();
                    } else {
                        $path = $record->attachments[$item['key']] ?? null;
                        if (! $required && ! $path) {
                            continue;
                        }
                        $previewable = $path && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true);
                        $component = Actions::make([
                            Action::make('preview_'.$item['key'])->label($item['label'])->color('gray')->icon('heroicon-o-eye')->visible((bool) $previewable)
                                ->modalHeading($item['label'])->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                                ->modalContent(fn () => view('requests.attachment-preview', [
                                    'url' => route('requests.attachment', ['documentRequest' => $record, 'key' => $item['key'], 'preview' => 1]),
                                    'label' => $item['label'], 'isImage' => strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf',
                                ])),
                            Action::make('download_'.$item['key'])->label($previewable ? 'Unduh' : $item['label'])->color('gray')->icon('heroicon-o-arrow-down-tray')->disabled(! $path)
                                ->url($path ? route('requests.attachment', ['documentRequest' => $record, 'key' => $item['key']]) : null)->openUrlInNewTab(),
                        ]);
                    }
                    if ($required) {
                        $components[] = $component;
                    } else {
                        $optionalComponents[] = $component;
                    }
                }
                if ($optionalComponents !== []) {
                    $components[] = Section::make('Lampiran tambahan')->contained(false)->collapsed()->schema($optionalComponents);
                }

                return [TextEntry::make('attachment_progress')->label('Kelengkapan')->state($requiredCount ? $completeCount.' / '.$requiredCount.' lampiran wajib' : 'Tidak ada lampiran wajib'), ...$components];
            }),
            Section::make('Detail tambahan')->compact()->collapsed()->columns(2)->visible(fn (?DocumentRequest $record): bool => self::isReadOnly($record))->schema([
                TextEntry::make('purpose')->label('Pengajuan')->formatStateUsing(fn (string $state): string => ['new' => 'Baru', 'renewal' => 'Perpanjangan', 'amendment' => 'Adendum'][$state] ?? $state),
                TextEntry::make('partner_name')->label('Mitra')->visible(fn (DocumentRequest $record): bool => filled($record->partner_name) && $record->partner_name !== ($record->company?->name ?? $record->other_business_name))->wrap(),
                TextEntry::make('partner_pic')->label('PIC mitra')->visible(fn (DocumentRequest $record): bool => filled($record->partner_pic)),
                TextEntry::make('partner_contact')->label('Kontak mitra')->visible(fn (DocumentRequest $record): bool => filled($record->partner_contact)),
                TextEntry::make('start_date')->label('Mulai')->date('d M Y')->visible(fn (DocumentRequest $record): bool => filled($record->start_date)),
                TextEntry::make('expiry_date')->label('Berakhir')->date('d M Y')->visible(fn (DocumentRequest $record): bool => filled($record->expiry_date)),
                TextEntry::make('target_date')->label('Target selesai')->date('d M Y')->visible(fn (DocumentRequest $record): bool => filled($record->target_date)),
                TextEntry::make('amount')->label('Nilai')->money('IDR')->visible(fn (DocumentRequest $record): bool => (bool) $record->has_cost),
                TextEntry::make('payment_terms')->label('Pembayaran')->visible(fn (DocumentRequest $record): bool => (bool) $record->has_cost && filled($record->payment_terms))->wrap(),
                Section::make()->contained(false)->columnSpanFull()->schema(function (?DocumentRequest $record): array {
                    if (! $record) {
                        return [];
                    }

                    return collect($record->requirements['fields'] ?? [])->filter(fn (array $item): bool => filled($record->details[$item['key']] ?? null))
                        ->map(fn (array $item): TextEntry => TextEntry::make('details.'.$item['key'])->label($item['label'])->wrap())->values()->all();
                }),
            ]),
            Section::make('Riwayat')->compact()->collapsed()->visibleOn('edit')->schema([
                TextEntry::make('history_text')->hiddenLabel()->state(fn (DocumentRequest $record): string => collect($record->history)->reverse()->map(fn (array $event): string => DocumentRequest::STATUSES[$event['status']].' · '.$event['user'].' · '.Carbon::parse($event['at'])->format('d M Y H:i').(filled($event['note']) ? "\n".$event['note'] : ''))->implode("\n\n"))->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Notifikasi WhatsApp')->compact()->collapsed()->visibleOn('edit')->schema([
                TextEntry::make('notification_log')->hiddenLabel()->state(fn (DocumentRequest $record): string => $record->notifications()->latest('id')->get()->map(fn ($notification): string => ($notification->recipient_kind === 'pic' ? 'PIC' : 'Pengaju').' · '.match ($notification->status) {
                    'accepted' => 'Diterima WagHub', 'failed' => 'Gagal · '.$notification->attempts.'/5 percobaan', 'cancelled' => 'Digantikan status terbaru', default => 'Menunggu'
                }.' · '.$notification->created_at->timezone(config('lms.reminder_timezone'))->format('d M Y H:i'))->implode("\n"))->extraAttributes(['style' => 'white-space: pre-line'])->placeholder('Belum ada notifikasi'),
            ]),
        ]);
    }
}
