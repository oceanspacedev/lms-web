@php
    $activeFiltersCount = $this->getTable()->getActiveFiltersCount();
@endphp

<x-filament::dropdown
    class="document-filters"
    placement="bottom-end"
    :shift="true"
    :size="true"
    max-height="min(75vh, 40rem)"
    wire:key="document-filters"
>
    <x-slot name="trigger">
        <x-filament::button color="gray" icon="heroicon-o-funnel" :badge="$activeFiltersCount ?: null">
            Filter
        </x-filament::button>
    </x-slot>

    <div class="document-filters__content">
        <div class="document-filters__heading">
            <h2>Filter Dokumen</h2>
            <x-filament::link tag="button" wire:click="resetTableFiltersForm" wire:loading.attr="disabled">
                Reset
            </x-filament::link>
        </div>

        {{ $this->getTableFiltersForm() }}

        <x-filament::button color="gray" x-on:click="close()">
            Selesai
        </x-filament::button>
    </div>
</x-filament::dropdown>
