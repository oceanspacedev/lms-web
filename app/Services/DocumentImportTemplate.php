<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentImport;
use App\Models\DocumentType;
use App\Models\User;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas Excel untuk impor dokumen lama: template kosong (sheet "Dokumen" + "Petunjuk") dan daftar baris gagal
 * dengan kolom yang sama, sehingga bisa diperbaiki lalu diimpor ulang.
 */
class DocumentImportTemplate
{
    public const GUIDE_SHEET_NAME = 'Petunjuk';

    public const FAILED_NOTE_HEADER = 'Keterangan';

    private const COLUMN_WIDTHS = [1 => 40, 2 => 22, 3 => 28, 4 => 26, 5 => 28, 6 => 30, 7 => 16, 8 => 18, 9 => 36, 10 => 32, 11 => 60];

    /** @return list<string> Judul kolom sheet "Dokumen"; kolom wajib ditandai "*". */
    public static function headers(): array
    {
        return collect(DocumentImporter::COLUMNS)
            ->map(fn (string $label, string $key): string => $label.(in_array($key, DocumentImporter::REQUIRED_COLUMNS, true) ? '*' : ''))
            ->values()->all();
    }

    public function downloadTemplate(): StreamedResponse
    {
        return $this->download(fn (string $target) => $this->writeTemplate($target), 'template-impor-dokumen.xlsx');
    }

    public function downloadFailedRows(DocumentImport $import): StreamedResponse
    {
        $date = now(config('lms.reminder_timezone'))->format('Ymd-His');

        return $this->download(fn (string $target) => $this->writeFailedRows($import, $target), "impor-gagal-batch-{$import->id}-{$date}.xlsx");
    }

    public function writeTemplate(string $target): void
    {
        $writer = $this->openDocumentSheet($target, false);

        $guide = $writer->addNewSheetAndMakeItCurrent();
        $guide->setName(self::GUIDE_SHEET_NAME);
        $guide->setColumnWidth(70, 1);
        $guide->setColumnWidth(40, 2);
        $bold = (new Style)->setFontBold();
        $maxRows = (int) config('lms.import_max_rows');
        $allowed = strtoupper(implode(', ', config('lms.allowed_extensions')));

        $writer->addRow(Row::fromValues(['Petunjuk impor dokumen lama'], $bold));
        foreach ([
            '1. Isi satu baris per dokumen di sheet "Dokumen", mulai baris ke-2. Jangan ubah judul kolom.',
            '2. Kolom bertanda * wajib diisi.',
            '3. Badan Usaha, Jenis Dokumen, dan Email PIC harus sesuai daftar di bawah (huruf besar-kecil tidak berpengaruh).',
            '4. Tanggal ditulis dd/mm/yyyy, misalnya 31/12/2026, atau memakai format tanggal Excel.',
            '5. Tanggal Berakhir wajib diisi untuk jenis dokumen yang memiliki masa berlaku.',
            '6. Nama File harus sama dengan nama file di dalam ZIP, termasuk ekstensinya (misalnya Kontrak-001.pdf). Folder di dalam ZIP diabaikan.',
            "7. File yang diterima: {$allowed}, maksimal ".DocumentImporter::megabytes((int) config('lms.max_upload_size_kb')).' MB per file.',
            "8. Satu impor maksimal {$maxRows} baris. Arsip besar dibagi menjadi beberapa batch.",
            '9. Dokumen yang sudah ada (badan usaha, jenis, dan nomor sama; atau judul sama bila nomor kosong) akan dilewati.',
        ] as $rule) {
            $writer->addRow(new Row([$this->textCell($rule)]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Badan Usaha aktif (isi kolom Badan Usaha)'], $bold));
        Company::query()->where('is_active', true)->orderBy('name')->each(function (Company $company) use ($writer): void {
            $writer->addRow(new Row([$this->textCell(StorageFileName::companyName($company))]));
        });

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Jenis Dokumen aktif (isi kolom Jenis Dokumen)', 'Masa berlaku'], $bold));
        DocumentType::query()->where('is_active', true)->orderBy('name')->each(function (DocumentType $type) use ($writer): void {
            $writer->addRow(new Row([$this->textCell($type->name), $this->textCell($type->has_expiry ? 'Ada, Tanggal Berakhir wajib' : 'Tanpa masa berlaku')]));
        });

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Email PIC (user aktif)', 'Nama'], $bold));
        User::active()->orderBy('name')->each(function (User $user) use ($writer): void {
            $writer->addRow(new Row([$this->textCell($user->email), $this->textCell($user->name)]));
        });

        $writer->close();
    }

    public function writeFailedRows(DocumentImport $import, string $target): void
    {
        $writer = $this->openDocumentSheet($target, true);
        foreach (app(DocumentImporter::class)->failedRows($import) as $row) {
            $writer->addRow(new Row([
                ...collect(array_keys(DocumentImporter::COLUMNS))->map(fn (string $key): Cell => $this->textCell($row['values'][$key] ?? null))->all(),
                $this->textCell('Baris '.$row['line'].': '.implode(' ', $row['messages'])),
            ]));
        }
        $writer->close();
    }

    private function openDocumentSheet(string $target, bool $withNotes): Writer
    {
        $writer = new Writer;
        $writer->openToFile($target);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(DocumentSpreadsheetExporter::SHEET_NAME);
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }
        $writer->addRow(Row::fromValues([...self::headers(), ...($withNotes ? [self::FAILED_NOTE_HEADER] : [])], (new Style)->setFontBold()));

        return $writer;
    }

    /** @param callable(string): void $write */
    private function download(callable $write, string $filename): StreamedResponse
    {
        return response()->streamDownload(fn () => $write('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Selalu teks (lihat DocumentSpreadsheetExporter::textCell), supaya isian yang diawali "=" tidak menjadi rumus. */
    private function textCell(?string $value): Cell
    {
        return $value === null || $value === '' ? Cell::fromValue('') : new StringCell($value, null);
    }
}
