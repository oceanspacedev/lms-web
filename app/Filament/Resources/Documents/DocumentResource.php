<?php

namespace App\Filament\Resources\Documents;

use App\Filament\Concerns\TrashActions;
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
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /** Halaman detail dokumen yang dihapus hanya terbuka untuk super admin, untuk dilihat lalu dipulihkan atau dihapus permanen. */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return TrashActions::routeBindingQuery(parent::getRecordRouteBindingEloquentQuery());
    }

    /** Dokumen yang dihapus tidak bisa diubah; pulihkan dulu. */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if ($record instanceof Document && $record->trashed()) {
            return Response::deny('Dokumen yang dihapus tidak dapat diubah. Pulihkan dokumen terlebih dahulu.');
        }

        return parent::getEditAuthorizationResponse($record);
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
