@extends('requests.layout')
@section('title', 'Cek Status Pengajuan')
@section('content')
<div class="page-heading"><h1>Cek status pengajuan</h1><p>Masukkan nomor pengajuan dan nomor WhatsApp yang dipakai saat mengajukan. Nomor pengajuan tertera pada pesan WhatsApp dan halaman status, misalnya #12.</p></div>
@if($throttled ?? false)
    <div class="notice error" role="alert"><strong>Terlalu banyak percobaan cek status.</strong><p>Tunggu beberapa saat lalu coba lagi, atau buka tautan status yang dikirim ke WhatsApp pengaju.</p></div>
@elseif(session('lookup_failed'))
    <div class="notice error" role="alert"><strong>Data tidak ditemukan. Periksa nomor pengajuan dan nomor WhatsApp.</strong></div>
@endif
<form id="lookup-form" method="post" action="{{ route('requests.public.lookup.submit') }}">
    @csrf
    <div class="field">
        <label for="lookup-submission-id">Nomor pengajuan <span aria-hidden="true">*</span></label>
        <input id="lookup-submission-id" name="submission_id" type="text" inputmode="numeric" autocomplete="off" maxlength="30" required>
    </div>
    <div class="field">
        <label for="lookup-requester-phone">Nomor WhatsApp pengaju <span aria-hidden="true">*</span></label>
        <input id="lookup-requester-phone" name="requester_phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="25" required>
    </div>
    <div class="form-footer"><span>* Wajib diisi</span><button type="submit">Cek status</button></div>
</form>
<a class="text-link" href="{{ route('requests.public.create') }}">Buat pengajuan baru</a>
@endsection
