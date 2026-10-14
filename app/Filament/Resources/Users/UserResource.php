<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Services\UserDeactivator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static string|\UnitEnum|null $navigationGroup = 'Data Master';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Aksi Nonaktifkan yang sama untuk daftar dan halaman ubah user. Semua tugas user wajib dialihkan ke pengganti
     * yang dipilih; bila belum ada kandidat yang sah, aksi ditolak sebelum modal dibuka.
     */
    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')->label('Nonaktifkan')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn (User $record): bool => app(UserDeactivator::class)->deactivationDenialReason(auth()->user(), $record) === null)
            ->modalHeading(fn (User $record): string => "Nonaktifkan {$record->name}?")
            ->modalDescription(fn (User $record): HtmlString => static::workloadSummary($record))
            ->modalSubmitActionLabel('Ya, nonaktifkan')
            ->schema(fn (User $record): array => static::replacementFields($record))
            ->mountUsing(function (Action $action, User $record, ?Schema $schema): void {
                $message = app(UserDeactivator::class)->missingCandidatesMessage($record);
                if ($message !== null) {
                    Notification::make()->danger()->title('User belum bisa dinonaktifkan')->body($message)->persistent()->send();
                    $action->cancel();
                }
                $schema?->fill();
            })
            ->action(function (Action $action, User $record, array $data): void {
                try {
                    app(UserDeactivator::class)->deactivate($record, auth()->user(), $data);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('User belum bisa dinonaktifkan')->body(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('User dinonaktifkan')->body('Tugasnya sudah dialihkan dan user langsung keluar dari aplikasi.')->send();
            });
    }

    /** Aksi Aktifkan kembali: user bisa masuk lagi dengan peran dan izin semula. */
    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')->label('Aktifkan kembali')->icon(Heroicon::OutlinedArrowPath)->color('success')
            ->visible(fn (User $record): bool => app(UserDeactivator::class)->reactivationDenialReason(auth()->user(), $record) === null)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "Aktifkan kembali {$record->name}?")
            ->modalDescription('User bisa masuk lagi dengan peran dan izin semula. Tugas yang sudah dialihkan tidak dikembalikan otomatis.')
            ->modalSubmitActionLabel('Ya, aktifkan')
            ->action(function (Action $action, User $record): void {
                try {
                    app(UserDeactivator::class)->reactivate($record, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('User belum bisa diaktifkan kembali')->body(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('User diaktifkan kembali')->send();
            });
    }

    /** Ringkasan tugas user yang dihitung dari database, untuk deskripsi modal Nonaktifkan. */
    private static function workloadSummary(User $record): HtmlString
    {
        $workload = app(UserDeactivator::class)->workload($record);
        $lines = array_filter([
            $workload['documents'] > 0 ? "{$workload['documents']} dokumen sebagai PIC (termasuk dokumen yang dihapus)" : null,
            $workload['requests'] > 0 ? "{$workload['requests']} pengajuan berjalan sebagai PIC, pemeriksa, atau penyetuju" : null,
            $workload['document_types'] > 0 ? "{$workload['document_types']} jenis dokumen sebagai PIC, pemeriksa, atau penyetuju pengajuan" : null,
        ]);
        $summary = $lines === []
            ? '<p>User ini tidak memegang tugas apa pun.</p>'
            : '<p>Tugas yang akan dialihkan ke pengganti:</p><ul style="list-style: disc; padding-left: 1.25rem">'
                .implode('', array_map(fn (string $line): string => "<li>{$line}</li>", $lines)).'</ul>';
        if ($workload['drafts'] > 0) {
            $summary .= "<p>{$workload['drafts']} draf pengajuan milik user ini dibiarkan apa adanya.</p>";
        }

        return new HtmlString($summary.'<p>User langsung keluar dari aplikasi. Peran dan izinnya tetap disimpan agar bisa diaktifkan kembali.</p>');
    }

    /**
     * Satu Select wajib per kelompok tugas yang jumlahnya lebih dari nol; opsinya hanya kandidat yang sah.
     *
     * @return list<Select>
     */
    private static function replacementFields(User $record): array
    {
        $deactivator = app(UserDeactivator::class);
        $fields = [
            UserDeactivator::GROUP_DOCUMENT_PIC => fn (): Select => Select::make(UserDeactivator::GROUP_DOCUMENT_PIC)->label('Pengganti PIC dokumen'),
            UserDeactivator::GROUP_REVIEWER => fn (): Select => Select::make(UserDeactivator::GROUP_REVIEWER)->label('Pengganti pemeriksa / PIC pengajuan')
                ->helperText('Hanya user dengan izin "Periksa pengajuan". PIC pengajuan pada jenis dokumen juga harus punya nomor WhatsApp valid.'),
            UserDeactivator::GROUP_APPROVER => fn (): Select => Select::make(UserDeactivator::GROUP_APPROVER)->label('Pengganti penyetuju')
                ->helperText('"Tim persetujuan" berarti pengajuan bisa disetujui siapa saja yang punya izin "Setujui pengajuan".'),
        ];

        return array_map(fn (string $group): Select => $fields[$group]()
            ->options(fn (): array => $deactivator->candidateOptions($record, $group))->searchable()->required(), $deactivator->requiredGroups($record));
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
