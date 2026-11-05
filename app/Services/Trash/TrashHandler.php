<?php

namespace App\Services\Trash;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Aturan tempat sampah untuk satu jenis data. Dipanggil hanya lewat TrashManager, yang mengurus transaksi, `deleted_by`, dan log.
 * Daftarkan implementasi baru dengan tag `TrashHandler::class` di AppServiceProvider.
 */
interface TrashHandler
{
    public function supports(Model $record): bool;

    /** Data aktif yang memakai (menghalangi hapus permanen) dan data di tempat sampah yang akan ikut terhapus permanen. */
    public function usage(Model $record): TrashUsage;

    /**
     * Pekerjaan tambahan sebelum data masuk tempat sampah, di dalam transaksi. Lempar ValidationException untuk membatalkan.
     *
     * @param  array<string, mixed>  $data  Isian modal hapus, misalnya pengganti tugas user.
     */
    public function beforeTrash(Model $record, User $actor, array $data): void;

    /** Pekerjaan tambahan setelah data dipulihkan, di dalam transaksi. */
    public function afterRestore(Model $record, User $actor): void;

    /**
     * Menghapus permanen data beserta anaknya yang di tempat sampah, di dalam transaksi yang dibuka TrashManager.
     * Anak dihapus lewat TrashManager::purge() supaya memakai handler-nya sendiri. File tidak disentuh di sini.
     *
     * @return list<array{disk: string, path: string}> File yang dihapus TrashManager setelah transaksi berhasil.
     */
    public function purge(Model $record): array;

    /** Alasan data tidak boleh dipindahkan ke tempat sampah oleh `$actor`, atau null bila boleh. */
    public function trashDenialReason(Model $record, User $actor): ?string;
}
