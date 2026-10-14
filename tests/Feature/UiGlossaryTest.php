<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Istilah baku antarmuka: "Tanpa Masa Berlaku" (bukan "masa tenggang"), "Badan Usaha" (bukan "Perusahaan"),
 * dan "Peran" (bukan "Role"). Hanya teks tampilan; kunci variabel dan isi pesan bawaan tidak berubah.
 */
class UiGlossaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'), $permissions));
        $this->actingAs($user);

        return $user;
    }

    public function test_no_expiry_status_uses_the_validity_wording(): void
    {
        $this->assertSame('Tanpa Masa Berlaku', Document::EXPIRY_STATUSES['no_expiry']);
        $this->assertNotContains('Tanpa Masa Tenggang', Document::EXPIRY_STATUSES);
    }

    public function test_document_list_shows_the_no_expiry_placeholder_in_sentence_case(): void
    {
        $this->signIn(['ViewAny:Document', 'View:Document']);
        $type = DocumentType::factory()->create(['has_expiry' => false]);
        $document = Document::factory()->create(['document_type_id' => $type->id]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => null]);
        $document->current_version_id = $version->id;
        $document->save();

        Livewire::test(ListDocuments::class)
            ->assertSee('Tanpa masa berlaku')
            ->assertSee('Tanpa Masa Berlaku')
            ->assertDontSee('Tanpa batas')
            ->assertDontSee('Tenggang');
    }

    public function test_reminder_variable_labels_say_badan_usaha_but_keep_their_keys_and_default_messages(): void
    {
        foreach ([ReminderTemplate::VARIABLE_LABELS, ReminderTemplate::OVERDUE_VARIABLE_LABELS] as $labels) {
            $this->assertSame('Nama Badan Usaha', $labels['perusahaan']);
        }
        foreach ([ReminderTemplate::REQUEST_REMINDER_VARIABLE_LABELS, ReminderTemplate::REQUEST_VARIABLE_LABELS] as $labels) {
            $this->assertSame('Nama badan usaha', $labels['perusahaan']);
        }
        $this->assertStringContainsString('{perusahaan}', ReminderTemplate::DEFAULT_BODY);
        $this->assertStringContainsString('{perusahaan}', ReminderTemplate::DEFAULT_OVERDUE_BODY);
    }

    public function test_user_screens_say_peran_instead_of_role(): void
    {
        $this->signIn(['ViewAny:User', 'Create:User']);

        $table = Livewire::test(ListUsers::class)->assertSee('Belum ada peran')->assertDontSee('Belum ada role')->instance()->getTable();
        $this->assertSame('Peran', $table->getColumn('roles.name')->getLabel());
        $this->assertSame('Peran', $table->getFilter('roles')->getLabel());

        Livewire::test(CreateUser::class)
            ->assertSee('Peran')
            ->assertSee('Hak akses mengikuti peran. User tanpa peran atau izin tidak dapat masuk panel.')
            ->assertDontSee('mengikuti role');
    }
}
