<?php

namespace App\Filament\Resources\Documents;

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ImportDocuments;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Documents\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\Documents\Schemas\DocumentForm;
use App\Filament\Resources\Documents\Schemas\DocumentInfolist;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use App\Services\Trash\TrashManager;
use BackedEnum;
use Filament\Actions\RestoreAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Component;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $modelLabel = 'Dokumen';

    protected static ?string $pluralModelLabel = 'Dokumen';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'documentType', 'pic', 'currentVersion']);
    }

    /** Halaman detail dokumen yang dihapus tetap bisa dibuka, supaya dokumen bisa dilihat dan dipulihkan. */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** Dokumen yang dihapus tidak bisa diubah; pulihkan dulu. */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if ($record instanceof Document && $record->trashed()) {
            return Response::deny('Dokumen yang dihapus tidak dapat diubah. Pulihkan dokumen terlebih dahulu.');
        }

        return parent::getEditAuthorizationResponse($record);
    }

    /**
     * Aksi Pulihkan yang sama untuk daftar dan halaman detail; hak aksesnya mengikuti DocumentPolicy::restore.
     * Pemulihan tercatat di riwayat aktivitas, dan riwayat versi/aktivitas di halaman detail ikut dimuat ulang.
     */
    public static function restoreAction(): RestoreAction
    {
        return RestoreAction::make()->label('Pulihkan')
            ->modalHeading('Pulihkan dokumen ini?')
            ->modalDescription('Dokumen muncul kembali di daftar, file bisa diunduh lagi, dan pengingat masa berlaku berjalan lagi.')
            ->modalSubmitActionLabel('Ya, pulihkan')
            ->using(fn (Document $record): bool => static::changeTrashedState($record, 'restored'))
            ->after(fn (Component $livewire) => $livewire->dispatch('document-version-updated'))
            ->successNotificationTitle('Dokumen berhasil dipulihkan');
    }

    /**
     * Pindahkan dokumen ke tempat sampah atau pulihkan lewat TrashManager. Aktivitas "deleted" / "restored"
     * dan riwayat penghapusan dicatat oleh DocumentTrashHandler dalam transaksi yang sama.
     *
     * @param  'deleted'|'restored'  $event
     */
    public static function changeTrashedState(Document $record, string $event): bool
    {
        $trash = app(TrashManager::class);
        $actor = auth()->user();
        if ($event === 'restored') {
            $trash->restore($record, $actor);
        } else {
            $trash->trash($record, $actor);
        }

        return true;
    }

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'document_number', 'company.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Badan Usaha' => $record->company?->displayName() ?? '-', 'Nomor' => $record->document_number ?? '-', 'Jenis Dokumen' => $record->documentType?->displayName() ?? '-'];
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'create' => CreateDocument::route('/create'),
            'import' => ImportDocuments::route('/import'),
            'view' => ViewDocument::route('/{record}'),
            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }
}
