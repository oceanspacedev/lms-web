@extends('requests.layout')
@section('title', 'Status Pengajuan')
@section('content')
@php
    $lookup = $lookup ?? false;
    $statusLink = $lookup ? null : route('requests.public.status', ['token' => $submission->public_token]);
@endphp
<div class="page-heading"><h1>{{ ! $lookup && session('submitted') ? 'Pengajuan berhasil' : 'Status pengajuan' }}</h1><p>{{ $lookup ? 'Halaman ini hanya untuk melihat status pengajuan.' : 'Simpan tautan ini untuk melihat perkembangan pengajuan.' }}</p></div>
@if($statusLink)
<section class="link-card" data-copy-link>
    <label for="status-link">Tautan status pengajuan</label>
    <div class="copy-link">
        <input id="status-link" type="text" readonly value="{{ $statusLink }}" aria-describedby="status-link-note">
        <button type="button" data-copy-button>Salin tautan</button>
    </div>
    <small class="copy-feedback" role="status" aria-live="polite" data-copy-feedback></small>
    <small id="status-link-note">Tautan ini juga dikirim ke WhatsApp pengaju. Siapa pun yang memegang tautan ini dapat melihat status, mengunggah dan mengunduh dokumen, menulis alasan keterlambatan, dan mengubah pengajuan bila perlu direvisi. Jadi jangan dibagikan ke orang lain.</small>
</section>
@endif
@if($lookup && $submission->status === 'revision')
    <div class="notice" role="status"><strong>Pengajuan perlu direvisi</strong><p>Buka tautan revisi yang dikirim ke WhatsApp pengaju untuk memperbaiki dan mengirim ulang pengajuan. Revisi tidak bisa dilakukan dari halaman ini.</p></div>
@endif
@if(! $lookup && $errors->any())
    <div class="notice error" role="alert"><strong>Permintaan belum berhasil.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif
@if(! $lookup && session('status_done'))
    <div class="notice success" role="status"><strong>{{ is_string(session('status_done')) ? session('status_done') : 'Berhasil disimpan.' }}</strong></div>
@endif
@if($lookup && in_array($submission->status, ['signing', 'handover'], true))
    @if($submission->status === 'signing')
        <div class="notice" role="status"><strong>Menunggu soft file bertanda tangan</strong><p>Draft sudah diserahkan. Pengaju perlu mengunduh draft, menandatanganinya, lalu mengunggah soft file-nya lewat tautan status yang dikirim ke WhatsApp pengaju. Halaman ini hanya untuk melihat status.</p></div>
    @else
        <div class="notice" role="status"><strong>Menunggu dokumen asli</strong><p>Soft file sudah diterima. Pengaju perlu menyerahkan dokumen asli bertanda tangan ke tim Legal. Bila terlambat, alasannya bisa ditulis lewat tautan status yang dikirim ke WhatsApp pengaju.</p></div>
    @endif
@endif
@if($submission->status === 'reviewing')
    <div class="notice" role="status"><strong>Pengajuan sedang diperiksa</strong><p>Tim legal sedang memeriksa pengajuan. Pengaju dikabari lewat WhatsApp saat draft mulai disusun atau bila ada yang perlu diperbaiki.</p></div>
@elseif($submission->status === 'drafting')
    <div class="notice" role="status"><strong>Draft sedang disusun</strong><p>Tim legal sedang menyusun draft dokumen. Pengaju dikabari lewat WhatsApp saat draft siap diunduh.</p></div>
@elseif($submission->status === 'scanning')
    <div class="notice" role="status"><strong>Dokumen asli sedang discan</strong><p>Dokumen asli sudah diterima tim legal dan sedang discan. Pengaju dikabari lewat WhatsApp saat scan bisa diunduh{{ $lookup ? ' lewat tautan status' : ' di halaman ini' }}.</p></div>
