@once
    <style>
        .wa-variable-editor { display: grid; gap: .75rem; }
        .wa-variable-editor[data-dragging="true"] .fi-input-wrp { outline: 2px solid var(--color-primary-500); outline-offset: 2px; }
        .wa-variable-editor__help { margin: 0; color: #52525b; font-size: .8125rem; line-height: 1.5; }
        .dark .wa-variable-editor__help { color: #a1a1aa; }
        .wa-variable-editor__chips { display: flex; flex-wrap: wrap; gap: .5rem; }
        .wa-variable-editor__chips .fi-btn { cursor: grab; min-height: 36px; }
        .wa-variable-editor__chips .fi-btn:active { cursor: grabbing; }
        .wa-variable-editor textarea { min-height: 12rem; line-height: 1.7; resize: vertical; caret-color: var(--color-primary-500); }
        .wa-variable-editor__chips .fi-btn:focus-visible { outline: 2px solid var(--color-primary-500); outline-offset: 2px; }
        .wa-variable-editor__error { margin: 0; color: var(--color-danger-600); font-size: .8125rem; }
        .dark .wa-variable-editor__error { color: var(--color-danger-400); }
        @media (pointer: coarse) { .wa-variable-editor__chips .fi-btn { min-height: 44px; } }
    </style>
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="wa-variable-editor"
        x-data="{
            dragging: false,
            error: '',
            insert(token) {
                const input = this.$refs.message;
                if (input.disabled || input.readOnly) { return; }
                const length = input.value.length - (input.selectionEnd - input.selectionStart) + token.length;
                if (input.maxLength > 0 && length > input.maxLength) {
                    this.error = 'Pesan maksimal ' + input.maxLength + ' karakter. Hapus sebagian teks terlebih dahulu.';
                    return;
                }
                this.error = '';
                input.setRangeText(token, input.selectionStart, input.selectionEnd, 'end');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
            }
        }"
        x-bind:data-dragging="dragging"
    >
        <x-filament::input.wrapper :disabled="$isDisabled()" :valid="! $field->getLivewire()->getErrorBag()->has($getStatePath())" class="fi-fo-textarea">
            <textarea
                x-ref="message"
                id="{{ $getId() }}"
                rows="{{ $getRows() }}"
                maxlength="{{ $getMaxLength() }}"
                @disabled($isDisabled())
                @readonly($isReadOnly())
                @required($isRequired())
                {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
                x-on:dragover="dragging = $event.dataTransfer.types.includes('text/plain'); $event.dataTransfer.dropEffect = 'copy'"
                x-on:dragleave="dragging = false"
                x-on:drop="dragging = false; error = ''"
                x-on:input="error = ''"
            >{{ $getState() }}</textarea>
        </x-filament::input.wrapper>

        <p class="wa-variable-editor__help">Seret data ke posisi yang diinginkan dalam pesan, atau ketuk untuk menyisipkan. Data terisi otomatis saat dikirim.</p>
        <div class="wa-variable-editor__chips" role="group" aria-label="Data otomatis">
            @foreach ($variableLabels as $name => $label)
                <x-filament::button
                    type="button" color="gray" size="sm" outlined
                    :disabled="$isDisabled() || $isReadOnly()"
                    draggable="{{ $isDisabled() || $isReadOnly() ? 'false' : 'true' }}"
                    data-variable="{{ '{'.$name.'}' }}"
                    aria-label="{{ 'Tambahkan '.$label.' ke pesan' }}"
                    x-on:dragstart="$event.dataTransfer.setData('text/plain', $el.dataset.variable); $event.dataTransfer.effectAllowed = 'copy'; dragging = true"
                    x-on:dragend="dragging = false"
                    x-on:click="insert($el.dataset.variable)"
                >{{ $label }}</x-filament::button>
            @endforeach
        </div>
        <p class="wa-variable-editor__error" role="status" x-text="error" x-show="error" x-cloak></p>
    </div>
</x-dynamic-component>
