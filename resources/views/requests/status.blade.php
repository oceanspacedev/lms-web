@extends('requests.layout')
@section('title', 'Status Pengajuan')
@section('content')
<div class="page-heading"><h1>{{ session('submitted') ? 'Pengajuan berhasil' : 'Status pengajuan' }}</h1><p>Simpan tautan ini untuk melihat perkembangan pengajuan.</p></div>
<section class="form-section receipt">
    <div class="receipt-heading"><span>Pengajuan #{{ $submission->id }}</span><span class="status {{ $submission->status }}">{{ $submission->status === 'approved' ? 'Disetujui' : \App\Models\DocumentRequest::STATUSES[$submission->status] }}</span></div>
    <h2>{{ $submission->title }}</h2>
    <dl><div><dt>Pengaju</dt><dd>{{ $submission->applicantName() }}</dd></div><div><dt>PIC</dt><dd>{{ $submission->pic?->name }}</dd></div><div><dt>Diperbarui</dt><dd>{{ $submission->updated_at->timezone(config('lms.reminder_timezone'))->format('d M Y H:i') }}</dd></div></dl>
    @if($submission->status === 'rejected')<div class="notice error"><strong>Alasan penolakan</strong><p>{{ collect($submission->history)->last()['note'] ?? '' }}</p></div>@endif
    <p class="file-hint">Notifikasi status dikirim ke WhatsApp pengaju.</p>
</section>
<a class="text-link" href="{{ route('requests.public.create') }}">Buat pengajuan lain</a>
@endsection
