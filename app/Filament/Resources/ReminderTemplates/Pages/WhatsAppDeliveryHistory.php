<?php

namespace App\Filament\Resources\ReminderTemplates\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\ReminderLog;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class WhatsAppDeliveryHistory extends Page
{
    use WithPagination;

    protected static string $resource = ReminderTemplateResource::class;

    protected string $view = 'filament.resources.reminder-templates.delivery-history';

    public string $source = 'all';

    public string $deliveryStatus = 'all';

    public function getTitle(): string
    {
        return 'Riwayat pengiriman WhatsApp';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->label('Pengaturan WhatsApp')->color('gray')->outlined()->url(ReminderTemplateResource::getUrl())];
    }

    /** @return array<string, string> */
    public function sources(): array
    {
        $sources = ['all' => 'Semua jenis', 'test' => 'Pesan uji saya'];
        if (auth()->user()->can('ViewAny:ReminderLog') && auth()->user()->can('View:ReminderLog')) {
            $sources['reminder'] = 'Pengingat masa berlaku';
        }
        if (auth()->user()->can('ViewAny:DocumentRequest') && auth()->user()->can('View:DocumentRequest')) {
            $sources['notification'] = 'Notifikasi pengajuan';
        }

        return $sources;
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    public function updatedDeliveryStatus(): void
    {
        $this->resetPage();
    }

    public function deliveries(): LengthAwarePaginator
    {
        $query = DB::table('whatsapp_test_messages')->where('user_id', auth()->id())
            ->select(['id', 'template_event', 'recipient_phone', 'status', 'error_message', 'created_at', 'message'])
            ->selectRaw("'test' as source, null as reference_id");
        $sources = $this->sources();
        if (isset($sources['reminder'])) {
            $query->unionAll(DB::table('reminder_logs')
                ->select(['id', DB::raw("'reminder' as template_event"), 'recipient_phone', 'status', 'error_message', 'created_at', 'request_payload as message'])
                ->selectRaw("'reminder' as source, document_version_id as reference_id"));
        }
        if (isset($sources['notification'])) {
            $query->unionAll(DB::table('document_request_notifications')
                ->whereIn('document_request_id', DocumentRequestResource::getEloquentQuery()->select('document_requests.id')->toBase())
                ->select(['id', DB::raw("'notification' as template_event"), 'recipient_phone', 'status', 'error_message', 'created_at', 'payload as message'])
                ->selectRaw("'notification' as source, document_request_id as reference_id"));
        }
        $deliveries = DB::query()->fromSub($query, 'deliveries')
            ->when($this->source !== 'all', fn (Builder $query): Builder => $query->where('source', $this->source))
            ->when($this->deliveryStatus !== 'all', fn (Builder $query): Builder => $query->where('status', $this->deliveryStatus))
            ->orderByDesc('created_at')->orderBy('source')->orderByDesc('id')->paginate(15);
        $deliveries->getCollection()->transform(function (object $delivery): object {
            if ($delivery->source !== 'test') {
                $payload = json_decode($delivery->message ?? '{}', true);
                $delivery->message = $payload['message']['text'] ?? '';
            }
            $delivery->status_label = ReminderLog::STATUSES[$delivery->status] ?? $delivery->status;

            return $delivery;
        });

        return $deliveries;
    }
}
