<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Models\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Support\Str;

class CompanyForm
{
    /**
     * Bentuk badan usaha yang lazim, selalu tersedia di pilihan walau belum dipakai badan usaha mana pun.
     *
     * @var array<int, string>
     */
    public const COMMON_LEGAL_FORMS = ['PT', 'CV', 'Yayasan', 'Koperasi', 'Firma', 'UD', 'Perkumpulan', 'Perorangan'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Badan Usaha')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('legal_form')
                    ->label('Bentuk Badan Usaha')
                    ->placeholder('Pilih atau cari bentuk badan usaha')
                    ->options(fn (?string $rawState): array => self::legalFormOptions($rawState))
                    ->searchable()
                    ->required()
                    ->rule('max:50')
                    ->validationMessages(['max' => 'Bentuk badan usaha maksimal 50 karakter.'])
                    ->dehydrateStateUsing(fn (?string $state): string => self::normalizeLegalForm($state))
                    ->createOptionForm([
                        TextInput::make('legal_form')
                            ->label('Bentuk badan usaha baru')
                            ->placeholder('Contoh: BUMDes')
                            ->required()
                            ->maxLength(50),
                    ])
                    ->createOptionUsing(fn (array $data): string => self::resolveLegalForm((string) $data['legal_form']))
                    ->createOptionModalHeading('Tambah bentuk badan usaha')
                    ->createOptionAction(fn (Action $action): Action => $action->label('Tambah bentuk badan usaha baru')->modalSubmitActionLabel('Tambah'))
                    ->helperText('Dipakai pada nama file dokumen. Pilih dari daftar; jika belum ada, tambahkan dengan tombol +.'),
                TextInput::make('npwp')
                    ->label('NPWP')
                    ->mask(RawJs::make(<<<'JS'
                        $input.replace(/\D/g, '').length > 15 ? '9999999999999999' : '99.999.999.9-999.999'
                        JS))
                    ->placeholder('99.999.999.9-999.999')
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->regex('/^(\d{2}\.\d{3}\.\d{3}\.\d-\d{3}\.\d{3}|\d{15}|\d{16})$/')
                    ->validationMessages(['regex' => 'NPWP harus 15 digit (99.999.999.9-999.999) atau 16 digit angka.'])
                    ->helperText('15 digit lama atau 16 digit (NIK/NPWP baru).')
                    ->maxLength(30),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
                Textarea::make('address')
                    ->label('Alamat')
                    ->rows(3)
                    ->maxLength(5000)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Pilihan bentuk badan usaha: daftar lazim, nilai lama yang sudah tersimpan di tabel badan usaha
     * (agar data lama tetap bisa dipilih), dan nilai yang sedang dipilih di formulir.
     *
     * @return array<string, string>
     */
    public static function legalFormOptions(?string $selected = null): array
    {
        $stored = Company::query()->whereNotNull('legal_form')->distinct()->orderBy('legal_form')->pluck('legal_form')->all();

        return collect([...self::COMMON_LEGAL_FORMS, ...$stored, $selected])
            ->filter(fn (?string $form): bool => filled($form))
            ->unique()
            ->mapWithKeys(fn (string $form): array => [$form => $form])
            ->all();
    }

    public static function normalizeLegalForm(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Bentuk baru yang sama dengan pilihan yang ada (abaikan huruf besar/kecil, titik, dan spasi,
     * misalnya "pt" atau "P.T.") memakai ejaan pilihan yang sudah ada agar tidak terbentuk duplikat.
     */
    public static function resolveLegalForm(string $value): string
    {
        $form = self::normalizeLegalForm($value);
        $key = self::legalFormKey($form);

        foreach (self::legalFormOptions() as $existing) {
            if (self::legalFormKey($existing) === $key) {
                return $existing;
            }
        }

        return $form;
    }

    private static function legalFormKey(string $form): string
    {
        return Str::lower((string) preg_replace('/[\s.]+/u', '', $form));
    }
}
