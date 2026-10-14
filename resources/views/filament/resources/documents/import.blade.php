<x-filament-panels::page>
    @php
        $import = $this->import();
        $unfinished = $this->unfinishedImport();
        $status = $import?->status;
        $step = match (true) {
            $import === null => 2,
            $status === 'checked' => 3,
            default => 4,
        };
        $statusLabels = \App\Models\DocumentImport::ROW_STATUSES;
        $statusColors = \App\Models\DocumentImport::ROW_STATUS_COLORS;
    @endphp
    <style>
        .doc-import { display: grid; gap: 1.5rem; }
        .doc-import__steps { display: flex; flex-wrap: wrap; gap: .5rem; margin: 0; padding: 0; list-style: none; }
        .doc-import__steps li { display: flex; align-items: center; gap: .5rem; padding: .5rem .875rem; border-radius: 999px; font-size: .8125rem; background: var(--gray-100); color: var(--gray-600); }
        .doc-import__steps li[aria-current="step"] { background: var(--color-primary-600, #2563eb); color: white; font-weight: 600; }
        .doc-import__steps li.is-done { background: #f0fdf4; color: #166534; }
        .doc-import__number { display: inline-grid; place-items: center; width: 1.375rem; height: 1.375rem; border-radius: 999px; background: rgba(255,255,255,.35); font-weight: 600; font-variant-numeric: tabular-nums; }
        .doc-import__text { margin: 0 0 1rem; font-size: .875rem; color: var(--gray-600); line-height: 1.6; max-width: 75ch; }
        .doc-import__actions { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-top: 1rem; }
        .doc-import__stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .doc-import__stat { border: 1px solid var(--gray-200); border-radius: .75rem; padding: .875rem 1rem; }
        .doc-import__stat dt { font-size: .8125rem; color: var(--gray-600); }
        .doc-import__stat dd { margin: .25rem 0 0; font-size: 1.5rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .doc-import__check { display: flex; gap: .75rem; align-items: flex-start; margin-top: .75rem; font-size: .875rem; cursor: pointer; }
        .doc-import__check small { display: block; color: var(--gray-600); font-size: .8125rem; margin-top: .125rem; }
        .doc-import__progress { height: .75rem; border-radius: 999px; background: var(--gray-200); overflow: hidden; }
        .doc-import__progress span { display: block; height: 100%; background: var(--color-primary-600, #2563eb); transition: width .3s ease; }
        .doc-import__table-wrap { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: .75rem; }
        .doc-import table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        .doc-import th { text-align: left; padding: .625rem .75rem; background: var(--gray-50); color: var(--gray-700); font-weight: 600; white-space: nowrap; }
        .doc-import td { padding: .625rem .75rem; border-top: 1px solid var(--gray-200); vertical-align: top; }
        .doc-import td.doc-import__line { font-variant-numeric: tabular-nums; color: var(--gray-600); }
        .doc-import__muted { color: var(--gray-600); }
        .doc-import__messages { margin: 0; padding-left: 1rem; min-width: 16rem; }
        .doc-import :is(button, a, input, label):focus-visible { outline: 2px solid var(--color-primary-500); outline-offset: 3px; }
        .dark .doc-import__steps li { background: var(--gray-800); color: var(--gray-300); }
        .dark .doc-import__steps li.is-done { background: #052e16; color: #bbf7d0; }
        .dark :is(.doc-import__text, .doc-import__muted, .doc-import__stat dt, .doc-import__check small, .doc-import td.doc-import__line) { color: var(--gray-400); }
        .dark :is(.doc-import__stat, .doc-import__table-wrap) { border-color: var(--gray-700); }
        .dark .doc-import td { border-color: var(--gray-800); }
        .dark .doc-import th { background: var(--gray-800); color: var(--gray-200); }
        .dark .doc-import__progress { background: var(--gray-700); }
    </style>

    <div class="doc-import">
        <ol class="doc-import__steps" aria-label="Langkah impor">
            @foreach ([1 => 'Unduh template', 2 => 'Unggah', 3 => 'Periksa', 4 => 'Impor'] as $number => $label)
                <li @if ($number === $step) aria-current="step" @elseif ($number < $step) class="is-done" @endif>
                    <span class="doc-import__number">{{ $number }}</span>{{ $label }}
                </li>
            @endforeach
        </ol>

        @if ($unfinished)
            <x-filament::callout color="warning" icon="heroicon-o-clock" heading="Ada impor yang belum selesai">
                <x-slot name="description">
                    Batch #{{ $unfinished->id }} ({{ $unfinished->excel_name }}) berstatus {{ \App\Models\DocumentImport::STATUSES[$unfinished->status] }}. Buka untuk melanjutkan atau membatalkannya.
                </x-slot>
                <x-slot name="controls">
                    <x-filament::button tag="a" size="sm" :href="\App\Filament\Resources\Documents\DocumentResource::getUrl('import', ['batch' => $unfinished->id])">Buka batch #{{ $unfinished->id }}</x-filament::button>
                </x-slot>
            </x-filament::callout>
        @endif

        @if ($import === null)
            <x-filament::section heading="1. Unduh template">
                <p class="doc-import__text">Template berisi sheet <strong>Dokumen</strong> untuk diisi dan sheet <strong>Petunjuk</strong> berisi aturan serta daftar badan usaha, jenis dokumen, dan email PIC yang bisa dipakai. Unduh ulang template bila master data berubah.</p>
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadTemplate">Unduh template Excel</x-filament::button>
            </x-filament::section>

            <x-filament::section heading="2. Unggah file">
                <p class="doc-import__text">Unggah Excel yang sudah diisi dan ZIP berisi file dokumennya. Satu batch maksimal {{ config('lms.import_max_rows') }} baris; arsip besar dibagi beberapa batch. Langkah berikutnya hanya memeriksa, belum ada yang disimpan.</p>
                <form wire:submit="check">
                    {{ $this->form }}
                    <div class="doc-import__actions">
                        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Periksa file</x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        @else
            @php
                $ready = $import->countRows('ready');
                $checkedReady = $import->checkedReadyCount();
                $processed = $import->processedCount();
                $percent = $checkedReady > 0 ? (int) floor($processed / $checkedReady * 100) : 100;
                $problemCount = count(app(\App\Services\DocumentImporter::class)->failedRows($import));
                $impact = $status === 'checked' ? $this->reminderImpact() : null;
                $unusedCount = (int) ($import->notes['unused_count'] ?? 0);
            @endphp
            <div
                wire:key="document-import-{{ $import->id }}"
                x-data="{
                    running: false,
                    onlyProblems: false,
                    async run() {
                        if (this.running) return
                        this.running = true
                        try {
                            while (await $wire.processNextChunk()) {}
                        } finally {
                            this.running = false
                        }
                    },
                    async start() {
                        if (this.running) return
                        if (await $wire.startImport()) await this.run()
                    },
                }"
                x-on:beforeunload.window="if (running) { $event.preventDefault(); $event.returnValue = '' }"
                class="doc-import"
            >
                @if ($status === 'checked')
                    <x-filament::section heading="3. Hasil pemeriksaan" :description="'Batch #'.$import->id.' · '.$import->excel_name.' · '.implode(', ', $import->zip_names)">
                        <dl class="doc-import__stats">
                            <div class="doc-import__stat"><dt>Total baris</dt><dd>{{ $import->total_rows }}</dd></div>
                            <div class="doc-import__stat"><dt>Siap diimpor</dt><dd>{{ $ready }}</dd></div>
                            <div class="doc-import__stat"><dt>Dilewati (sudah ada)</dt><dd>{{ $import->skipped_count }}</dd></div>
                            <div class="doc-import__stat"><dt>Perlu diperbaiki</dt><dd>{{ $import->failed_count }}</dd></div>
                        </dl>
                        @if ($unusedCount > 0)
                            <p class="doc-import__text">{{ $unusedCount }} file di ZIP tidak disebut di Excel dan diabaikan: {{ implode(', ', $import->notes['unused_files'] ?? []) }}{{ $unusedCount > count($import->notes['unused_files'] ?? []) ? ', …' : '' }}</p>
                        @endif
                        @if ($import->failed_count > 0)
                            <p class="doc-import__text">Baris yang perlu diperbaiki tidak ikut diimpor. Unduh daftarnya, perbaiki, lalu impor ulang sebagai batch baru.</p>
                        @endif

                        @if ($ready > 0)
                            <x-filament::callout :color="$impact['due'] > 0 ? 'warning' : 'gray'" icon="heroicon-o-chat-bubble-left-ellipsis" heading="Pengingat WhatsApp">
                                <x-slot name="description">
                                    <span data-reminder-impact>{{ $impact['due'] }} dokumen akan langsung dikirimi pengingat, {{ $impact['expired'] }} sudah kedaluwarsa.</span>
                                    <label class="doc-import__check">
                                        <x-filament::input.checkbox wire:model="skipPastReminders" />
                                        <span>Lewati pengingat yang jadwalnya sudah lewat
                                            <small>Disarankan untuk arsip lama. Pengingat untuk jadwal yang sudah lewat tidak dikirim; jadwal berikutnya tetap berjalan seperti biasa.</small>
                                        </span>
                                    </label>
                                </x-slot>
                            </x-filament::callout>
                        @endif

                        <div class="doc-import__actions">
                            <x-filament::button icon="heroicon-o-arrow-up-tray" x-on:click="start()" x-bind:disabled="running || {{ $ready === 0 ? 'true' : 'false' }}">Impor {{ $ready }} dokumen</x-filament::button>
                            @if ($problemCount > 0)
                                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadFailedRows">Unduh baris bermasalah</x-filament::button>
                            @endif
                            <x-filament::button color="gray" outlined wire:click="cancelImport" wire:confirm="Batalkan batch ini dan unggah ulang file?">Batal, unggah ulang</x-filament::button>
                        </div>
                    </x-filament::section>
                @elseif ($status === 'importing')
                    <x-filament::section heading="4. Impor" :description="'Batch #'.$import->id.' · '.$import->excel_name">
                        <p class="doc-import__text" aria-live="polite">{{ $processed }} dari {{ $checkedReady }} dokumen diproses ({{ $percent }}%).</p>
                        <div class="doc-import__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><span style="width: {{ $percent }}%"></span></div>
                        <p class="doc-import__text" x-show="running" style="margin-top: 1rem;">Sedang mengimpor {{ \App\Services\DocumentImporter::CHUNK_SIZE }} dokumen per tahap. Jangan tutup halaman ini.</p>
                        <div x-show="! running">
                            <p class="doc-import__text" style="margin-top: 1rem;">Impor berhenti sebelum selesai (misalnya koneksi terputus). Lanjutkan untuk memproses sisa dokumen; dokumen yang sudah masuk tidak akan diimpor dua kali.</p>
                            <x-filament::button icon="heroicon-o-play" x-on:click="run()">Lanjutkan impor</x-filament::button>
                        </div>
                    </x-filament::section>
                @else
                    <x-filament::section heading="Laporan impor" :description="'Batch #'.$import->id.' · '.$import->excel_name.' · '.\App\Models\DocumentImport::STATUSES[$status]">
                        @if ($status === 'expired')
                            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle" heading="Batch kedaluwarsa" description="File sementara batch ini sudah dihapus karena lebih dari 24 jam. Baris yang belum diproses ikut di daftar baris gagal; unggah ulang untuk mengimpornya." />
                        @endif
                        <dl class="doc-import__stats">
                            <div class="doc-import__stat"><dt>Berhasil diimpor</dt><dd>{{ $import->imported_count }}</dd></div>
                            <div class="doc-import__stat"><dt>Dilewati (sudah ada)</dt><dd>{{ $import->skipped_count }}</dd></div>
                            <div class="doc-import__stat"><dt>Gagal</dt><dd>{{ $problemCount }}</dd></div>
                        </dl>
                        <div class="doc-import__actions">
                            <x-filament::button tag="a" icon="heroicon-o-folder-open" :href="\App\Filament\Resources\Documents\DocumentResource::getUrl()">Lihat daftar dokumen</x-filament::button>
                            @if ($problemCount > 0)
                                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadFailedRows">Unduh baris gagal (Excel)</x-filament::button>
                            @endif
                            <x-filament::button color="gray" outlined wire:click="startOver">Impor batch berikutnya</x-filament::button>
                        </div>
                    </x-filament::section>
                @endif

                <x-filament::section heading="Rincian per baris" compact>
                    <label class="doc-import__check" style="margin: 0 0 .75rem;">
                        <input type="checkbox" class="fi-checkbox-input" x-model="onlyProblems">
                        <span>Tampilkan hanya baris yang tidak diimpor</span>
                    </label>
                    <div class="doc-import__table-wrap">
                        <table>
                            <thead>
                                <tr><th scope="col">Baris</th><th scope="col">Judul</th><th scope="col">Badan Usaha · Jenis</th><th scope="col">Nama File</th><th scope="col">Status</th><th scope="col">Keterangan</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($import->rows as $row)
                                    <tr wire:key="import-row-{{ $row['line'] }}" @if (in_array($row['status'], ['ready', 'imported'], true)) x-show="! onlyProblems" @endif>
                                        <td class="doc-import__line">{{ $row['line'] }}</td>
                                        <td>{{ $row['values']['title'] ?? '-' }}@if (filled($row['values']['document_number'] ?? null))<br><span class="doc-import__muted">{{ $row['values']['document_number'] }}</span>@endif</td>
                                        <td>{{ $row['values']['company'] ?? '-' }}<br><span class="doc-import__muted">{{ $row['values']['document_type'] ?? '-' }}</span></td>
                                        <td>{{ $row['values']['file_name'] ?? '-' }}</td>
                                        <td>
                                            @if ($row['status'] === 'imported' && $row['document_id'])
                                                <a href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl('view', ['record' => $row['document_id']]) }}"><x-filament::badge :color="$statusColors[$row['status']]">{{ $statusLabels[$row['status']] }}</x-filament::badge></a>
                                            @else
                                                <x-filament::badge :color="$statusColors[$row['status']]">{{ $statusLabels[$row['status']] }}</x-filament::badge>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($row['messages'] !== [])
                                                <ul class="doc-import__messages">@foreach ($row['messages'] as $message)<li>{{ $message }}</li>@endforeach</ul>
                                            @else
                                                <span class="doc-import__muted">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
