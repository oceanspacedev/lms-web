@php
    /** @var list<array{label: string, actor: string, at: ?\Carbon\CarbonImmutable, note: ?string}> $entries */
@endphp
<style>
    .request-history { list-style: none; margin: 0; padding: 0; }
    .request-history__item { position: relative; padding: 0 0 1.25rem 1.25rem; border-left: 2px solid var(--gray-200); margin-left: .3125rem; }
    .request-history__item:last-child { padding-bottom: 0; }
    .request-history__item::before { content: ''; position: absolute; left: calc(-1px - .3125rem); top: .3125rem; width: .625rem; height: .625rem; box-sizing: border-box; border-radius: 9999px; background: var(--gray-400); border: 2px solid #fff; }
    .request-history__item:first-child::before { background: var(--color-primary-500); }
    .request-history__head { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; font-size: .875rem; }
    .request-history__head span { color: var(--gray-600); }
    .request-history__time { display: block; margin-top: .125rem; font-size: .8125rem; color: var(--gray-600); font-variant-numeric: tabular-nums; }
    .request-history__note { margin: .375rem 0 0; font-size: .875rem; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere; max-width: 75ch; }
    .request-history__empty { margin: 0; font-size: .875rem; color: var(--gray-600); }
    .dark .request-history__item { border-color: var(--gray-700); }
    .dark .request-history__item::before { background: var(--gray-500); border-color: var(--gray-900); }
    .dark .request-history__item:first-child::before { background: var(--color-primary-500); }
    .dark :is(.request-history__head span, .request-history__time, .request-history__empty) { color: var(--gray-400); }
</style>
@if ($entries === [])
    <p class="request-history__empty">Belum ada riwayat.</p>
@else
    <ol class="request-history" aria-label="Riwayat pengajuan">
        @foreach ($entries as $entry)
            <li class="request-history__item">
                <div class="request-history__head"><strong>{{ $entry['label'] }}</strong><span>{{ $entry['actor'] }}</span></div>
                @if ($entry['at'])
                    <time class="request-history__time" datetime="{{ $entry['at']->toIso8601String() }}">{{ $entry['at']->translatedFormat('d M Y H:i') }} WIB</time>
                @endif
                @if ($entry['note'])
                    <p class="request-history__note">{{ $entry['note'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
