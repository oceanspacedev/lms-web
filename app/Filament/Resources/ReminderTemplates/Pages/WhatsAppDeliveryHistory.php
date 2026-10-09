<?php

namespace App\Filament\Resources\ReminderTemplates\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentReminderSender;
use App\Services\DocumentRequestNotifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
            ->selectRaw("'test' as source, null as reference_id, null as resent_by, null as resent_at");
        $sources = $this->sources();
        if (isset($sources['reminder'])) {
            $query->unionAll(DB::table('reminder_logs')
                ->select(['id', DB::raw("'reminder' as template_event"), 'recipient_phone', 'status', 'error_message', 'created_at', 'request_payload as message'])
                ->selectRaw("'reminder' as source, document_version_id as reference_id, resent_by, resent_at"));
        }
        if (isset($sources['notification'])) {
            $query->unionAll(DB::table('document_request_notifications')
                ->whereIn('document_request_id', DocumentRequestResource::getEloquentQuery()->select('document_requests.id')->toBase())
                ->select(['id', DB::raw("'notification' as template_event"), 'recipient_phone', 'status', 'error_message', 'created_at', 'payload as message'])
                ->selectRaw("'notification' as source, document_request_id as reference_id, resent_by, resent_at"));
        }
        $deliveries = DB::query()->fromSub($query, 'deliveries')
            ->when($this->source !== 'all', fn (Builder $query): Builder => $query->where('source', $this->source))
            ->when($this->deliveryStatus !== 'all', fn (Builder $query): Builder => $query->where('status', $this->deliveryStatus))
            ->orderByDesc('created_at')->orderBy('source')->orderByDesc('id')->paginate(15);
        $names = User::whereIn('id', $deliveries->getCollection()->pluck('resent_by')->filter()->unique())->pluck('name', 'id');
        $deliveries->getCollection()->transform(function (object $delivery) use ($names): object {
            if ($delivery->source !== 'test') {
                $payload = json_decode($delivery->message ?? '{}', true);
                $delivery->message = $payload['message']['text'] ?? '';
            }
            $delivery->status_label = ReminderLog::STATUSES[$delivery->status] ?? $delivery->status;
            $delivery->resent_by_name = $names[$delivery->resent_by] ?? null;
            $delivery->can_resend = $delivery->status === 'failed' && in_array($delivery->source, ['reminder', 'notification'], true) && $this->canResend();

            return $delivery;
        });

        return $deliveries;
    }

    /** Hanya user yang boleh mengubah pengaturan WhatsApp yang boleh mengirim ulang. */
    public function canResend(): bool
    {
        $setting = ReminderTemplate::globalSetting();

        return $setting !== null && ReminderTemplateResource::canEdit($setting);
    }

    public function resend(string $source, int $id): void
    {
        abort_unless($this->canResend() && isset($this->sources()[$source]) && in_array($source, ['reminder', 'notification'], true), 403);
        if ($source === 'reminder') {
            $outcome = app(DocumentReminderSender::class)->resend(ReminderLog::findOrFail($id), auth()->user());
        } else {
            $notification = DocumentRequestNotification::whereIn('document_request_id', DocumentRequestResource::getEloquentQuery()->select('document_requests.id')->toBase())->findOrFail($id);
            $outcome = app(DocumentRequestNotifier::class)->resend($notification, auth()->user());
        }
        match ($outcome) {
            'accepted' => Notification::make()->title('Pesan diterima WagHub')->body('Status ini belum memastikan pesan diterima di WhatsApp tujuan.')->success()->send(),
            'failed' => Notification::make()->title('Pengiriman gagal lagi')->body('Periksa nomor penerima dan konfigurasi WagHub, lalu coba lagi.')->danger()->send(),
            'cancelled' => Notification::make()->title('Pesan tidak dikirim')->body('Pengajuan atau dokumen sudah berpindah tahap, sehingga pesan ini tidak berlaku lagi.')->warning()->send(),
            default => Notification::make()->title('Pesan ini tidak dapat dikirim ulang')->body('Hanya pesan berstatus Gagal yang dapat dikirim ulang.')->warning()->send(),
        };
    }
}
