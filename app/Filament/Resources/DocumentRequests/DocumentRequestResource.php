<?php

namespace App\Filament\Resources\DocumentRequests;

use App\Filament\Concerns\TrashActions;
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
            if ($user->can('Review:DocumentRequest')) {
                $query->orWhere(fn (Builder $query): Builder => $query->where('status', '!=', 'draft')->where(fn (Builder $query): Builder => $query->whereNull('reviewer_id')->orWhere('reviewer_id', $user->id)->orWhereIn('status', DocumentRequest::LEGAL_VISIBLE_STATUSES)));
            }
        });
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return TrashActions::routeBindingQuery(parent::getRecordRouteBindingEloquentQuery());
    }

    /**
     * Batasi $query ke pengajuan yang bisa ditindaklanjuti user saat ini juga. Mengikuti DocumentRequestPolicy:
     * diperiksa (review), dokumen fisik ditunggu tim legal (handover), dikirim ulang oleh pengaju (draf/revisi),
     * serta scan dan dokumen fisik yang harus diserahkan pengaju (signing, handover).
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
                $query->orWhere(fn (Builder $query): Builder => $query->whereIn('document_requests.status', ['submitted', 'drafting'])->where($notOwnRequest)->where($assignedToMeOrNobody('document_requests.reviewer_id')));
            }
            if ($user->can('Review:DocumentRequest') && $user->can('Create:Document')) {
                // Dokumen fisik ditunggu tim legal atau menunggu discan: semua Staff Legal yang boleh mengarsipkan dapat menindaklanjutinya.
                $query->orWhere(fn (Builder $query): Builder => $query->whereIn('document_requests.status', ['handover', 'scanning'])->where($notOwnRequest));
            }
            $query->orWhere(fn (Builder $query): Builder => $query->where('document_requests.requester_id', $user->id)->whereIn('document_requests.status', ['signing', 'handover']));
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
