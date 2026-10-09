<?php

namespace App\Services;

use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor daftar dokumen ke berkas Excel (.xlsx) secara streaming, tanpa menampung seluruh baris di memori.
 */
class DocumentSpreadsheetExporter
{
    public const SHEET_NAME = 'Dokumen';

    public const CHUNK_SIZE = 500;

    public const HEADERS = [
        'Judul', 'Nomor Dokumen', 'Badan Usaha', 'Jenis Dokumen', 'Pihak Lawan', 'PIC', 'Tanggal Terbit', 'Tanggal Berakhir',
        'Status', 'Sisa Hari', 'Versi Aktif', 'Nama File', 'Dibuat',
    ];

    /** @param Builder<Document> $query */
    public function download(Builder $query, string $baseName): StreamedResponse
    {
        $timezone = config('lms.reminder_timezone');
        $filename = $baseName.'-'.now($timezone)->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($query): void {
            $this->write($query, 'php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @param Builder<Document> $query */
    public function write(Builder $query, string $target): void
    {
        $options = new Options;
        foreach ([1 => 40, 2 => 22, 3 => 28, 4 => 24, 5 => 28, 6 => 22, 7 => 16, 8 => 16, 9 => 20, 10 => 11, 11 => 12, 12 => 36, 13 => 18] as $column => $width) {
            $options->setColumnWidth($width, $column);
        }
        $writer = new Writer($options);
        $writer->openToFile($target);
        $writer->getCurrentSheet()->setName(self::SHEET_NAME);
        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

        $dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        $timezone = config('lms.reminder_timezone');
        $today = CarbonImmutable::today($timezone);
        if ($query->getQuery()->orders === null || $query->getQuery()->orders === []) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        $query->with(['company', 'documentType', 'pic', 'currentVersion'])->lazy(self::CHUNK_SIZE)->each(function (Document $document) use ($writer, $dateStyle, $timezone, $today): void {
            $version = $document->currentVersion;
            $expiry = $version?->expiry_date ? CarbonImmutable::parse($version->expiry_date->toDateString(), $timezone) : null;
            $hasExpiry = (bool) $document->documentType?->has_expiry && $expiry !== null;
            $writer->addRow(new Row([
                Cell::fromValue($document->title),
                Cell::fromValue($document->document_number ?? ''),
                Cell::fromValue($document->company?->name ?? ''),
                Cell::fromValue($document->documentType?->name ?? ''),
                Cell::fromValue($document->counterparty ?? ''),
                Cell::fromValue($document->pic?->name ?? ''),
                $this->dateCell($version?->issued_date?->toDateString(), $timezone, $dateStyle),
                $this->dateCell($version?->expiry_date?->toDateString(), $timezone, $dateStyle),
                Cell::fromValue(Document::EXPIRY_STATUSES[$document->expiry_status]),
                Cell::fromValue($hasExpiry ? (int) $today->diffInDays($expiry, false) : ''),
                Cell::fromValue($version ? 'v'.$version->version_number : ''),
                Cell::fromValue($version?->file_name ?? ''),
                $this->dateCell($document->created_at?->timezone($timezone)->toDateString(), $timezone, $dateStyle),
            ]));
        });

        $writer->close();
    }

    private function dateCell(?string $date, string $timezone, Style $style): Cell
    {
        return $date === null ? Cell::fromValue('') : Cell::fromValue(CarbonImmutable::parse($date, $timezone)->toDateTimeImmutable(), $style);
    }
}
