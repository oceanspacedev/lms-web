<?php

namespace App\Services\Trash;

/**
 * Keterkaitan satu data dengan data lain: dasar kalimat di modal hapus dan alasan hapus permanen ditolak.
 * Entri dengan jumlah nol dibuang, jadi `$active` kosong berarti tidak ada data aktif yang memakai.
 */
final readonly class TrashUsage
{
    /** Jumlah contoh nama yang disebut per jenis data sebelum "dan N lainnya". */
    public const EXAMPLE_LIMIT = 5;

    /** @var list<array{label: string, count: int, examples: list<string>}> Data aktif yang memakai; menghalangi hapus permanen. */
    public array $active;

    /** @var list<array{label: string, count: int, examples: list<string>}> Data di tempat sampah yang ikut terhapus permanen. */
    public array $trashed;

    /**
     * Pola alasan hapus permanen ditolak. `{holder}` menjadi penyebut data anak yang dipakai (diikuti spasi) atau kosong,
     * `{subject}` menjadi penyebut itu atau "{jenis data} ini", `{list}` menjadi "2 dokumen (A, B) dan 1 pengajuan (#14)",
     * dan `{examples}` hanya contohnya, misalnya "#14, #15".
     */
    public const BLOCK_MASTER_DATA = 'Tidak bisa dihapus permanen: {holder}masih dipakai {list}. Hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi.';

    public const BLOCK_DOCUMENT = 'Tidak bisa dihapus permanen: {holder}masih dipakai pengajuan {examples}. Hapus dulu pengajuan itu, lalu coba lagi.';

    public const BLOCK_USER = 'Tidak bisa dihapus permanen: {subject} masih tercatat di {list}. User yang punya riwayat cukup dibiarkan di tempat sampah — ia tetap tidak bisa masuk dan tidak menerima WhatsApp.';

    /**
     * @param  list<array{label: string, count: int, examples: list<string>}>  $active
     * @param  list<array{label: string, count: int, examples: list<string>}>  $trashed
     * @param  list<string>  $notes  Kalimat tambahan untuk modal hapus.
     * @param  string  $subjectName  Sebutan jenis data dalam huruf kecil, misalnya "badan usaha".
     * @param  string  $blockTemplate  Pola alasan hapus permanen ditolak, salah satu konstanta BLOCK_*.
     */
    public function __construct(array $active = [], array $trashed = [], public array $notes = [], public string $subjectName = 'data', public string $blockTemplate = self::BLOCK_MASTER_DATA)
    {
        $this->active = self::withoutEmpty($active);
        $this->trashed = self::withoutEmpty($trashed);
    }

    public function blocksForceDelete(): bool
    {
        return $this->active !== [];
    }

    /**
     * Alasan hapus permanen ditolak menurut `$blockTemplate`, misalnya "Tidak bisa dihapus permanen: masih dipakai 2 dokumen (A, B)
     * dan 1 pengajuan (#14). Hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi." Contoh dibatasi lima, ditambah "dan N lainnya".
     *
     * @param  string|null  $holder  Penyebut data yang dipakai bila bukan data yang sedang dihapus, misalnya 'dokumen "X" di tempat sampah'.
     */
    public function blockMessage(?string $holder = null): string
    {
        $examples = array_map(function (array $entry): array {
            $examples = array_slice($entry['examples'], 0, self::EXAMPLE_LIMIT);
            $others = $entry['count'] - count($examples);
            if ($examples !== [] && $others > 0) {
                $examples[] = "dan {$others} lainnya";
            }

            return $examples;
        }, $this->active);
        $entries = array_map(fn (array $entry, array $examples): string => "{$entry['count']} {$entry['label']}".($examples === [] ? '' : ' ('.implode(', ', $examples).')'),
            $this->active, $examples);

        return strtr($this->blockTemplate, [
            '{holder}' => $holder === null ? '' : $holder.' ',
            '{subject}' => $holder ?? $this->subjectName.' ini',
            '{list}' => self::join($entries),
            '{examples}' => self::join(array_map(fn (array $examples): string => implode(', ', $examples), $examples)),
        ]);
    }

    /** Kalimat keterkaitan untuk modal hapus, atau string kosong bila tidak ada yang perlu disebut. */
    public function summary(): string
    {
        $sentences = [];
        if ($this->active !== []) {
            $counts = array_map(fn (array $entry): string => "{$entry['count']} {$entry['label']}", $this->active);
            $labels = array_map(fn (array $entry): string => $entry['label'], $this->active);
            $sentences[] = ucfirst($this->subjectName).' ini masih dipakai '.self::join($counts).'.';
            $sentences[] = ucfirst(self::join($labels)).' itu tidak ikut terhapus, dan nama '.$this->subjectName.' tetap tampil di sana dengan tanda (dihapus).';
        }

        return implode(' ', [...$sentences, ...$this->notes]);
    }

    /**
     * Menggabungkan bagian kalimat: "A dan B" atau "A, B, dan C".
     *
     * @param  list<string>  $parts
     */
    public static function join(array $parts): string
    {
        if (count($parts) <= 2) {
            return implode(' dan ', $parts);
        }

        return implode(', ', array_slice($parts, 0, -1)).', dan '.end($parts);
    }

    /**
     * @param  list<array{label: string, count: int, examples: list<string>}>  $entries
     * @return list<array{label: string, count: int, examples: list<string>}>
     */
    private static function withoutEmpty(array $entries): array
    {
        return array_values(array_filter($entries, fn (array $entry): bool => $entry['count'] > 0));
    }
}
