<?php

namespace App\Filament\Resources\DocumentRequests\Schemas;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Carbon\CarbonImmutable;
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
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentRequestForm
{
    private static function isReadOnly(?DocumentRequest $record): bool
    {
        return $record && ! $record->editableBy(auth()->user());
    }

    /** Pengajuan dari form publik (/pengajuan), yang tidak menanyakan tujuan, tanggal, maupun biaya. */
    private static function isPublic(DocumentRequest $record): bool
    {
        return $record->public_token !== null;
    }

    /** @return array<string, mixed> */
    private static function requirements(Get $get, ?DocumentRequest $record): array
    {
        if ($record) {
            return $record->requirements;
        }
        $type = DocumentType::find($get('document_type_id'));

        return ['fields' => $type?->request_fields ?? [], 'attachments' => $type?->request_attachments ?? [], 'has_expiry' => (bool) $type?->has_expiry];
    }

    /** Sama dengan aturan DocumentRequest::validateSubmission(): tanggal berakhir wajib saat diajukan bila jenis dokumennya punya masa berlaku. */
    private static function requiresExpiry(Get $get, ?DocumentRequest $record): bool
    {
        return (bool) (self::requirements($get, $record)['has_expiry'] ?? false);
    }

    private static function formatDate(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($date)->translatedFormat('d M Y');
        } catch (Throwable) {
            return is_scalar($date) ? (string) $date : null;
        }
    }

    /** Nilai dalam rupiah dari kolom desimal (string), tanpa lewat float agar angka besar tidak bergeser. */
    private static function formatAmount(mixed $amount): ?string
    {
        if (! is_numeric($amount)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        return 'Rp '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', ltrim($whole, '+')).($fraction !== '' ? ','.str_pad($fraction, 2, '0') : '');
    }

    /** Isian tambahan jenis dokumen (kolom `details`) sebagai teks hanya-baca; null bila belum diisi. */
    private static function formatDetail(mixed $value, string $type): ?string
    {
        if (! is_scalar($value) || ! filled($value)) {
            return null;
        }

        return $type === 'date' ? self::formatDate($value) : (string) $value;
    }

    /**
     * Isian tambahan pengajuan sebagai entri hanya-baca, mengikuti isian yang berlaku saat pengajuan dibuat (`requirements`).
     *
     * @return list<TextEntry>
     */
    private static function detailEntries(?DocumentRequest $record): array
    {
        $entries = [];
        foreach ($record?->requirements['fields'] ?? [] as $item) {
            if (! is_array($item) || blank($item['key'] ?? null)) {
                continue;
            }
            $key = (string) $item['key'];
            $type = (string) ($item['type'] ?? 'text');
            $entry = TextEntry::make('detail_'.$key)->label((string) ($item['label'] ?? $key))->placeholder('Belum diisi')->wrap()
                ->state(fn (DocumentRequest $record): ?string => self::formatDetail($record->details[$key] ?? null, $type));
            if ($type === 'textarea') {
                $entry->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']);
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Riwayat pengajuan untuk staf, terbaru di atas. Menampilkan nama pelaku apa adanya dan waktu dalam zona waktu aplikasi.
     *
     * @return list<array{label: string, actor: string, at: ?CarbonImmutable, note: ?string}>
     */
    private static function historyEntries(?DocumentRequest $record): array
    {
        $entries = [];
        $previous = null;
        foreach ($record?->history ?? [] as $event) {
            $status = (string) ($event['status'] ?? '');
            $entries[] = [
                'label' => match (true) {
                    $status === 'draft' => 'Draf dibuat',
                    $status === 'submitted' && $previous === 'revision' => 'Revisi dikirim',
                    default => DocumentRequest::TIMELINE_LABELS[$status] ?? DocumentRequest::STATUSES[$status] ?? $status,
                },
                'actor' => filled($event['user'] ?? null) ? (string) $event['user'] : '—',
                'at' => filled($event['at'] ?? null) ? CarbonImmutable::parse($event['at'])->timezone(config('lms.reminder_timezone')) : null,
                'note' => filled($event['note'] ?? null) ? (string) $event['note'] : null,
            ];
            $previous = $status;
        }

        return array_reverse($entries);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make('Ringkasan')->compact()->columns(2)->columnSpan(['default' => 1, 'lg' => 2])->visible(fn (?DocumentRequest $record): bool => self::isReadOnly($record))->schema([
                TextEntry::make('status_label')->label('Status')->state(fn (DocumentRequest $record): string => DocumentRequest::STATUSES[$record->status])->badge(),
                TextEntry::make('submitted_date')->label('Diajukan')->state(fn (DocumentRequest $record): ?string => collect($record->history)->firstWhere('status', 'submitted')['at'] ?? null)
                    ->dateTime('d M Y H:i')->timezone(config('lms.reminder_timezone'))->placeholder('Belum diajukan'),
                TextEntry::make('documentType.name')->label('Jenis dokumen')->wrap(),
                TextEntry::make('business_name')->label('Badan usaha')->state(fn (DocumentRequest $record): ?string => $record->company?->name ?? $record->other_business_name)->wrap(),
                TextEntry::make('partner_name')->label('Pihak lawan / mitra')->state(fn (DocumentRequest $record): ?string => $record->counterpartyName())->placeholder('—')->wrap(),
                TextEntry::make('pic.name')->label('PIC')->placeholder('—'),
                TextEntry::make('revision_number')->label('Versi pengajuan')->state(fn (DocumentRequest $record): string => $record->revisionNumber() ? 'Revisi ke-'.$record->revisionNumber() : 'Pengajuan awal'),
                TextEntry::make('submission_reason')->label('Keperluan')->state(fn (DocumentRequest $record): string => $record->request_reason ?: $record->title)->columnSpanFull()->wrap(),
                TextEntry::make('review_note')->label('Catatan pemeriksa')->visible(fn (DocumentRequest $record): bool => in_array($record->status, ['revision', 'rejected'], true))
                    ->state(fn (DocumentRequest $record): ?string => collect($record->history)->last()['note'] ?? null)->columnSpanFull()->wrap(),
            ]),
            Section::make('Pengaju')->key('applicant_summary')->compact()->visible(fn (?DocumentRequest $record): bool => self::isReadOnly($record))->schema([
                TextEntry::make('applicant_name')->label('Nama')->state(fn (DocumentRequest $record): ?string => $record->requester_name ?? $record->requester?->name)->wrap(),
                TextEntry::make('requester_phone')->label('WhatsApp')->visible(fn (DocumentRequest $record): bool => filled($record->requester_phone)),
                TextEntry::make('requester_division')->label('Divisi')->visible(fn (DocumentRequest $record): bool => filled($record->requester_division))->wrap(),
            ]),
            TextEntry::make('editable_status')->columnSpanFull()->label('Status')->visible(fn (?DocumentRequest $record): bool => $record && ! self::isReadOnly($record))->state(fn (DocumentRequest $record): string => DocumentRequest::STATUSES[$record->status])->badge(),
            Section::make('Pengaju')->columnSpanFull()->visible(fn (?DocumentRequest $record): bool => (bool) $record?->public_token && ! self::isReadOnly($record))->columns(2)->schema([
                TextEntry::make('requester_name')->label('Nama'), TextEntry::make('requester_phone')->label('WhatsApp'),
                TextEntry::make('requester_division')->label('Divisi'), TextEntry::make('request_reason')->label('Keperluan')->columnSpanFull(),
                TextEntry::make('other_business_name')->label('Badan usaha lainnya')->visible(fn (?DocumentRequest $record): bool => filled($record?->other_business_name)),
            ]),
            TextEntry::make('editable_review_note')->columnSpanFull()->label('Catatan pemeriksa')->visible(fn (?DocumentRequest $record): bool => $record && ! self::isReadOnly($record) && in_array($record->status, ['revision', 'rejected'], true))
                ->state(fn (DocumentRequest $record): ?string => collect($record->history)->last()['note'] ?? null)->extraAttributes(['style' => 'white-space: pre-line']),
            Section::make('Data pengajuan')->columnSpanFull()->visible(fn (?DocumentRequest $record): bool => ! self::isReadOnly($record))->columns(2)->schema([
                Select::make('company_id')->label('Badan Usaha')->options(fn (): array => Company::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()->disabledOn('edit'),
                Select::make('document_type_id')->label('Jenis Dokumen')->options(fn (): array => DocumentType::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()->live()->disabledOn('edit')
                    ->afterStateUpdated(function (Set $set): void {
                        $set('details', []);
                        $set('attachments', []);
                    }),
                TextInput::make('title')->label('Judul')->required()->maxLength(255),
                Select::make('purpose')->label('Tujuan Pengajuan')->options(DocumentRequest::PURPOSES)->default('new')->required()->live(),
                TextInput::make('partner_name')->label('Pihak lawan / mitra')->maxLength(255)
                    ->helperText('Opsional. Kosongkan bila dokumen tidak punya pihak lawan, misalnya akta pendirian.'),
                Select::make('pic_user_id')->label('PIC internal')->options(fn (?DocumentRequest $record): array => User::activeOrKeep($record?->pic_user_id)->pluck('name', 'id')->all())->default(fn (): int => auth()->id())->searchable()->required(),
                TextInput::make('partner_pic')->label('PIC mitra')->maxLength(255),
                TextInput::make('partner_contact')->label('Kontak mitra')->maxLength(255),
            ])->disabled(fn (?DocumentRequest $record): bool => $record && ! $record->editableBy(auth()->user())),
            Section::make('Rencana')->columnSpanFull()->visible(fn (?DocumentRequest $record): bool => ! self::isReadOnly($record))->columns(2)->schema([
                DatePicker::make('start_date')->label('Mulai')->markAsRequired()->helperText('Wajib diisi saat mengajukan.'),
                DatePicker::make('expiry_date')->label('Berakhir')->afterOrEqual('start_date')
                    ->markAsRequired(fn (Get $get, ?DocumentRequest $record): bool => self::requiresExpiry($get, $record))
                    ->helperText(fn (Get $get, ?DocumentRequest $record): ?string => self::requiresExpiry($get, $record) ? 'Wajib diisi saat mengajukan.' : null),
                DatePicker::make('target_date')->label('Target selesai'),
                Toggle::make('has_cost')->label('Ada biaya')->default(false)->live(),
                TextInput::make('amount')->label('Nilai (Rp)')->numeric()->minValue(0)->maxValue(9999999999999999)->markAsRequired()->helperText('Wajib diisi saat mengajukan.')->visible(fn (Get $get): bool => (bool) $get('has_cost')),
                Textarea::make('payment_terms')->label('Pembayaran')->rows(2)->maxLength(4000)->markAsRequired()->helperText('Wajib diisi saat mengajukan.')->visible(fn (Get $get): bool => (bool) $get('has_cost')),
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
            Section::make('Rincian pengajuan')->key('request_details')->compact()->columnSpanFull()->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->visible(fn (?DocumentRequest $record): bool => self::isReadOnly($record))->schema([
                TextEntry::make('public_form_note')->hiddenLabel()->columnSpanFull()->color('gray')
                    ->state('Diajukan lewat form publik: tujuan pengajuan, tanggal mulai/berakhir, dan biaya tidak ditanyakan kepada pengaju.')
                    ->visible(fn (DocumentRequest $record): bool => self::isPublic($record)),
                TextEntry::make('purpose_label')->label('Tujuan pengajuan')->state(fn (DocumentRequest $record): string => DocumentRequest::PURPOSES[$record->purpose] ?? (string) $record->purpose)
                    ->visible(fn (DocumentRequest $record): bool => ! self::isPublic($record) || $record->purpose !== 'new'),
                TextEntry::make('start_date_label')->label('Mulai')->state(fn (DocumentRequest $record): ?string => self::formatDate($record->start_date))->placeholder('Belum diisi')
                    ->visible(fn (DocumentRequest $record): bool => ! self::isPublic($record) || $record->start_date !== null),
                TextEntry::make('expiry_date_label')->label('Berakhir')->state(fn (DocumentRequest $record): ?string => self::formatDate($record->expiry_date))->placeholder('Belum diisi')
                    ->visible(fn (DocumentRequest $record): bool => $record->expiry_date !== null || (! self::isPublic($record) && (bool) ($record->requirements['has_expiry'] ?? false))),
                TextEntry::make('target_date_label')->label('Target selesai')->state(fn (DocumentRequest $record): ?string => self::formatDate($record->target_date))->visible(fn (DocumentRequest $record): bool => $record->target_date !== null),
                TextEntry::make('partner_pic_label')->label('PIC mitra')->state(fn (DocumentRequest $record): ?string => $record->partner_pic)->visible(fn (DocumentRequest $record): bool => filled($record->partner_pic))->wrap(),
                TextEntry::make('partner_contact_label')->label('Kontak mitra')->state(fn (DocumentRequest $record): ?string => $record->partner_contact)->visible(fn (DocumentRequest $record): bool => filled($record->partner_contact))->wrap(),
                TextEntry::make('cost_label')->label('Biaya')->state(fn (DocumentRequest $record): string => $record->has_cost ? 'Ada biaya' : 'Tanpa biaya')
                    ->visible(fn (DocumentRequest $record): bool => ! self::isPublic($record) || $record->has_cost),
                TextEntry::make('amount_label')->label('Nilai')->state(fn (DocumentRequest $record): ?string => self::formatAmount($record->amount))->placeholder('Belum diisi')->visible(fn (DocumentRequest $record): bool => $record->has_cost),
                TextEntry::make('payment_terms_label')->label('Pembayaran')->state(fn (DocumentRequest $record): ?string => $record->payment_terms)->placeholder('Belum diisi')->visible(fn (DocumentRequest $record): bool => $record->has_cost)
                    ->columnSpanFull()->wrap()->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Isian tambahan')->key('request_extra_fields')->compact()->columnSpanFull()->columns(['default' => 1, 'sm' => 2])
                ->visible(fn (?DocumentRequest $record): bool => self::isReadOnly($record) && self::detailEntries($record) !== [])
                ->schema(fn (?DocumentRequest $record): array => self::detailEntries($record)),
            Section::make('Lampiran')->compact()->columnSpanFull()->columns(fn (?DocumentRequest $record): int => self::isReadOnly($record) ? 2 : 1)->schema(function (Get $get, ?DocumentRequest $record): array {
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
                                ->iconButton((bool) $previewable)->tooltip($previewable ? 'Unduh '.$item['label'] : null)
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
                    $components[] = Section::make('Lampiran tambahan')->contained(false)->columnSpanFull()->collapsed()->schema($optionalComponents);
                }

                return [TextEntry::make('attachment_progress')->label('Kelengkapan')->hiddenLabel()->columnSpanFull()->state($requiredCount ? $completeCount.' / '.$requiredCount.' lampiran wajib' : 'Tidak ada lampiran wajib'), ...$components];
            }),
            Section::make('Riwayat')->key('request_history')->compact()->columnSpanFull()->visible(fn (?DocumentRequest $record): bool => (bool) $record?->exists)->schema([
                View::make('filament.resources.document-requests.history')->viewData(fn (?DocumentRequest $record): array => ['entries' => self::historyEntries($record)]),
            ]),
        ]);
    }
}
