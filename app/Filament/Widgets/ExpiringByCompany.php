<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Company;
use App\Models\Document;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ExpiringByCompany extends TableWidget
{
    /**
     * Kolom hitungan: alias => [label, dari hari, sampai hari]. Hari dihitung dari hari ini.
     *
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    public const BUCKETS = [
        'due_30' => ['0-30 hari', 0, 30],
        'due_60' => ['31-60 hari', 31, 60],
        'due_90' => ['61-90 hari', 61, 90],
    ];

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Document::class);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function table(Table $table): Table
    {
        $columns = [TextColumn::make('name')->label('Badan Usaha')->searchable()->weight('medium')->wrap()];
        foreach (self::BUCKETS as $alias => [$label, $from, $to]) {
            $columns[] = TextColumn::make($alias)->label($label)->badge()->alignCenter()
                ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                ->url(fn (Company $record): ?string => $record->{$alias} > 0 ? $this->listUrl($record, [
                    'expiry_range' => ['expiry_from' => today(config('lms.reminder_timezone'))->addDays($from)->toDateString(), 'expiry_until' => today(config('lms.reminder_timezone'))->addDays($to)->toDateString()],
                ]) : null);
        }
        $columns[] = TextColumn::make('overdue')->label('Kedaluwarsa')->badge()->alignCenter()
            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
            ->url(fn (Company $record): ?string => $record->overdue > 0 ? $this->listUrl($record, ['expiry_status' => ['value' => 'expired']]) : null);

        return $table
            ->heading('Dokumen Berakhir per Badan Usaha')
            ->description('Jumlah dokumen aktif menurut sisa masa berlaku, dihitung dari hari ini. Klik angka untuk melihat daftarnya.')
            ->query(function (): Builder {
                abort_unless(static::canView(), 403);

                return Company::query()
                    ->withCount([
                        ...collect(self::BUCKETS)->mapWithKeys(fn (array $bucket, string $alias): array => [
                            "documents as {$alias}" => fn (Builder $documents): Builder => $documents->expiringBetween($bucket[1], $bucket[2]),
                        ])->all(),
                        'documents as overdue' => fn (Builder $documents): Builder => $documents->withExpiryStatus('expired'),
                    ])
                    ->where(fn (Builder $companies): Builder => $companies
                        ->whereHas('documents', fn (Builder $documents): Builder => $documents->expiringBetween(0, 90))
                        ->orWhereHas('documents', fn (Builder $documents): Builder => $documents->withExpiryStatus('expired')))
                    ->orderByDesc('due_30')->orderByDesc('overdue')->orderBy('name');
            })
            ->columns($columns)
            ->paginated([10, 25])
            ->emptyStateHeading('Tidak ada dokumen yang akan berakhir dalam 90 hari')
            ->emptyStateDescription('Dokumen tanpa masa tenggang tidak dihitung.');
    }

    /** @param array<string, array<string, string>> $filters */
    private function listUrl(Company $company, array $filters): string
    {
        return DocumentResource::getUrl('index', ['tableFilters' => ['company' => ['value' => $company->id], ...$filters]]);
    }
}
