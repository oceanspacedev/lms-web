<div style="display: flex; flex-direction: column; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: .75rem; color: var(--gray-400); font-size: .875rem;">
        <span style="flex: 1; height: 1px; background: var(--gray-200);"></span>
        atau
        <span style="flex: 1; height: 1px; background: var(--gray-200);"></span>
    </div>

    <x-filament::button
        tag="a"
        :href="route('filament.admin.auth.whatsapp-login')"
        color="gray"
        class="w-full"
    >
        <span style="display: inline-flex; align-items: center; gap: .5rem;">
            <x-whatsapp-icon :size="18" style="color: #25D366;" />
            Masuk dengan kode WhatsApp
        </span>
    </x-filament::button>
</div>
