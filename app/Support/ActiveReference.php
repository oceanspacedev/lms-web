<?php

namespace App\Support;

use App\Models\Concerns\Trashable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Rujukan ke induk (badan usaha, jenis dokumen, user) yang boleh dipilih: aktif dan tidak di tempat sampah,
 * atau nilai yang sudah tersimpan pada data itu sendiri (walau induknya nonaktif atau di tempat sampah),
 * mengikuti pola `User::scopeActiveOrKeep`.
 */
class ActiveReference
{
    /**
     * Aturan validasi: id ada, tidak di tempat sampah, dan memenuhi `$where` (kolom => nilai, null berarti kosong),
     * atau sama dengan `$keepId` yang sedang tersimpan.
     *
     * @param  array<string, mixed>  $where
     */
    public static function rule(string $table, int|string|null $keepId = null, array $where = []): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $keepId, $where): void {
            if (filled($keepId) && (string) $value === (string) $keepId) {
                return;
            }

            $query = DB::table($table)->where('id', $value)->whereNull('deleted_at');
            foreach ($where as $column => $expected) {
                $expected === null ? $query->whereNull($column) : $query->where($column, $expected);
            }

            if (! $query->exists()) {
                $fail('validation.exists')->translate();
            }
        };
    }

    /**
     * Batasi query ke induk yang aktif (tidak di tempat sampah dan memenuhi `$where`), ditambah `$keepId` yang
     * sedang terpasang walau nonaktif atau di tempat sampah. Dipakai untuk pilihan dropdown dan Select::relationship().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $where
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, int|string|null $keepId = null, array $where = []): Builder
    {
        $model = $query->getModel();

        return $query->withoutGlobalScope(SoftDeletingScope::class)->where(function (Builder $query) use ($model, $keepId, $where): void {
            $query->where(function (Builder $active) use ($model, $where): void {
                $active->whereNull($model->qualifyColumn('deleted_at'));
                foreach ($where as $column => $expected) {
                    $expected === null ? $active->whereNull($model->qualifyColumn($column)) : $active->where($model->qualifyColumn($column), $expected);
                }
            })->when(filled($keepId), fn (Builder $query): Builder => $query->orWhere($model->getQualifiedKeyName(), $keepId));
        });
    }

    /**
     * Opsi dropdown (id => label) dari `$query` yang aktif, ditambah nilai yang sedang terpasang dengan label `displayName()`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): string  $label
     * @return array<int|string, string>
     */
    public static function options(Builder $query, int|string|null $keepId, Closure $label): array
    {
        $options = $query->get()->mapWithKeys(fn (Model $model): array => [$model->getKey() => $label($model)])->all();

        if (filled($keepId) && ! array_key_exists($keepId, $options)) {
            $kept = $query->getModel()->newQuery()->withTrashed()->find($keepId);
            if ($kept !== null) {
                /** @var Model&Trashable $kept */
                $options[$kept->getKey()] = $kept->displayName();
            }
        }

        return $options;
    }

    /**
     * Aturan nama atau email unik. Bentrok dengan baris di tempat sampah gagal dengan `$trashedMessage`
     * (indeks unik database tidak diubah, jadi pemulihan tidak pernah bentrok); bentrok dengan baris aktif
     * gagal dengan pesan unik biasa, kecuali `$activeConflictHandledElsewhere` (aturan `unique` lain sudah menanganinya).
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function uniqueRule(string $modelClass, string $column, ?int $ignoreId, string $trashedMessage, bool $activeConflictHandledElsewhere = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($modelClass, $column, $ignoreId, $trashedMessage, $activeConflictHandledElsewhere): void {
            $conflict = $modelClass::query()->withTrashed()
                ->where($column, $value)
                ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
                ->first();

            if ($conflict === null) {
                return;
            }

            if ($conflict->trashed()) {
                $fail($trashedMessage);
            } elseif (! $activeConflictHandledElsewhere) {
                $fail('validation.unique')->translate();
            }
        };
    }
}
