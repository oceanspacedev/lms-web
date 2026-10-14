@php
    $fieldId = $inputId ?? 'field-'.str_replace('.', '-', $key);
    $fieldName = preg_replace('/\.([^.]+)/', '[$1]', $key);
    $fieldValue = old($key, data_get($defaults, $key, ''));
    $fieldValue = is_scalar($fieldValue) ? $fieldValue : '';
    $inputType = $type ?? 'text';
    $isRequired = $required ?? false;
    $describedBy = trim((isset($hint) ? $fieldId.'-hint ' : '').($errors->has($key) ? $fieldId.'-error' : ''));
@endphp
<div class="field {{ $wide ?? false ? 'wide' : '' }}">
    <label for="{{ $fieldId }}">{{ $label }} @if($isRequired)<span aria-hidden="true">*</span>@endif</label>
    @isset($hint)<small id="{{ $fieldId }}-hint">{{ $hint }}</small>@endisset
    @if($inputType === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $fieldName }}" rows="2" maxlength="4000" @required($isRequired && ! ($scoped ?? false)) data-required="{{ (int) $isRequired }}" @if($errors->has($key)) aria-invalid="true" @endif @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>{{ $fieldValue }}</textarea>
    @else
        <input id="{{ $fieldId }}" name="{{ $fieldName }}" type="{{ $inputType }}" value="{{ $inputType === 'date' ? substr($fieldValue, 0, 10) : $fieldValue }}" @required($isRequired && ! ($scoped ?? false)) data-required="{{ (int) $isRequired }}" @if($inputType === 'number') step="any" @elseif($inputType !== 'date') maxlength="{{ $inputType === 'tel' ? 25 :($key === 'requester_division' ? 100 : (str_starts_with($key, 'details.') ? 4000 : 255)) }}" @endif @if($inputType === 'tel') inputmode="tel" autocomplete="tel" pattern="(08|\+?628)[0-9]{8,11}" title="Gunakan nomor WhatsApp diawali 08, 62, atau +62." @endif @if($errors->has($key)) aria-invalid="true" @endif @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @endif
    @error($key)<small class="field-error" id="{{ $fieldId }}-error">{{ $message }}</small>@enderror
</div>
