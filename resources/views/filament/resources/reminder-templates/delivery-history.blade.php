<x-filament-panels::page>
    @php
        $deliveries = $this->deliveries();
    @endphp
    <style>
        .wa-history { display: grid; gap: 1.25rem; }
        .wa-history__filters { display: flex; flex-wrap: wrap; align-items: end; gap: 1rem; }
        .wa-history__filters label { display: grid; gap: .5rem; font-size: .8125rem; min-width: 12rem; }
        .wa-history__filters select { padding: .625rem; border: 1px solid var(--gray-300); background: white; color: var(--gray-900); border-radius: .5rem; min-height: 44px; }
        .wa-history__count { color: var(--gray-600); font-size: .8125rem; padding-bottom: .75rem; }
        .wa-history__list { display: grid; border: 1px solid var(--gray-200); border-radius: .75rem; background: white; overflow: hidden; }
        .wa-history__item { padding: 1.25rem; border-bottom: 1px solid var(--gray-200); display: grid; gap: .75rem; }
        .wa-history__item:last-child { border-bottom: 0; }
        .wa-history__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; }
        .wa-history__title { margin: 0; font-size: .875rem; font-weight: 600; }
        .wa-history__meta { margin: 0; font-size: .8125rem; color: var(--gray-600); font-variant-numeric: tabular-nums; }
        .wa-history__status { font-size: .75rem; padding: .25rem .5rem; border-radius: .375rem; background: var(--gray-100); color: var(--gray-700); font-weight: 500; }
        .wa-history__status--failed { background: #fef2f2; color: #b91c1c; }
        .wa-history__status--sent { background: #f0fdf4; color: #166534; }
        .wa-history__status--accepted { background: #eff6ff; color: #1d4ed8; }
        .wa-history details { font-size: .8125rem; }
        .wa-history summary { cursor: pointer; min-height: 32px; }
        .wa-history__message { white-space: pre-line; overflow-wrap: anywhere; line-height: 1.7; margin: .5rem 0; max-width: 75ch; }
        .wa-history__error { margin: 0; font-size: .8125rem; color: #b91c1c; overflow-wrap: anywhere; }
        .wa-history__empty { padding: 3rem 1.25rem; text-align: center; color: var(--gray-600); font-size: .875rem; }
        .wa-history :is(select,summary):focus-visible { outline: 2px solid var(--color-primary-500); outline-offset: 3px; }
        .dark .wa-history__filters select, .dark .wa-history__list { background: var(--gray-900); color: var(--gray-100); border-color: var(--gray-700); }
        .dark .wa-history__item { border-color: var(--gray-800); }
        .dark :is(.wa-history__meta,.wa-history__count,.wa-history__empty) { color: var(--gray-400); }
        .dark .wa-history__status { background: var(--gray-800); color: var(--gray-200); }
        .dark .wa-history__status--failed { background: #450a0a; color: #fecaca; }
        .dark .wa-history__status--sent { background: #052e16; color: #bbf7d0; }
        .dark .wa-history__status--accepted { background: #172554; color: #bfdbfe; }
        .dark .wa-history__error { color: #fecaca; }
        @media (max-width: 640px) { .wa-history__filters label { min-width: 0; flex: 1 1 100%; } }
    </style>
    <div class="wa-history">
        <div class="wa-history__filters">
            <label>Jenis pengiriman<select wire:model.live="source">@foreach ($this->sources() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label>Status<select wire:model.live="deliveryStatus"><option value="all">Semua status</option>@foreach (\App\Models\ReminderLog::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <span class="wa-history__count">{{ $deliveries->total() }} pengiriman</span>
        </div>
        <p class="wa-history__meta">Diterima WagHub berarti permintaan diterima penyedia, belum memastikan pesan sampai ke WhatsApp. Riwayat mengikuti hak akses Anda.</p>
        <div class="wa-history__list" wire:loading.class="opacity-50">
            @forelse ($deliveries as $delivery)
                @php
                    $name = match ($delivery->source) {
                        'test' => 'Pesan uji · '.(match ($delivery->template_event) { 'reminder' => 'Masa berlaku', 'overdue' => 'Setelah kedaluwarsa', 'request_reminder' => 'Pengingat pengajuan', default => (\App\Models\ReminderTemplate::REQUEST_TEMPLATE_LABELS[$delivery->template_event] ?? $delivery->template_event) }),
                        'reminder' => 'Pengingat masa berlaku · Versi #'.$delivery->reference_id,
                        default => 'Notifikasi pengajuan #'.$delivery->reference_id,
                    };
                @endphp
                <article class="wa-history__item" wire:key="delivery-{{ $delivery->source }}-{{ $delivery->id }}">
                    <div class="wa-history__row"><h2 class="wa-history__title">{{ $name }}</h2><span class="wa-history__status wa-history__status--{{ $delivery->status }}">{{ $delivery->status_label }}</span></div>
                    <div class="wa-history__row"><p class="wa-history__meta">{{ $delivery->recipient_phone ?: 'Nomor belum tersedia' }}</p><time class="wa-history__meta">{{ \Carbon\CarbonImmutable::parse($delivery->created_at)->timezone(config('lms.reminder_timezone'))->format('d M Y, H:i') }}</time></div>
                    @if ($delivery->error_message)<p class="wa-history__error">{{ $delivery->error_message }}</p>@endif
                    @if (filled($delivery->message))<details><summary>Lihat pesan</summary><p class="wa-history__message">{{ $delivery->message }}</p></details>@endif
                </article>
            @empty
                <p class="wa-history__empty">Belum ada pengiriman untuk pilihan ini.</p>
            @endforelse
        </div>
        {{ $deliveries->links() }}
    </div>
</x-filament-panels::page>
