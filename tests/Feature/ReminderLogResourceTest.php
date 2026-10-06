<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Filament\Resources\ReminderLogs\Pages\ListReminderLogs;
use App\Filament\Resources\ReminderLogs\Pages\ViewReminderLog;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Models\ReminderLog;
use App\Models\User;
use Database\Seeders\ReminderLogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReminderLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(ReminderLogSeeder::class);
    }

    public function test_authorized_user_can_read_logs_without_mutating_actions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:ReminderLog', 'View:ReminderLog']);
        $this->actingAs($user);
        $log = ReminderLog::factory()->create();
        Livewire::test(ListReminderLogs::class)->assertCanSeeTableRecords([$log]);
        Livewire::test(ViewReminderLog::class, ['record' => $log->id])->assertSuccessful()->assertSee($log->recipient_phone);
        $this->assertFalse(ReminderLogResource::canCreate());
        $this->assertFalse(ReminderLogResource::canEdit($log));
        $this->assertFalse(ReminderLogResource::canDelete($log));
        $this->assertSame(['index', 'view'], array_keys(ReminderLogResource::getPages()));
    }

    public function test_user_without_permission_cannot_read_logs(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ListReminderLogs::class)->assertForbidden();
        Livewire::test(ViewReminderLog::class, ['record' => ReminderLog::factory()->create()->id])->assertForbidden();
    }

    public function test_profile_normalizes_whatsapp_number_and_rejects_invalid_phone(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('ViewAny:ReminderLog');
        $this->actingAs($user);
        Livewire::test(EditProfile::class)->fillForm(['phone' => 'abc123'])
            ->call('save')->assertHasFormErrors(['phone']);
        Livewire::test(EditProfile::class)->fillForm(['phone' => '+62 812-3456-7890'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('6281234567890', $user->fresh()->phone);
    }
}
