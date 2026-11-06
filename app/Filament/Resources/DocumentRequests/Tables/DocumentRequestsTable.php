<?php

namespace App\Filament\Resources\DocumentRequests\Tables;

use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\DocumentRequests\Actions\MarkReceivedAction;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentRequestsTable
{
    /** Panjang maksimal teks Keterangan di tabel; teks lengkap (termasuk alasan keterlambatan) tampil sebagai tooltip. */
    private const HANDOVER_NOTE_LIMIT = 60;

    /** Pola LIKE yang aman: karakter khusus (! % _) dari pencarian dianggap huruf biasa. */
    private static function likePattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))).'%';
    }

    public static function configure(Table $table): Table
    {
        return $table->striped()->modifyQueryUsing(fn (Builder $query): Builder => $query->with('lateReasons'))->paginated([10, 25, 50])->searchPlaceholder('Cari nomor, pengajuan, pihak lawan / mitra, atau pengaju')->columns([
            TextColumn::make('id')->label('No.')->formatStateUsing(fn (int|string $state): string => '#'.$state)->sortable()->visibleFrom('md')
                ->searchable(query: function (Builder $query, string $search): Builder {
                    $number = ltrim(trim($search), '#');

                    return ctype_digit($number) && strlen($number) <= 18 ? $query->orWhere('document_requests.id', (int) $number) : $query;
                }),
            TextColumn::make('title')->label('Pengajuan')->searchable()->weight('medium')->limit(45)->wrap()->tooltip(fn (DocumentRequest $record): string => $record->title)
                ->description(fn (DocumentRequest $record): string => $record->documentType->displayName().($record->revisionNumber() ? ' · Revisi ke-'.$record->revisionNumber() : '')),
            TextColumn::make('partner_name')->label('Pihak Lawan / Mitra')->searchable()->state(fn (DocumentRequest $record): ?string => $record->counterpartyName())->placeholder('—')->limit(30)->wrap()->visibleFrom('md'),
            TextColumn::make('requester_name')->label('Pengaju')->limit(30)->wrap()->visibleFrom('md')->state(fn (DocumentRequest $record): string => $record->applicantDisplayName())
                ->searchable(query: fn (Builder $query, string $search): Builder => $query
                    ->orWhere(fn (Builder $query): Builder => $query->whereNotNull('document_requests.requester_name')
                        ->whereRaw("lower(document_requests.requester_name) like ? escape '!'", [self::likePattern($search)]))
                    ->orWhere(fn (Builder $query): Builder => $query->whereNull('document_requests.requester_name')
                        ->whereHas('requester', fn (Builder $requester): Builder => $requester->whereRaw("lower(users.name) like ? escape '!'", [self::likePattern($search)])))),
            // Status ditambah badge "Belum scan" / "Fisik belum diterima" agar langkah yang belum beres terlihat tanpa membuka detail.
            TextColumn::make('status')->label('Status')->badge()
                ->state(fn (DocumentRequest $record): array => $record->statusBadges())
                ->color(fn (string $state, DocumentRequest $record): string => $record->statusBadgeColor($state))
                ->visibleFrom(TrashActions::statusVisibleFrom()),
            TextColumn::make('handover_note')->label('Keterangan')->state(fn (DocumentRequest $record): ?string => self::handoverNote($record))->placeholder('—')->visibleFrom('md')
                ->limit(self::HANDOVER_NOTE_LIMIT)->wrap()
                ->tooltip(fn (DocumentRequest $record): ?string => mb_strlen((string) self::handoverNote($record)) > self::HANDOVER_NOTE_LIMIT ? self::handoverNote($record) : null)
                ->color(fn (DocumentRequest $record): ?string => $record->status === 'handover' && $record->isHandoverLate() ? 'danger' : ($record->status === 'signing' ? 'warning' : null)),
            TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d M Y')->timezone(config('lms.reminder_timezone'))->sortable()->visibleFrom('md'),
            TrashActions::deletedColumn(),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options(DocumentRequest::STATUSES),
            SelectFilter::make('stage')->label('Tahap')->options([
                'to_review' => 'Perlu diperiksa', 'signing' => 'Menunggu tanda tangan', 'scan_overdue' => 'Belum unggah scan > 7 hari',
                'handover_on_time' => 'Menunggu fisik (belum lewat batas)', 'late' => 'Terlambat', 'late_no_reason' => 'Terlambat, belum ada alasan',
                'archived' => 'Selesai', 'cancelled' => 'Dibatalkan',
            ])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'to_review' => $query->where('status', 'submitted'),
                'signing' => $query->where('status', 'signing'),
                'scan_overdue' => $query->scanOverdue(),
                'handover_on_time' => $query->handoverOnTime(),
                'late' => $query->handoverLate(),
                'late_no_reason' => $query->lateWithoutReason(),
                'archived' => $query->where('status', 'archived'),
                'cancelled' => $query->where('status', 'cancelled'),
                default => $query,
            }),
            Filter::make('waiting_long')->toggle()
                ->label(fn (): string => 'Menunggu lebih dari '.ReminderTemplate::requestReminderSettings()['after_days'].' hari')
                ->query(fn (Builder $query): Builder => $query->waitingLongerThan(ReminderTemplate::requestReminderSettings()['after_days']))
                ->indicator('Menunggu lama'),
            TrashActions::filter(),
        ])
            ->recordActions([
                // Langsung dari daftar tanpa membuka detail; bila scan belum masuk, modalnya meminta scan sekaligus.
                MarkReceivedAction::make()->icon('heroicon-o-inbox-arrow-down')->color('success')->iconButton()->tooltip('Fisik diterima'),
                Action::make('open')->label('Buka')->icon('heroicon-o-eye')->color('gray')->iconButton()->tooltip('Buka pengajuan')->url(fn (DocumentRequest $record): string => DocumentRequestResource::getUrl('edit', ['record' => $record]))
                    ->hidden(fn (DocumentRequest $record): bool => $record->trashed()),
                TrashActions::viewTrashed('pengajuan'),
                TrashActions::delete('pengajuan')->iconButton(),
                TrashActions::restore('pengajuan')->iconButton(),
                TrashActions::forceDelete('pengajuan')->iconButton(),
            ])
            ->toolbarActions(TrashActions::bulkActions('pengajuan'))
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading(TrashActions::emptyStateHeading(fn (HasTable $livewire): string => match (self::emptyReason($livewire)) {
                'search' => 'Pengajuan tidak ditemukan',
                'actionable' => 'Tidak ada pengajuan yang perlu tindakan Anda',
                default => 'Belum ada pengajuan',
            }))
            ->emptyStateDescription(TrashActions::emptyStateDescription('pengajuan', fn (HasTable $livewire): ?string => match (self::emptyReason($livewire)) {
                'search' => 'Ubah kata pencarian atau reset filter untuk melihat pengajuan lainnya.',
                'actionable' => 'Buka tab Semua untuk melihat seluruh pengajuan.',
                default => null,
            }));
    }

    /** Keterangan pemantauan dokumen fisik; kosong untuk status lain dan pengajuan di tempat sampah. lateReasons dimuat bersama daftar agar tidak N+1. */
    private static function handoverNote(DocumentRequest $record): ?string
    {
        if ($record->trashed()) {
            return null;
        }
        if ($record->status === 'handover') {
            if (! $record->isHandoverLate()) {
                return $record->handoverRemainingLabel();
            }

            return 'Terlambat '.$record->daysLate().' hari · '.($record->lateReasons->last()?->reason ?? 'belum ada alasan');
        }

        return $record->status === 'signing' && $record->signed_uploaded_at === null && ($record->daysSinceDraft() ?? 0) > DocumentRequest::SCAN_REMINDER_DAYS
            ? 'Belum unggah scan > 7 hari'
            : null;
    }

    /** Penyebab tabel kosong: pencarian/filter aktif, tab "Perlu tindakan saya", atau memang belum ada pengajuan. */
    private static function emptyReason(HasTable $livewire): string
    {
        if (filled($livewire->getTableSearch()) || $livewire->getTable()->getActiveFiltersCount() > 0) {
            return 'search';
        }

        return $livewire instanceof ListDocumentRequests && $livewire->activeTab === 'actionable' ? 'actionable' : 'none';
    }
}
