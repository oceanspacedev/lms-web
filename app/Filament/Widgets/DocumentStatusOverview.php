<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class DocumentStatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Masa Berlaku Dokumen';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Document::class);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getStats(): array
    {
        abort_unless(static::canView(), 403);

        return collect(Document::EXPIRY_STATUSES)->map(fn (string $label, string $status): Stat => Stat::make(
            $label, Document::query()->withExpiryStatus($status)->count(),
        )->color(Document::EXPIRY_STATUS_COLORS[$status])
            ->url(DocumentResource::getUrl('index', ['tableFilters' => ['expiry_status' => ['value' => $status]]])))->values()->all();
    }
}
