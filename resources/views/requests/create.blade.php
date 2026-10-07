@extends('requests.layout')
@section('title', $submission ? 'Revisi Pengajuan' : 'Pengajuan Dokumen')
@section('content')
@php
    $defaults = $submission?->attributesToArray() ?? ['purpose' => 'new', 'has_cost' => false];
    $selectedType = old('document_type_id', $submission?->document_type_id ?? ($types->count() === 1 ? $types->first()->id : ''));
@endphp
<div class="page-heading"><h1>{{ $submission ? 'Revisi pengajuan #'.$submission->id : 'Pengajuan dokumen' }}</h1><p>Status pengajuan dikirim melalui WhatsApp.</p></div>
@if($submission)
    <div class="notice"><strong>Revisi ke-{{ $submission->revisionNumber() }}</strong><p>{{ collect($submission->history)->last()['note'] ?? '' }}</p></div>
@endif
@if($errors->any())
    <div class="notice error" role="alert"><strong>Periksa kembali isian pengajuan.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@if($errors->has('attachments.*'))<p>Pilih kembali berkas lampiran.</p>@endif</div>
@endif
@if($types->isEmpty())
    <div class="notice">Pengajuan belum tersedia. Hubungi PIC untuk informasi lebih lanjut.</div>
@else
<form id="request-form" method="post" enctype="multipart/form-data" action="{{ $submission ? route('requests.public.revise', ['token' => $submission->public_token]) : route('requests.public.store') }}">
    @csrf
    <div class="honeypot" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
    <section class="form-section"><h2>Pengaju</h2><div class="fields">
        @include('requests.field', ['key' => 'requester_name', 'label' => 'Nama', 'required' => true])
        @include('requests.field', ['key' => 'requester_phone', 'label' => 'Nomor telepon / WhatsApp', 'type' => 'tel', 'required' => true])
        @include('requests.field', ['key' => 'requester_division', 'label' => 'Divisi', 'required' => true])
    </div></section>
    <section class="form-section"><h2>Dokumen</h2><div class="fields">
        <div class="field"><label for="company">Badan usaha <span aria-hidden="true">*</span></label><select id="company" name="company_id" required @disabled((bool) $submission)><option value="">Pilih badan usaha</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected((string)old('company_id', $submission?->company_id) === (string)$company->id)>{{ $company->name }}</option>@endforeach<option value="other" @selected(old('company_id', $submission?->other_business_name ? 'other' : '') === 'other')>Lainnya</option></select></div>
        <div data-other-business hidden>@include('requests.field', ['key' => 'other_business_name', 'label' => 'Nama badan usaha', 'required' => true])</div>
        <div class="field"><label for="document-type">Jenis dokumen <span aria-hidden="true">*</span></label><select id="document-type" name="document_type_id" required @disabled((bool) $submission)><option value="">Pilih jenis dokumen</option>@foreach($types as $documentType)<option value="{{ $documentType->id }}" data-expiry="{{ (int)($schemas[$documentType->id]['has_expiry'] ?? false) }}" @selected((string)$selectedType === (string)$documentType->id)>{{ $documentType->name }}</option>@endforeach</select></div>
        @include('requests.field', ['key' => 'title', 'label' => 'Judul pengajuan', 'required' => true, 'wide' => true])
        @include('requests.field', ['key' => 'request_reason', 'label' => 'Keperluan', 'type' => 'textarea', 'required' => true, 'wide' => true])
    </div>
    </section>
    @if(!$submission || collect($submission->requirements['attachments'] ?? [])->contains('key', 'supporting-document'))
        @php $supportingPath = $submission?->attachments['supporting-document'] ?? null; @endphp
        <div class="field">
            <label for="supporting-document">Dokumen pendukung <span aria-hidden="true">*</span></label>
            @if($supportingPath)<small>Berkas tersimpan. Pilih berkas untuk mengganti.</small>@endif
            <input id="supporting-document" type="file" name="attachments[supporting-document]" accept="{{ implode(',', array_map(fn ($extension) => '.'.$extension, config('lms.allowed_extensions'))) }}" @required(!$supportingPath)>
            <small>{{ implode(', ', array_map('strtoupper', config('lms.allowed_extensions'))) }} · Maks. {{ (int)(config('lms.max_upload_size_kb') / 1024) }} MB</small>
        </div>
    @endif
    @foreach($schemas as $typeId => $schema)
        @if(count($schema['attachments'] ?? []))
        <section class="form-section" data-document-type="{{ $typeId }}" hidden><h2>Lampiran tambahan</h2>
            @foreach([true, false] as $requiredGroup)
                @php $items = collect($schema['attachments'])->filter(fn ($item) => $item['key'] !== 'supporting-document' && (bool)$item['required'] === $requiredGroup); @endphp
                @if($items->isNotEmpty())
                    @if(!$requiredGroup)<details><summary>Lampiran tambahan</summary>@endif
                    <div class="fields uploads">
                    @foreach($items as $item)
                        @php $existingPath = $submission?->attachments[$item['key']] ?? null; @endphp
                        <div class="field wide" data-condition="{{ $item['when'] ?? 'always' }}">
                            <label for="attachment-{{ $typeId }}-{{ $item['key'] }}">{{ $item['label'] }} @if($item['required'])<span aria-hidden="true">*</span>@endif</label>
                            @if($existingPath)<small>Berkas tersimpan. Pilih berkas untuk mengganti.</small>@endif
                            <input id="attachment-{{ $typeId }}-{{ $item['key'] }}" type="file" name="attachments[{{ $item['key'] }}]" accept="{{ implode(',', array_map(fn ($extension) => '.'.$extension, config('lms.allowed_extensions'))) }}" data-required="{{ (int)($item['required'] && !$existingPath) }}">
                        </div>
                    @endforeach
                    </div>
                    @if(!$requiredGroup)</details>@endif
                @endif
            @endforeach
        </section>
        @endif
    @endforeach
    <div class="form-footer"><span>* Wajib diisi</span><button type="submit">{{ $submission ? 'Kirim revisi' : 'Kirim' }}</button></div>
    <noscript><p>Aktifkan JavaScript untuk menampilkan isian sesuai jenis dokumen.</p></noscript>
</form>
@endif
@endsection
