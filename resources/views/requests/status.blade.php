@extends('requests.layout')
@section('title', 'Status Pengajuan')
@section('content')
<div class="page-heading"><h1>{{ session('submitted') ? 'Pengajuan berhasil' : 'Status pengajuan' }}</h1><p>Simpan tautan ini untuk melihat perkembangan pengajuan.</p></div>
<section class="form-section receipt">
    <div class="receipt-heading"><span>Pengajuan #{{ $submission->id }}</span><span class="status {{ $submission->status }}">{{ $submission->status === 'approved' ? 'Disetujui' : \App\Models\DocumentRequest::STATUSES[$submission->status] }}</span></div>
    <h2>{{ $submission->title }}</h2>
    <p>{{ $submission->revisionNumber() ? 'Revisi ke-'.$submission->revisionNumber() : 'Pengajuan awal' }}</p>
    <dl><div><dt>Pengaju</dt><dd>{{ $submission->applicantName() }}</dd></div><div><dt>Diperbarui</dt><dd>{{ $submission->updated_at->timezone(config('lms.reminder_timezone'))->format('d M Y H:i') }}</dd></div></dl>
    @if($submission->status === 'rejected')<div class="notice error"><strong>Alasan penolakan</strong><p>{{ collect($submission->history)->last()['note'] ?? '' }}</p></div>@endif
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
                @if($entry['at'])<time datetime="{{ $entry['at']->toIso8601String() }}">{{ $entry['at']->format('d M Y H:i') }} WIB</time>@endif
                @if($entry['note'])<p>{{ $entry['note'] }}</p>@endif
            </li>
        @endforeach
    </ol>
    <p class="file-hint">Notifikasi status dikirim ke WhatsApp pengaju.</p>
</section>
<a class="text-link" href="{{ route('requests.public.create') }}">Buat pengajuan lain</a>
@endsection
