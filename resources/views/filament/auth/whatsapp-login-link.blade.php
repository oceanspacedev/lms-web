<div class="flex flex-col gap-4">
    <div class="flex items-center gap-3 text-sm text-gray-400 dark:text-gray-500">
        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
        atau
        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
    </div>

    <x-filament::button
        tag="a"
        :href="route('filament.admin.auth.whatsapp-login')"
        color="gray"
        class="w-full"
    >
        <span class="inline-flex items-center gap-2">
            <x-whatsapp-icon :size="18" class="text-[#25D366]" />
            Masuk dengan kode WhatsApp
        </span>
    </x-filament::button>
</div>
