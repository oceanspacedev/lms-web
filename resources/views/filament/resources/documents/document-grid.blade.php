<div class="document-grid" aria-label="Dokumen dalam tampilan grid">
    @foreach ($records as $record)
        @php
            $version = $record->currentVersion;
            $format = match ($version?->mime_type) {
                'application/pdf' => 'PDF',
                'application/msword' => 'DOC',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
                'image/jpeg' => 'JPG',
                'image/png' => 'PNG',
                default => strtoupper(pathinfo($version?->file_name ?? '', PATHINFO_EXTENSION)) ?: 'FILE',
            };
            $isImage = in_array($format, ['JPG', 'PNG'], true);
            $status = $record->expiry_status;
            $canView = \App\Filament\Resources\Documents\DocumentResource::canView($record);
            $fileSize = $version ? \Illuminate\Support\Number::fileSize($version->file_size, precision: 1) : 'Belum ada file';
            $actions = collect($table->getRecordActions())
                ->map(fn ($action) => $action->getClone()->record($record))
                ->filter(fn ($action) => $action->isVisible())
                ->all();
        @endphp

        <article class="document-card" wire:key="{{ $this->getId() }}.table.records.{{ $record->getKey() }}">
            <div class="document-card__preview" data-format="{{ strtolower($format) }}">
                <x-filament::icon :icon="$isImage ? 'heroicon-o-photo' : 'heroicon-o-document-text'" class="document-card__file-icon" aria-hidden="true" />
                <span class="document-card__format" aria-hidden="true">{{ $format }}</span>
                @if (count($actions))
                    <div class="document-card__menu">
                        {{ \Filament\Actions\ActionGroup::make($actions)
                            ->label('Aksi dokumen '.$record->title)
                            ->icon('heroicon-o-ellipsis-horizontal')
                            ->iconButton()->color('gray')->dropdownPlacement('bottom-end')
                            ->table($table)->livewire($this)->record($record) }}
                    </div>
                @endif
            </div>

            <div class="document-card__body">
                <div class="document-card__file-meta">
                    <span>{{ $format }} <span aria-hidden="true">·</span> {{ $fileSize }}</span>
                </div>

                <h3 class="document-card__title" title="{{ $version?->file_name }}">
                    @if ($canView)
                        <a href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl('view', ['record' => $record]) }}">{{ $record->title }}</a>
                    @else
                        {{ $record->title }}
                    @endif
                </h3>
                <p class="document-card__company">{{ $record->company?->name ?? '—' }}</p>

                <div class="document-card__status-row">
                    <x-filament::badge :color="\App\Models\Document::EXPIRY_STATUS_COLORS[$status]">
                        {{ \App\Models\Document::EXPIRY_STATUSES[$status] }}
                    </x-filament::badge>
                </div>
            </div>
        </article>
    @endforeach
</div>
