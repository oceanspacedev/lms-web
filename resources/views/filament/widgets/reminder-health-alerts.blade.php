<x-filament-widgets::widget>
    @if ($alerts !== [])
        <x-filament::section heading="Perlu Perhatian: Pengingat WhatsApp" icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <ul class="space-y-2">
                @foreach ($alerts as $alert)
                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm" data-alert="{{ $alert['key'] }}">
                        <span class="flex items-center gap-2">
                            <x-filament::badge :color="$alert['level']">{{ $alert['level'] === 'danger' ? 'Penting' : 'Perhatian' }}</x-filament::badge>
                            {{ $alert['message'] }}
                        </span>
                        @if ($alert['url'])
                            <x-filament::link :href="$alert['url']" size="sm">Periksa</x-filament::link>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
