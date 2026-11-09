{{-- Logo panel. Sidebar Mekaya sudah menulis nama aplikasi sendiri, jadi teks di sini disembunyikan di sana (lihat app.css). --}}
<div class="lms-brand flex items-center gap-2.5">
    <img src="{{ asset('images/logo.svg') }}" alt="" class="size-8 shrink-0">
    <span class="lms-brand-name text-lg font-bold tracking-tight text-gray-950 dark:text-white">{{ filament()->getBrandName() }}</span>
</div>