@endif
<section class="form-section receipt">
    <div class="receipt-heading"><span>Pengajuan #{{ $submission->id }}</span><span class="status {{ $submission->status }}">{{ \App\Models\DocumentRequest::statusLabel($submission->status) }}</span></div>
    @unless($lookup)<h2>{{ $submission->title }}</h2>@endunless
    <p>{{ $submission->revisionNumber() ? 'Revisi ke-'.$submission->revisionNumber() : 'Pengajuan awal' }}</p>
    <dl><div><dt>Pengaju</dt><dd>{{ $submission->applicantName() }}</dd></div><div><dt>Diperbarui</dt><dd>{{ $submission->updated_at->timezone(config('lms.reminder_timezone'))->translatedFormat('d M Y H:i') }}</dd></div></dl>
    @if($lookup)
        <p class="file-hint">Judul pengajuan dan catatan pemeriksa hanya ditampilkan melalui tautan status yang dikirim ke WhatsApp pengaju.</p>
    @elseif($submission->status === 'rejected')
        <div class="notice error"><strong>Alasan penolakan</strong><p>{{ collect($submission->history)->last()['note'] ?? '' }}</p></div>
    @elseif($submission->status === 'cancelled')
        <div class="notice error"><strong>Alasan pembatalan</strong><p>{{ collect($submission->history)->last(fn ($entry) => ($entry['status'] ?? null) === 'cancelled' && ! isset($entry['event']))['note'] ?? '' }}</p></div>
    @endif
    @php
        $acceptAttribute = implode(',', array_map(fn ($extension) => '.'.$extension, config('lms.allowed_extensions')));
        $canSign = ! $lookup && in_array($submission->status, ['signing', 'handover'], true);
    @endphp
    @if(! $lookup && in_array($submission->status, ['scanning', 'archived'], true) && ($submission->scan_path || $submission->draft_path || $submission->signed_path))
        {{-- Setelah fisik diterima, pengaju tetap bisa mengunduh draft dan soft file-nya; setelah selesai, juga scan dokumen fisik dari tim legal. --}}
        <div @class(['notice', 'success' => $submission->status === 'archived']) role="region" aria-labelledby="request-files-heading">
            <strong id="request-files-heading">Dokumen pengajuan</strong>
            <p>{{ $submission->status === 'archived' ? 'Pengajuan sudah selesai. File berikut tetap bisa diunduh lewat tautan status ini.' : 'File berikut bisa diunduh lewat tautan status ini. Scan dokumen asli menyusul setelah tim legal selesai men-scan.' }}</p>
            <p>
                @if($submission->scan_path)<a class="text-link" href="{{ route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'scan']) }}"><strong>Unduh scan dokumen asli (bertanda tangan &amp; cap)</strong></a>@if($submission->scan_name) <small>({{ $submission->scan_name }})</small>@endif<br>@endif
                @if($submission->draft_path)<a class="text-link" href="{{ route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'draft']) }}">Unduh draft dokumen</a>@if($submission->draft_name) <small>({{ $submission->draft_name }})</small>@endif<br>@endif
                @if($submission->signed_path)<a class="text-link" href="{{ route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'signed']) }}">Unduh soft file bertanda tangan yang Anda unggah</a>@if($submission->signed_name) <small>({{ $submission->signed_name }})</small>@endif @endif
            </p>
        </div>
    @endif
    @if($canSign)
        @php($draftLink = $submission->draft_path ? route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'draft']) : null)
        @if($submission->status === 'signing')
            <div class="notice"><strong>Langkah selanjutnya</strong>
                <ol>
                    <li>@if($draftLink)<a class="text-link" href="{{ $draftLink }}">Unduh draft dokumen</a>@if($submission->draft_name) <small>({{ $submission->draft_name }})</small>@endif, lalu cetak.@else Siapkan draft dokumen dari tim legal, lalu cetak. Hubungi tim legal bila belum menerimanya.@endif</li>
                    <li>Tanda tangani, dan beri cap bila perlu.</li>
                    <li>Scan atau foto dokumen yang sudah ditandatangani, lalu unggah soft file-nya di bawah ini{!! $submission->signedUploadDueDate() ? ' paling lambat <strong>'.e($submission->signedUploadDueDate()->translatedFormat('d M Y')).'</strong>' : '' !!}.</li>
                    <li>Serahkan dokumen asli ke tim legal paling lambat {{ \App\Models\DocumentRequest::HANDOVER_DAYS }} hari setelah soft file diunggah. Tim legal akan men-scan dokumen asli, dan scan itu bisa diunduh di halaman ini setelah pengajuan selesai.</li>
                </ol>
            </div>
        @else
            @php($lateReasons = $submission->lateReasons)
            @php($daysLeft = max(0, \App\Models\DocumentRequest::HANDOVER_DAYS - ($submission->daysSinceSigned() ?? 0)))
            <p><strong>Batas penyerahan dokumen asli:</strong> {{ $submission->handoverDueDate()?->translatedFormat('d M Y') }}
                @if($submission->isHandoverLate())
                    (terlambat {{ $submission->daysLate() }} hari)
                @elseif($daysLeft === 0)
                    (hari ini)
                @else
                    (sisa {{ $daysLeft }} hari)
                @endif
            </p>
            @if($submission->isHandoverLate())
                <div class="notice error"><strong>Terlambat</strong>
                    <p>Dokumen asli belum diterima tim legal dan batas penyerahan sudah lewat. Segera serahkan, lalu tuliskan alasan keterlambatan di bawah ini. Alasan tidak perlu disetujui dan tidak memperpanjang batas.</p>
                </div>
                <form method="post" action="{{ route('requests.public.reason', ['token' => $submission->public_token]) }}">
                    @csrf
                    <div class="field">
                        <label for="late-reason">Alasan keterlambatan</label>
                        <textarea id="late-reason" name="reason" maxlength="2000" required>{{ old('reason') }}</textarea>
                    </div>
                    <button type="submit">Kirim alasan</button>
                </form>
                @if($lateReasons->isNotEmpty())
                    <h3 class="timeline-heading">Alasan keterlambatan yang sudah dikirim</h3>
                    <ul>@foreach($lateReasons as $lateReason)<li>{{ $lateReason->created_at->timezone(config('lms.reminder_timezone'))->translatedFormat('d M Y H:i') }} WIB: {{ $lateReason->reason }}</li>@endforeach</ul>
                @endif
            @else
                <p class="file-hint">Serahkan dokumen asli bertanda tangan ke tim legal sebelum batas ini. Tim legal akan menandainya diterima.</p>
            @endif
            <p>
                @if($draftLink)<a class="text-link" href="{{ $draftLink }}">Unduh draft dokumen</a>@if($submission->draft_name) <small>({{ $submission->draft_name }})</small>@endif<br>@endif
                @if($submission->signed_path)<a class="text-link" href="{{ route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'signed']) }}">Unduh soft file yang sudah diunggah</a>@if($submission->signed_name) <small>({{ $submission->signed_name }})</small>@endif @endif
            </p>
        @endif
        <form method="post" enctype="multipart/form-data" action="{{ route('requests.public.scan', ['token' => $submission->public_token]) }}">
            @csrf
            <div class="field">
                <label for="signed-scan">{{ $submission->status === 'handover' ? 'Ganti soft file (bila salah unggah)' : 'Unggah soft file dokumen bertanda tangan' }}</label>
                <input id="signed-scan" type="file" name="scan" accept="{{ $acceptAttribute }}" data-max-kb="{{ config('lms.max_upload_size_kb') }}" required>
                <small>Jenis berkas: {{ implode(', ', config('lms.allowed_extensions')) }}. Ukuran maksimal {{ number_format(config('lms.max_upload_size_kb') / 1024, 0) }} MB.</small>
            </div>
            <button type="submit" @class(['secondary' => $submission->status === 'handover'])>{{ $submission->status === 'handover' ? 'Ganti soft file' : 'Unggah soft file' }}</button>
        </form>
    @endif
    <ol class="progress" aria-label="Tahap pengajuan">
        @foreach($submission->progressSteps() as $step)
            <li class="progress-step {{ $step['state'] }}" @if($step['state'] === 'current') aria-current="step" @endif>
                <span class="progress-dot" aria-hidden="true"></span>
                <span>{{ $step['label'] }}<span class="sr-only"> ({{ ['done' => 'selesai', 'current' => 'sedang berjalan', 'stopped' => 'dihentikan', 'pending' => 'belum'][$step['state']] }})</span></span>
            </li>
        @endforeach
    </ol>
    <h3 class="timeline-heading">Riwayat</h3>
    <ol class="timeline">
        @foreach($submission->publicTimeline() as $entry)
            <li class="timeline-item {{ $entry['tone'] }}">
                <div class="timeline-title"><strong>{{ $entry['label'] }}</strong></div>
                @if($entry['at'])<time datetime="{{ $entry['at']->toIso8601String() }}">{{ $entry['at']->translatedFormat('d M Y H:i') }} WIB</time>@endif
                @if($entry['note'] && ! $lookup)<p>{{ $entry['note'] }}</p>@endif
            </li>
        @endforeach
    </ol>
    <p class="file-hint">Notifikasi status dikirim ke WhatsApp pengaju.</p>
</section>
@if($lookup)<p><a class="text-link" href="{{ route('requests.public.lookup') }}">Cek pengajuan lain</a></p>@endif
<a class="text-link" href="{{ route('requests.public.create') }}">Buat pengajuan lain</a>
@endsection
