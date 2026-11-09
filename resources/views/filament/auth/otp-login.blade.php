@php
    use Illuminate\View\ComponentAttributeBag;

    use function Filament\Support\generate_icon_html;
@endphp

<x-mekaya::auth-card>
    <header class="flex flex-col items-center justify-center py-3">
        <div class="flex items-center justify-center space-y-2 rounded-lg bg-white p-2 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700/80">
            {{
                generate_icon_html(
                    $this->getHeaderIcon(),
                    attributes: (new ComponentAttributeBag)->class(['size-5']),
                )
            }}
        </div>

        <h1 class="mt-4 font-heading text-lg font-medium text-gray-950 dark:text-white">
            {{ $this->getHeading() }}
        </h1>

        @if (filled($subheading = $this->getSubheading()))
            <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ $subheading }}
            </p>
        @endif
    </header>

    {{-- Magic link ditukar setelah halaman tampil, bukan saat GET; lihat EmailLogin::$magicToken. --}}
    <div class="mt-8" @if (filled($initAction = $this->getInitAction())) wire:init="{{ $initAction }}" @endif>
        {{ $this->content }}
    </div>
</x-mekaya::auth-card>
