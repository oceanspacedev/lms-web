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
<section class="form-section receipt">
    <div class="receipt-heading"><span>Pengajuan #{{ $submission->id }}</span><span class="status {{ $submission->status }}">{{ $submission->status === 'approved' ? 'Disetujui' : \App\Models\DocumentRequest::STATUSES[$submission->status] }}</span></div>
    @unless($lookup)<h2>{{ $submission->title }}</h2>@endunless
    <p>{{ $submission->revisionNumber() ? 'Revisi ke-'.$submission->revisionNumber() : 'Pengajuan awal' }}</p>
    <dl><div><dt>Pengaju</dt><dd>{{ $submission->applicantName() }}</dd></div><div><dt>Diperbarui</dt><dd>{{ $submission->updated_at->timezone(config('lms.reminder_timezone'))->translatedFormat('d M Y H:i') }}</dd></div></dl>
    @if($lookup)
        <p class="file-hint">Judul pengajuan dan catatan pemeriksa hanya ditampilkan melalui tautan status yang dikirim ke WhatsApp pengaju.</p>
    @elseif($submission->status === 'rejected')
        <div class="notice error"><strong>Alasan penolakan</strong><p>{{ collect($submission->history)->last()['note'] ?? '' }}</p></div>
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
