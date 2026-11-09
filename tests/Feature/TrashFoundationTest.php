<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\TrashLog;
use App\Models\User;
use App\Services\UserDeactivator;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrashFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(config('filament-shield.super_admin.name'));

        return $user;
    }

    /** @return array<string, Model> */
    private function models(): array
    {
        return [
            'company' => Company::factory()->create(),
            'document_type' => DocumentType::factory()->create(),
            'user' => User::factory()->create(),
            'document_request' => DocumentRequest::factory()->create(),
            'document' => Document::factory()->create(),
        ];
    }

    public function test_five_models_soft_delete_and_record_who_deleted(): void
    {
        $actor = User::factory()->create();

        foreach ($this->models() as $subject => $model) {
            $model->forceFill(['deleted_by' => $actor->id])->save();
            $model->delete();

            $this->assertSoftDeleted($model);
            $fresh = $model->newQuery()->withTrashed()->findOrFail($model->getKey());
            $this->assertTrue($fresh->deletedBy->is($actor), $subject);
            $this->assertSame($subject, $fresh->trashSubject());
            $this->assertArrayHasKey($fresh->trashSubject(), TrashLog::SUBJECTS);
            $this->assertNotSame('', $fresh->trashLabel());
        }
    }

    public function test_deleted_by_is_readable_even_when_the_deleter_is_trashed(): void
    {
        $actor = User::factory()->create();
        $company = Company::factory()->create();
        $company->forceFill(['deleted_by' => $actor->id])->save();
        $company->delete();
        $actor->delete();

        $this->assertTrue($company->fresh()->deletedBy->is($actor));
    }

    public function test_display_name_marks_trashed_records(): void
    {
        $company = Company::factory()->create(['name' => 'PT A']);
        $this->assertSame('PT A', $company->displayName());

        $company->delete();

        $this->assertSame('PT A (dihapus)', $company->displayName());
        $this->assertSame('PT A', $company->trashLabel());
    }

    public function test_trash_labels_use_the_name_or_title_of_each_model(): void
    {
        $this->assertSame('PT B', Company::factory()->create(['name' => 'PT B'])->trashLabel());
        $this->assertSame('Jenis X', DocumentType::factory()->create(['name' => 'Jenis X'])->trashLabel());
        $this->assertSame('Budi', User::factory()->create(['name' => 'Budi'])->trashLabel());
        $request = DocumentRequest::factory()->create(['title' => 'Judul Pengajuan']);
        $this->assertSame("#{$request->id} · Judul Pengajuan", $request->trashLabel());
        $this->assertSame('Judul Dokumen', Document::factory()->create(['title' => 'Judul Dokumen'])->trashLabel());
    }

    public function test_trash_log_table_and_constants_match_the_contract(): void
    {
        $this->assertSame(['deleted' => 'Dihapus', 'restored' => 'Dipulihkan', 'force_deleted' => 'Dihapus permanen'], TrashLog::ACTIONS);
        $this->assertSame(['document' => 'Dokumen', 'company' => 'Badan Usaha', 'document_type' => 'Jenis Dokumen', 'user' => 'User', 'document_request' => 'Pengajuan'], TrashLog::SUBJECTS);
        foreach (['id', 'subject_type', 'subject_id', 'subject_label', 'action', 'user_id', 'details', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('trash_logs', $column), $column);
        }

        $actor = User::factory()->create();
        $log = TrashLog::create(['subject_type' => 'company', 'subject_id' => 9999, 'subject_label' => 'PT Hilang', 'action' => 'force_deleted', 'user_id' => $actor->id, 'details' => ['files' => ['a.pdf']]]);

        $this->assertSame(['files' => ['a.pdf']], $log->fresh()->details);
        $this->assertTrue($log->user->is($actor));
        $actor->forceDelete();
        $this->assertNull($log->fresh()->user_id);
    }

    public function test_only_super_admin_may_restore_or_force_delete_even_with_permission(): void
    {
        $company = Company::factory()->create();
        $regular = User::factory()->create();
        $regular->givePermissionTo(['Restore:Company', 'RestoreAny:Company', 'ForceDelete:Company', 'ForceDeleteAny:Company']);
        $superAdmin = $this->superAdmin();

        foreach (['restore', 'forceDelete'] as $ability) {
            $this->assertFalse($regular->can($ability, $company), $ability);
            $this->assertTrue($superAdmin->can($ability, $company), $ability);
        }
        foreach (['restoreAny', 'forceDeleteAny'] as $ability) {
            $this->assertFalse($regular->can($ability, Company::class), $ability);
            $this->assertTrue($superAdmin->can($ability, Company::class), $ability);
        }
    }

    public function test_restore_and_force_delete_are_super_admin_only_for_all_five_models(): void
    {
        $regular = User::factory()->create();
        $regular->givePermissionTo(PermissionSeeder::permissionNames());
        $superAdmin = $this->superAdmin();

        foreach ($this->models() as $subject => $model) {
            foreach (['restore', 'forceDelete'] as $ability) {
                $this->assertFalse($regular->can($ability, $model), "{$subject} {$ability}");
                $this->assertTrue($superAdmin->can($ability, $model), "{$subject} {$ability}");
            }
            foreach (['restoreAny', 'forceDeleteAny'] as $ability) {
                $this->assertFalse($regular->can($ability, $model::class), "{$subject} {$ability}");
                $this->assertTrue($superAdmin->can($ability, $model::class), "{$subject} {$ability}");
            }
        }
    }

    public function test_delete_follows_delete_permission(): void
    {
        $request = DocumentRequest::factory()->create();
        $user = User::factory()->create();

        $this->assertFalse($user->can('delete', $request));
        $this->assertFalse($user->can('deleteAny', DocumentRequest::class));

        $user->givePermissionTo('Delete:DocumentRequest');
        $this->assertTrue($user->can('delete', $request));
        $this->assertFalse($user->can('deleteAny', DocumentRequest::class));

        $user->givePermissionTo('DeleteAny:DocumentRequest');
        $this->assertTrue($user->can('deleteAny', DocumentRequest::class));
    }

    public function test_user_cannot_delete_self_or_last_super_admin(): void
    {
        $superAdmin = $this->superAdmin();
        $this->assertFalse($superAdmin->can('delete', $superAdmin));
        $this->assertSame('Anda tidak dapat menghapus akun sendiri.', app(UserDeactivator::class)->deletionDenialReason($superAdmin, $superAdmin));

        $other = User::factory()->create();
        $this->assertTrue($superAdmin->can('delete', $other));

        $manager = User::factory()->create();
        $manager->givePermissionTo(['Update:User', 'Delete:User']);
        $this->assertFalse($manager->can('delete', $superAdmin), 'Non-super-admin tidak boleh menghapus super admin');
        $this->assertTrue($manager->can('delete', $other));
        $this->assertFalse($manager->can('delete', $manager));

        $inactiveSuperAdmin = $this->superAdmin();
        $inactiveSuperAdmin->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($inactiveSuperAdmin->can('delete', $superAdmin), 'Super admin aktif terakhir tidak boleh dihapus');
    }

    public function test_delete_user_requires_the_delete_permission(): void
    {
        $target = User::factory()->create();
        $actor = User::factory()->create();
        $actor->givePermissionTo('Update:User');

        $this->assertFalse($actor->can('delete', $target));
        $this->assertFalse($actor->can('deleteAny', User::class));

        $actor->givePermissionTo(['Delete:User', 'DeleteAny:User']);
        $this->assertTrue($actor->can('delete', $target));
        $this->assertTrue($actor->can('deleteAny', User::class));
    }

    public function test_deactivated_user_only_blocks_self_and_super_admin_deletion(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('Delete:User');
        $deactivated = User::factory()->deactivated()->create();
        $deactivatedSuperAdmin = $this->superAdmin();
        $deactivatedSuperAdmin->forceFill(['deactivated_at' => now()])->save();
        $deactivator = app(UserDeactivator::class);

        $this->assertNull($deactivator->deletionDenialReason($actor, $deactivated));
        $this->assertTrue($actor->can('delete', $deactivated), 'Tanpa izin Update:User, user nonaktif tetap bisa dihapus');
        $this->assertNotNull($deactivator->deletionDenialReason($actor, $deactivatedSuperAdmin));
        $this->assertFalse($actor->can('delete', $deactivatedSuperAdmin));

        $selfDeactivated = User::factory()->deactivated()->create();
        $selfDeactivated->givePermissionTo('Delete:User');
        $this->assertFalse($selfDeactivated->can('delete', $selfDeactivated));

        $superAdmin = $this->superAdmin();
        $this->assertTrue($superAdmin->can('delete', $deactivatedSuperAdmin));
    }
}
