@props([
    'label' => null,
])
{{ filled($label) ? $label.': ' : '' }}{{ trim($slot) }}
