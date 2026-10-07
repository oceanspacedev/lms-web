@php
    $fieldId = 'field-'.str_replace('.', '-', $key);
    $fieldName = preg_replace('/\.([^.]+)/', '[$1]', $key);
    $fieldValue = old($key, data_get($defaults, $key, ''));
    $fieldValue = is_scalar($fieldValue) ? $fieldValue : '';
    $inputType = $type ?? 'text';
    $isRequired = $required ?? false;
@endphp
<div class="field {{ $wide ?? false ? 'wide' : '' }}">
    <label for="{{ $fieldId }}">{{ $label }} @if($isRequired)<span aria-hidden="true">*</span>@endif</label>
    @if($inputType === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $fieldName }}" rows="2" maxlength="4000" @required($isRequired) data-required="{{ (int) $isRequired }}" @if($errors->has($key)) aria-invalid="true" @endif>{{ $fieldValue }}</textarea>
    @else
        <input id="{{ $fieldId }}" name="{{ $fieldName }}" type="{{ $inputType }}" value="{{ $inputType === 'date' ? substr($fieldValue, 0, 10) : $fieldValue }}" @required($isRequired) data-required="{{ (int) $isRequired }}" @if($inputType === 'number') step="any" @elseif($inputType !== 'date') maxlength="{{ $inputType === 'tel' ? 15 : ($key === 'requester_division' ? 100 : (str_starts_with($key, 'details.') ? 4000 : 255)) }}" @endif @if($inputType === 'tel') inputmode="tel" autocomplete="tel" pattern="(08|\+?628)[0-9]{8,11}" title="Gunakan nomor WhatsApp diawali 08, 62, atau +62." @endif @if($errors->has($key)) aria-invalid="true" @endif>
    @endif
    @error($key)<small class="field-error">{{ $message }}</small>@enderror
</div>
