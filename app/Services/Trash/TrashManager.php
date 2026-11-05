<?php

namespace App\Services\Trash;

use App\Models\TrashLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Satu-satunya jalur hapus ke tempat sampah, pulihkan, dan hapus permanen. Aturan per jenis data ada di TrashHandler.
 * Setiap tindakan dicatat di TrashLog. File dari hapus permanen dihapus setelah transaksi database berhasil.
 */
class TrashManager
{
    public const OPERATIONS = ['trash', 'restore', 'forceDelete'];

    /**
     * @param  iterable<TrashHandler>  $handlers
     */
    public function __construct(private iterable $handlers) {}

    /**
     * @param  array<string, mixed>  $data  Isian modal hapus, diteruskan ke handler.
     *
     * @throws ValidationException bila data sudah di tempat sampah atau tidak boleh dihapus oleh `$actor`.
     */
    public function trash(Model $record, User $actor, array $data = []): void
    {
        $handler = $this->handlerFor($record);

        DB::transaction(function () use ($record, $actor, $data, $handler): void {
            if ($record->trashed()) {
                throw self::invalid('Data ini sudah ada di tempat sampah.');
            }
            $reason = $handler->trashDenialReason($record, $actor);
            if ($reason !== null) {
                throw self::invalid($reason);
            }

            $handler->beforeTrash($record, $actor, $data);
            // Diperbarui lewat query supaya hook `saving` model (validasi isian) tidak ikut berjalan.
            $record->newQueryWithoutScopes()->whereKey($record->getKey())->update(['deleted_by' => $actor->id]);
            $record->forceFill(['deleted_by' => $actor->id])->syncOriginalAttribute('deleted_by');
            if (! $record->delete()) {
                throw new LogicException('Data gagal dipindahkan ke tempat sampah: '.$record::class.' #'.$record->getKey());
            }
            $this->log($record->trashSubject(), $record->getKey(), $record->trashLabel(), 'deleted', $actor);
        });
    }

    /**
     * @throws ValidationException bila data tidak ada di tempat sampah.
     */
    public function restore(Model $record, User $actor): void
    {
        $handler = $this->handlerFor($record);

        DB::transaction(function () use ($record, $actor, $handler): void {
            if (! $record->trashed()) {
                throw self::invalid('Data ini tidak ada di tempat sampah.');
            }

            $record->deleted_by = null;
            if (! $record->restore()) {
                throw new LogicException('Data gagal dipulihkan: '.$record::class.' #'.$record->getKey());
            }
            $handler->afterRestore($record, $actor);
            $this->log($record->trashSubject(), $record->getKey(), $record->trashLabel(), 'restored', $actor);
        });
    }

    /**
     * Menghapus permanen data di tempat sampah beserta anaknya yang juga di tempat sampah.
     *
     * @throws ValidationException bila data belum di tempat sampah atau masih dipakai data aktif.
     */
    public function forceDelete(Model $record, User $actor): void
    {
        $handler = $this->handlerFor($record);
        $subject = $record->trashSubject();
        $key = $record->getKey();
        $label = $record->trashLabel();

        [$log, $files] = DB::transaction(function () use ($record, $actor, $handler, $subject, $key, $label): array {
            if (! $record->trashed()) {
                throw self::invalid('Data ini belum ada di tempat sampah.');
            }
            $usage = $handler->usage($record);
            if ($usage->blocksForceDelete()) {
                throw self::invalid($usage->blockMessage());
            }

            $files = $handler->purge($record);
            $log = $this->log($subject, $key, $label, 'force_deleted', $actor, $usage->trashed === [] ? null : ['cascaded' => $usage->trashed]);

            return [$log, $files];
        });

        DB::afterCommit(fn () => $this->deleteFiles($log, $files));
    }

    /**
     * Menghapus permanen anak di tempat sampah lewat handler-nya, di dalam transaksi hapus permanen induknya.
     * Dipanggil dari TrashHandler::purge(); tidak membuka transaksi dan tidak menulis log sendiri.
     *
     * @return list<array{disk: string, path: string}>
     *
     * @throws ValidationException bila anak itu masih dipakai data aktif.
     * @throws LogicException bila tidak ada handler untuk anak itu.
     */
    public function purge(Model $record): array
    {
        $handler = $this->handlerFor($record);
        $usage = $handler->usage($record);
        if ($usage->blocksForceDelete()) {
            $holder = Str::lower(TrashLog::SUBJECTS[$record->trashSubject()]).' "'.$record->trashLabel().'" di tempat sampah';

            throw self::invalid($usage->blockMessage($holder));
        }

        return $handler->purge($record);
    }

    public function usage(Model $record): TrashUsage
    {
        return $this->handlerFor($record)->usage($record);
    }

    /**
     * Menjalankan satu operasi untuk banyak data; tiap data dalam transaksinya sendiri. Data yang ditolak dilewati beserta alasannya.
     *
     * @param  'trash'|'restore'|'forceDelete'  $operation
     * @param  iterable<Model>  $records
     * @return array{done: int, skipped: list<array{label: string, reason: string}>}
     */
    public function bulk(string $operation, iterable $records, User $actor): array
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException("Operasi tempat sampah tidak dikenal: {$operation}");
        }

        $done = 0;
        $skipped = [];
        foreach ($records as $record) {
            try {
                $this->{$operation}($record, $actor);
                $done++;
            } catch (ValidationException $exception) {
                $skipped[] = ['label' => $record->trashLabel(), 'reason' => (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @throws LogicException bila tidak ada handler terdaftar untuk data ini.
     */
    private function handlerFor(Model $record): TrashHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($record)) {
                return $handler;
            }
        }

        throw new LogicException('Tidak ada TrashHandler terdaftar untuk '.$record::class.'.');
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function log(string $subject, int|string $key, string $label, string $action, User $actor, ?array $details = null): TrashLog
    {
        return TrashLog::create([
            'subject_type' => $subject,
            'subject_id' => $key,
            'subject_label' => $label,
            'action' => $action,
            'user_id' => $actor->id,
            'details' => $details,
        ]);
    }

    /**
     * Data di database sudah terhapus; file yang gagal dihapus dicatat di log yang sama sebagai `left_files`.
     *
     * @param  list<array{disk: string, path: string}>  $files
     */
    private function deleteFiles(TrashLog $log, array $files): void
    {
        $leftFiles = [];
        foreach ($files as $file) {
            try {
                $deleted = Storage::disk($file['disk'])->delete($file['path']);
            } catch (Throwable $exception) {
                report($exception);
                $deleted = false;
            }
            if (! $deleted) {
                $leftFiles[] = ['disk' => $file['disk'], 'path' => $file['path']];
            }
        }

        if ($leftFiles !== []) {
            $log->update(['details' => [...($log->details ?? []), 'left_files' => $leftFiles]]);
        }
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['record' => $message]);
    }
}
