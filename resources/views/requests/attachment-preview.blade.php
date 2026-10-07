@if($isImage)
    <img src="{{ $url }}" alt="{{ $label }}" style="display: block; max-width: 100%; max-height: 70vh; margin: auto; object-fit: contain;">
@else
    <iframe src="{{ $url }}" title="{{ $label }}" style="display: block; width: 100%; height: 70vh; border: 0;"></iframe>
@endif
@if($showNewTabLink ?? true)
    <a href="{{ $url }}" target="_blank" rel="noopener" style="display: inline-block; margin-top: 12px; text-decoration: underline;">Buka di tab baru</a>
@endif
