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
    <small id="status-link-note">Tautan ini juga dikirim ke WhatsApp pengaju. Siapa pun yang memegang tautan ini dapat melihat status pengajuan, dan bila pengajuan perlu direvisi, dapat mengubah lalu mengirim ulang pengajuan. Jadi jangan dibagikan ke orang lain.</small>
</section>
@endif
@if($lookup && $submission->status === 'revision')
    <div class="notice" role="status"><strong>Pengajuan perlu direvisi</strong><p>Buka tautan revisi yang dikirim ke WhatsApp pengaju untuk memperbaiki dan mengirim ulang pengajuan. Revisi tidak bisa dilakukan dari halaman ini.</p></div>
@endif
@if(! $lookup && $errors->any())
    <div class="notice error" role="alert"><strong>Permintaan belum berhasil.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif
@if(! $lookup && session('status_done'))
    <div class="notice" role="status"><strong>Berhasil disimpan.</strong></div>
@endif
<section class="form-section receipt">
    <div class="receipt-heading"><span>Pengajuan #{{ $submission->id }}</span><span class="status {{ $submission->status }}">{{ \App\Models\DocumentRequest::STATUSES[$submission->status] }}</span></div>
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
    @if($canSign)
        @if($submission->draft_path)
            <p><a class="text-link" href="{{ route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'draft']) }}">Unduh draf dokumen</a>@if($submission->draft_name) <small>({{ $submission->draft_name }})</small>@endif</p>
        @endif
        @if($submission->status === 'handover')
            @php($lateReasons = $submission->lateReasons)
            <p><strong>Batas penyerahan dokumen fisik:</strong> {{ $submission->handoverDueDate()?->translatedFormat('d M Y') }}
                @if($submission->isHandoverLate())
                    (terlambat {{ $submission->daysLate() }} hari)
                @else
                    (sisa {{ max(0, \App\Models\DocumentRequest::HANDOVER_DAYS - ($submission->daysSinceSigned() ?? 0)) }} hari)
                @endif
            </p>
            <p class="file-hint">Serahkan dokumen fisik bertanda tangan ke tim legal sebelum batas ini.</p>
            @if($submission->signed_path)
                <p><a class="text-link" href="{{ route('requests.public.file', ['token' => $submission->public_token, 'kind' => 'signed']) }}">Unduh scan yang sudah diunggah</a>@if($submission->signed_name) <small>({{ $submission->signed_name }})</small>@endif</p>
            @endif
        @endif
        <form method="post" enctype="multipart/form-data" action="{{ route('requests.public.scan', ['token' => $submission->public_token]) }}">
            @csrf
            <div class="field">
                <label for="signed-scan">{{ $submission->status === 'handover' ? 'Ganti scan dokumen bertanda tangan' : 'Unggah scan dokumen bertanda tangan' }}</label>
                <input id="signed-scan" type="file" name="scan" accept="{{ $acceptAttribute }}" required>
                <small>Jenis berkas: {{ implode(', ', config('lms.allowed_extensions')) }}. Ukuran maksimal {{ number_format(config('lms.max_upload_size_kb') / 1024, 0) }} MB.</small>
            </div>
            <button type="submit">Unggah scan</button>
        </form>
        @if($submission->status === 'handover' && $submission->isHandoverLate())
            <div class="notice error"><strong>Terlambat</strong>
                <p>Dokumen fisik belum diterima dan batas penyerahan sudah lewat. Tuliskan alasan keterlambatan di bawah ini. Alasan tidak perlu disetujui dan tidak memperpanjang batas.</p>
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
        @endif
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
                <div class="timeline-title"><strong>{{ $entry['label'] }}</strong><span>{{ $entry['actor'] }}</span></div>
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
