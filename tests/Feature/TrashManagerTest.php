<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\ReminderTemplate;
use App\Models\TrashLog;
use App\Models\User;
use App\Services\Trash\CompanyTrashHandler;
use App\Services\Trash\TrashHandler;
use App\Services\Trash\TrashManager;
use App\Services\Trash\TrashUsage;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class TrashManagerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): TrashManager
    {
        return app(TrashManager::class);
    }

    private function trashedCompany(User $actor, array $attributes = []): Company
    {
        $company = Company::factory()->create($attributes);
        $this->manager()->trash($company, $actor);

        return $company;
    }

    /** Template pengingat per jenis dokumen dari sebelum pengaturan pengingat menjadi global (tanpa `global_key`). */
    private function legacyReminderTemplate(): ReminderTemplate
    {
        return ReminderTemplate::withoutEvents(fn (): ReminderTemplate => ReminderTemplate::factory()->create());
    }

    private function forceDeleteError(Model $record, User $actor): string
    {
        try {
            $this->manager()->forceDelete($record, $actor);
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }

        $this->fail('Hapus permanen seharusnya ditolak.');
    }

    public function test_trash_and_restore_company_writes_logs_with_actor(): void
    {
        $deleter = User::factory()->create();
        $restorer = User::factory()->create();
        $company = Company::factory()->create(['name' => 'PT Salah Input']);

        $this->manager()->trash($company, $deleter);

        $this->assertSoftDeleted($company);
        $this->assertSame($deleter->id, Company::withTrashed()->find($company->id)->deleted_by);
        $this->assertDatabaseHas('trash_logs', ['subject_type' => 'company', 'subject_id' => $company->id, 'subject_label' => 'PT Salah Input', 'action' => 'deleted', 'user_id' => $deleter->id]);

        $this->manager()->restore($company, $restorer);

        $fresh = Company::find($company->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_by);
        $this->assertDatabaseHas('trash_logs', ['subject_type' => 'company', 'subject_id' => $company->id, 'subject_label' => 'PT Salah Input', 'action' => 'restored', 'user_id' => $restorer->id]);
        $this->assertSame(2, TrashLog::count());
    }

    public function test_force_delete_company_used_by_active_document_is_blocked_with_names(): void
    {
        $actor = User::factory()->create();
        $company = Company::factory()->create();
        Document::factory()->for($company)->create(['title' => 'PKS Sewa Gudang']);
        $request = DocumentRequest::factory()->for($company)->create();
        $this->manager()->trash($company, $actor);

        $message = $this->forceDeleteError($company, $actor);

        $this->assertSame("Tidak bisa dihapus permanen: masih dipakai 1 dokumen (PKS Sewa Gudang) dan 1 pengajuan (#{$request->id}). Hapus atau pindahkan data itu dulu.", $message);
        $this->assertSoftDeleted($company);
        $this->assertDatabaseMissing('trash_logs', ['action' => 'force_deleted']);
    }

    public function test_force_delete_unused_company_removes_row_and_logs_label(): void
    {
        $actor = User::factory()->create();
        $company = $this->trashedCompany($actor, ['name' => 'PT Uji Coba']);

        $this->manager()->forceDelete($company, $actor);

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $log = TrashLog::where('action', 'force_deleted')->sole();
        $this->assertSame(['company', $company->id, 'PT Uji Coba', $actor->id], [$log->subject_type, $log->subject_id, $log->subject_label, $log->user_id]);
    }

    public function test_usage_summary_counts_documents_requests_and_running_requests(): void
    {
        $company = Company::factory()->create();
        Document::factory()->for($company)->count(2)->create();
        Document::factory()->for($company)->create()->delete();
        DocumentRequest::factory()->for($company)->create(['status' => 'submitted']);
        DocumentRequest::factory()->for($company)->handover()->create();
        DocumentRequest::factory()->for($company)->cancelled()->create();
        DocumentRequest::factory()->for($company)->signing()->create()->delete();

        $usage = $this->manager()->usage($company);

        $this->assertSame([['dokumen', 2], ['pengajuan', 3]], array_map(fn (array $entry): array => [$entry['label'], $entry['count']], $usage->active));
        $this->assertSame([['dokumen', 1], ['pengajuan', 1]], array_map(fn (array $entry): array => [$entry['label'], $entry['count']], $usage->trashed));
        $this->assertSame(['2 pengajuan masih berjalan dan tetap bisa diselesaikan.'], $usage->notes);
        $this->assertTrue($usage->blocksForceDelete());
        $this->assertSame('Badan usaha ini masih dipakai 2 dokumen dan 3 pengajuan. Dokumen dan pengajuan itu tidak ikut terhapus, dan nama badan usaha tetap tampil di sana dengan tanda (dihapus). 2 pengajuan masih berjalan dan tetap bisa diselesaikan.', $usage->summary());

        $unused = $this->manager()->usage(Company::factory()->create());
        $this->assertFalse($unused->blocksForceDelete());
        $this->assertSame('', $unused->summary());
    }

    public function test_block_message_lists_five_examples_then_more_count(): void
    {
        $actor = User::factory()->create();
        $company = Company::factory()->create();
        foreach (range(1, 7) as $number) {
            Document::factory()->for($company)->create(['title' => "Kontrak {$number}"]);
        }
        $this->manager()->trash($company, $actor);

        $this->assertSame(
            'Tidak bisa dihapus permanen: masih dipakai 7 dokumen (Kontrak 1, Kontrak 2, Kontrak 3, Kontrak 4, Kontrak 5, dan 2 lainnya). Hapus atau pindahkan data itu dulu.',
            $this->forceDeleteError($company, $actor),
        );
    }

    public function test_bulk_reports_done_and_skipped_with_reasons(): void
    {
        $actor = User::factory()->create();
        $used = $this->trashedCompany($actor, ['name' => 'PT Dipakai']);
        Document::factory()->for($used)->create(['title' => 'Kontrak Vendor']);
        $unused = [$this->trashedCompany($actor), $this->trashedCompany($actor)];

        $result = $this->manager()->bulk('forceDelete', [$unused[0], $used, $unused[1]], $actor);

        $this->assertSame(2, $result['done']);
        $this->assertSame([['label' => 'PT Dipakai', 'reason' => 'Tidak bisa dihapus permanen: masih dipakai 1 dokumen (Kontrak Vendor). Hapus atau pindahkan data itu dulu.']], $result['skipped']);
        $this->assertSame(0, Company::withTrashed()->whereKey([$unused[0]->id, $unused[1]->id])->count());
        $this->assertSoftDeleted($used);

        $active = Company::factory()->create(['name' => 'PT Aktif']);
        $result = $this->manager()->bulk('restore', [$used, $active], $actor);

        $this->assertSame(['done' => 1, 'skipped' => [['label' => 'PT Aktif', 'reason' => 'Data ini tidak ada di tempat sampah.']]], $result);
        $this->assertNotSoftDeleted($used);
    }

    public function test_force_delete_document_type_removes_its_reminder_template(): void
    {
        $actor = User::factory()->create();
        $template = $this->legacyReminderTemplate();
        $type = DocumentType::factory()->create(['name' => 'Jenis Keliru', 'reminder_template_id' => $template->id]);
        $this->manager()->trash($type, $actor);

        $this->manager()->forceDelete($type, $actor);

        $this->assertDatabaseMissing('document_types', ['id' => $type->id]);
        $this->assertDatabaseMissing('reminder_templates', ['id' => $template->id]);
        $this->assertDatabaseHas('trash_logs', ['subject_type' => 'document_type', 'subject_id' => $type->id, 'subject_label' => 'Jenis Keliru', 'action' => 'force_deleted']);
    }

    public function test_force_delete_document_type_keeps_global_or_shared_reminder_template(): void
    {
        $actor = User::factory()->create();
        $global = ReminderTemplate::factory()->create();
        $this->assertSame('global', $global->global_key);
        $shared = $this->legacyReminderTemplate();
        DocumentType::factory()->create(['reminder_template_id' => $shared->id])->delete();
        $types = [DocumentType::factory()->create(['reminder_template_id' => $global->id]), DocumentType::factory()->create(['reminder_template_id' => $shared->id])];

        foreach ($types as $type) {
            $this->manager()->trash($type, $actor);
            $this->manager()->forceDelete($type, $actor);
        }

        $this->assertDatabaseHas('reminder_templates', ['id' => $global->id]);
        $this->assertDatabaseHas('reminder_templates', ['id' => $shared->id]);
    }

    public function test_document_type_usage_counts_documents_and_requests(): void
    {
        $actor = User::factory()->create();
        $type = DocumentType::factory()->create();
        Document::factory()->for($type)->create(['title' => 'Akta Pendirian']);
        $this->manager()->trash($type, $actor);

        $this->assertSame('Jenis dokumen ini masih dipakai 1 dokumen. Dokumen itu tidak ikut terhapus, dan nama jenis dokumen tetap tampil di sana dengan tanda (dihapus).', $this->manager()->usage($type)->summary());
        $this->assertSame('Tidak bisa dihapus permanen: masih dipakai 1 dokumen (Akta Pendirian). Hapus atau pindahkan data itu dulu.', $this->forceDeleteError($type, $actor));
    }

    public function test_files_from_purge_are_deleted_after_commit_and_failures_are_logged(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('lms/terhapus.pdf', 'isi');
        Exceptions::fake();
        $handler = new class extends CompanyTrashHandler
        {
            public function purge(Model $record, User $actor): array
            {
                parent::purge($record, $actor);

                return [['disk' => 'local', 'path' => 'lms/terhapus.pdf'], ['disk' => 'disk-tidak-ada', 'path' => 'lms/tertinggal.pdf']];
            }
        };
        $manager = new TrashManager([$handler]);
        $actor = User::factory()->create();
        $company = Company::factory()->create();
        $manager->trash($company, $actor);

        $manager->forceDelete($company, $actor);

        Storage::disk('local')->assertMissing('lms/terhapus.pdf');
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $this->assertSame([['disk' => 'disk-tidak-ada', 'path' => 'lms/tertinggal.pdf']], TrashLog::where('action', 'force_deleted')->sole()->details['left_files']);
        Exceptions::assertReported(fn (InvalidArgumentException $exception): bool => str_contains($exception->getMessage(), 'disk-tidak-ada'));
    }

    public function test_trash_rejects_already_trashed_records_and_force_delete_requires_trash(): void
    {
        $actor = User::factory()->create();
        $company = Company::factory()->create(['name' => 'PT Aktif']);

        $this->assertSame('Data ini belum ada di tempat sampah.', $this->forceDeleteError($company, $actor));
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'deleted_at' => null]);

        $this->manager()->trash($company, $actor);
        $this->assertSame(['done' => 0, 'skipped' => [['label' => 'PT Aktif', 'reason' => 'Data ini sudah ada di tempat sampah.']]], $this->manager()->bulk('trash', [$company], $actor));
        $this->assertSame(1, TrashLog::count());
    }

    public function test_stale_trashed_instance_cannot_be_force_deleted_or_restored_after_someone_else_restored_it(): void
    {
        $actor = User::factory()->create();
        $company = $this->trashedCompany($actor, ['name' => 'PT Sudah Dipulihkan']);
        $stale = Company::withTrashed()->findOrFail($company->id);
        $this->manager()->restore(Company::withTrashed()->findOrFail($company->id), User::factory()->create());
        $this->assertTrue($stale->trashed());

        $this->assertSame('Data ini belum ada di tempat sampah.', $this->forceDeleteError($stale, $actor));
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'deleted_at' => null]);

        try {
            $this->manager()->restore($stale, $actor);
            $this->fail('Pulihkan seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame('Data ini tidak ada di tempat sampah.', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(['deleted', 'restored'], TrashLog::orderBy('id')->pluck('action')->all());
    }

    public function test_logs_keep_the_actor_name(): void
    {
        $actor = User::factory()->create(['name' => 'Penghapus Tercatat']);
        $this->trashedCompany($actor, ['name' => 'PT Nama Pelaku']);

        $this->assertSame(['actor_name' => 'Penghapus Tercatat'], TrashLog::sole()->details);
    }

    public function test_records_without_a_registered_handler_are_rejected(): void
    {
        $this->expectException(LogicException::class);

        $this->manager()->usage(ReminderTemplate::factory()->create());
    }

    public function test_cascaded_trashed_dependents_get_their_own_force_deleted_log_rows(): void
    {
        Storage::fake('local');
        $dependentHandler = new class implements TrashHandler
        {
            public function supports(Model $record): bool
            {
                return $record instanceof Document || $record instanceof DocumentRequest;
            }

            public function usage(Model $record): TrashUsage
            {
                return new TrashUsage;
            }

            public function beforeTrash(Model $record, User $actor, array $data): void {}

            public function afterRestore(Model $record, User $actor): void {}

            public function purge(Model $record, User $actor): array
            {
                $record->forceDelete();

                return [['disk' => 'local', 'path' => "lms/{$record->trashSubject()}-{$record->getKey()}.pdf"]];
            }

            public function trashDenialReason(Model $record, User $actor): ?string
            {
                return null;
            }
        };
        $manager = new TrashManager([new CompanyTrashHandler, $dependentHandler]);
        $this->app->instance(TrashManager::class, $manager);
        $actor = User::factory()->create();
        $company = Company::factory()->create(['name' => 'PT Induk']);
        $document = Document::factory()->for($company)->create(['title' => 'Kontrak Lama']);
        $request = DocumentRequest::factory()->for($company)->create(['title' => 'Pengajuan Uji']);
        $document->delete();
        $request->delete();
        Storage::disk('local')->put("lms/document-{$document->id}.pdf", 'isi');
        Storage::disk('local')->put("lms/document_request-{$request->id}.pdf", 'isi');
        $manager->trash($company, $actor);

        $manager->forceDelete($company, $actor);

        $via = ['subject' => 'company', 'id' => $company->id, 'label' => 'PT Induk'];
        $logs = TrashLog::where('action', 'force_deleted')->orderBy('id')->get()
            ->map(fn (TrashLog $log): array => [$log->subject_type, $log->subject_id, $log->subject_label, $log->user_id, $log->details['via'] ?? null])->all();
        $this->assertSame([
            ['document_request', $request->id, 'Pengajuan Uji', $actor->id, $via],
            ['document', $document->id, 'Kontrak Lama', $actor->id, $via],
            ['company', $company->id, 'PT Induk', $actor->id, null],
        ], $logs);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_requests', ['id' => $request->id]);
        Storage::disk('local')->assertMissing(["lms/document-{$document->id}.pdf", "lms/document_request-{$request->id}.pdf"]);
    }

    public function test_purge_is_only_for_dependents_during_force_delete(): void
    {
        $company = Company::factory()->create();
        $company->delete();

        $this->expectException(LogicException::class);

        $this->manager()->purge($company, User::factory()->create());
    }

    public function test_restore_document_type_whose_pic_was_deactivated_succeeds(): void
    {
        $this->seed(PermissionSeeder::class);
        $pic = User::factory()->create(['phone' => '081200000001']);
        $pic->givePermissionTo('Review:DocumentRequest');
        $type = DocumentType::factory()->create(['request_pic_id' => $pic->id]);
        $actor = User::factory()->create();
        $this->manager()->trash($type, $actor);
        $pic->forceFill(['deactivated_at' => now()])->save();
        $restoredEvents = 0;
        DocumentType::restored(function () use (&$restoredEvents): void {
            $restoredEvents++;
        });

        $this->manager()->restore($type, $actor);

        $fresh = DocumentType::find($type->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_by);
        $this->assertSame($pic->id, $fresh->request_pic_id);
        $this->assertFalse($type->isDirty());
        $this->assertSame(1, $restoredEvents);
        $this->assertDatabaseHas('trash_logs', ['subject_type' => 'document_type', 'subject_id' => $type->id, 'action' => 'restored', 'user_id' => $actor->id]);
    }
}
