<?php

namespace App\Filament\Resources\DocumentRequests;

use App\Filament\Resources\DocumentRequests\Pages\CreateDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Resources\DocumentRequests\Schemas\DocumentRequestForm;
use App\Filament\Resources\DocumentRequests\Tables\DocumentRequestsTable;
use App\Models\DocumentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentRequestResource extends Resource
{
    protected static ?string $model = DocumentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'Pengajuan Dokumen';

    protected static ?string $pluralModelLabel = 'Pengajuan Dokumen';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('view', $record);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['documentType', 'requester']);
        $user = auth()->user();
        if ($user->can('ViewAll:DocumentRequest')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('requester_id', $user->id);
            foreach (['Review' => 'reviewer_id', 'Approve' => 'approver_id'] as $permission => $column) {
                if ($user->can($permission.':DocumentRequest')) {
                    $query->orWhere(fn (Builder $query): Builder => $query->where('status', '!=', 'draft')->where(fn (Builder $query): Builder => $query->whereNull($column)->orWhere($column, $user->id)));
                }
            }
        });
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentRequestsTable::configure($table);
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
            'index' => ListDocumentRequests::route('/'),
            'create' => CreateDocumentRequest::route('/create'),
            'edit' => EditDocumentRequest::route('/{record}/edit'),
        ];
    }
}
