<?php

namespace App\Filament\Resources\DocumentRequests\Tables;

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
    /** Pola LIKE yang aman: karakter khusus (! % _) dari pencarian dianggap huruf biasa. */
    private static function likePattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))).'%';
    }

    public static function configure(Table $table): Table
    {
        return $table->striped()->paginated([10, 25, 50])->searchPlaceholder('Cari nomor, pengajuan, pihak lawan / mitra, atau pengaju')->columns([
            TextColumn::make('id')->label('No.')->formatStateUsing(fn (int|string $state): string => '#'.$state)->sortable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    $number = ltrim(trim($search), '#');

                    return ctype_digit($number) && strlen($number) <= 18 ? $query->orWhere('document_requests.id', (int) $number) : $query;
                }),
            TextColumn::make('title')->label('Pengajuan')->searchable()->weight('medium')->limit(45)->wrap()->tooltip(fn (DocumentRequest $record): string => $record->title)
                ->description(fn (DocumentRequest $record): string => $record->documentType->name.($record->revisionNumber() ? ' · Revisi ke-'.$record->revisionNumber() : '')),
            TextColumn::make('partner_name')->label('Pihak Lawan / Mitra')->searchable()->state(fn (DocumentRequest $record): ?string => $record->counterpartyName())->placeholder('—')->limit(30)->wrap(),
            TextColumn::make('requester_name')->label('Pengaju')->limit(30)->wrap()->state(fn (DocumentRequest $record): string => $record->applicantName())
                ->searchable(query: fn (Builder $query, string $search): Builder => $query
                    ->orWhere(fn (Builder $query): Builder => $query->whereNotNull('document_requests.requester_name')
                        ->whereRaw("lower(document_requests.requester_name) like ? escape '!'", [self::likePattern($search)]))
                    ->orWhere(fn (Builder $query): Builder => $query->whereNull('document_requests.requester_name')
                        ->whereHas('requester', fn (Builder $requester): Builder => $requester->whereRaw("lower(users.name) like ? escape '!'", [self::likePattern($search)])))),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => DocumentRequest::STATUSES[$state])->color(fn (string $state): string => match ($state) {
                'archived' => 'success', 'rejected' => 'danger', 'revision' => 'warning', 'draft' => 'gray', default => 'info'
            }),
            TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d M Y')->timezone(config('lms.reminder_timezone'))->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options(DocumentRequest::STATUSES),
            Filter::make('waiting_long')->toggle()
                ->label(fn (): string => 'Menunggu lebih dari '.ReminderTemplate::requestReminderSettings()['after_days'].' hari')
                ->query(fn (Builder $query): Builder => $query->waitingLongerThan(ReminderTemplate::requestReminderSettings()['after_days']))
                ->indicator('Menunggu lama'),
        ])
            ->recordActions([Action::make('open')->label('Buka')->icon('heroicon-o-eye')->iconButton()->tooltip('Buka pengajuan')->url(fn (DocumentRequest $record): string => DocumentRequestResource::getUrl('edit', ['record' => $record]))])->defaultSort('updated_at', 'desc')
            ->emptyStateHeading(fn (HasTable $livewire): string => match (self::emptyReason($livewire)) {
                'search' => 'Pengajuan tidak ditemukan',
                'actionable' => 'Tidak ada pengajuan yang perlu tindakan Anda',
                default => 'Belum ada pengajuan',
            })
            ->emptyStateDescription(fn (HasTable $livewire): ?string => match (self::emptyReason($livewire)) {
                'search' => 'Ubah kata pencarian atau reset filter untuk melihat pengajuan lainnya.',
                'actionable' => 'Buka tab Semua untuk melihat seluruh pengajuan.',
                default => null,
            });
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
