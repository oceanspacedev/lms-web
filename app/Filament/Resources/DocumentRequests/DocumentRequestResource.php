<?php

namespace App\Filament\Resources\DocumentRequests;

use App\Filament\Resources\DocumentRequests\Pages\CreateDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Resources\DocumentRequests\Schemas\DocumentRequestForm;
use App\Filament\Resources\DocumentRequests\Tables\DocumentRequestsTable;
use App\Models\DocumentRequest;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentRequestResource extends Resource
{
    protected static ?string $model = DocumentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

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
        $query = parent::getEloquentQuery()->with(['documentType', 'requester', 'company']);
        $user = auth()->user();
        if ($user->can('ViewAll:DocumentRequest')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('requester_id', $user->id);
            $query->orWhere(fn (Builder $query): Builder => $query->where('status', '!=', 'draft')->where('pic_user_id', $user->id));
            foreach (['Review' => 'reviewer_id', 'Approve' => 'approver_id'] as $permission => $column) {
                if ($user->can($permission.':DocumentRequest')) {
                    $query->orWhere(fn (Builder $query): Builder => $query->where('status', '!=', 'draft')->where(fn (Builder $query): Builder => $query->whereNull($column)->orWhere($column, $user->id)));
                }
            }
        });
    }

    /**
     * Batasi $query ke pengajuan yang bisa ditindaklanjuti user saat ini juga. Mengikuti DocumentRequestPolicy:
     * diperiksa (review), disetujui (approve), diarsipkan (archive), atau dikirim ulang oleh pengaju (draf/revisi).
     * Pengajuan publik tidak punya requester_id, jadi "bukan pengajuan sendiri" harus menerima NULL.
     *
     * @param  Builder<DocumentRequest>  $query
     * @return Builder<DocumentRequest>
     */
    public static function scopeToActionable(Builder $query): Builder
    {
        $user = auth()->user();
        $notOwnRequest = fn (Builder $query): Builder => $query->whereNull('document_requests.requester_id')->orWhere('document_requests.requester_id', '!=', $user->id);
        $assignedToMeOrNobody = fn (string $column): Closure => fn (Builder $query): Builder => $query->whereNull($column)->orWhere($column, $user->id);

        return $query->where(function (Builder $query) use ($user, $notOwnRequest, $assignedToMeOrNobody): void {
            $query->whereRaw('1 = 0');
            if ($user->can('Review:DocumentRequest')) {
                $query->orWhere(fn (Builder $query): Builder => $query->where('document_requests.status', 'submitted')->where($notOwnRequest)->where($assignedToMeOrNobody('document_requests.reviewer_id')));
            }
            if ($user->can('Approve:DocumentRequest')) {
                $query->orWhere(fn (Builder $query): Builder => $query->where('document_requests.status', 'review')->where($notOwnRequest)->where($assignedToMeOrNobody('document_requests.approver_id')));
            }
            if ($user->can('Create:Document')) {
                $query->orWhere(fn (Builder $query): Builder => $query->where('document_requests.status', 'approved')
                    ->when(! $user->can('Approve:DocumentRequest'), fn (Builder $query): Builder => $query->where('document_requests.requester_id', $user->id)));
            }
            if ($user->can('Update:DocumentRequest')) {
                $query->orWhere(fn (Builder $query): Builder => $query->where('document_requests.requester_id', $user->id)->whereIn('document_requests.status', ['draft', 'revision']));
            }
        });
    }

    public static function countActionable(): int
    {
        return static::scopeToActionable(static::getEloquentQuery())->count();
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
