<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Services\ReminderHealthChecker;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

class ReminderHealthAlerts extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.reminder-health-alerts';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', ReminderTemplate::class) || Gate::allows('viewAny', ReminderLog::class);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);
        $canOpenSettings = Gate::allows('viewAny', ReminderTemplate::class);
        $canOpenLogs = Gate::allows('viewAny', ReminderLog::class);

        $alerts = array_map(function (array $alert) use ($canOpenSettings, $canOpenLogs): array {
            $alert['url'] = match ($alert['key']) {
                'template', 'recipient' => $canOpenSettings ? ReminderTemplateResource::getUrl('index') : null,
                'failed' => $canOpenLogs ? ReminderLogResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'failed']]]) : null,
                default => null,
            };

            return $alert;
        }, app(ReminderHealthChecker::class)->alerts());

        return ['alerts' => $alerts];
    }
}
