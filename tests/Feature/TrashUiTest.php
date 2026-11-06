<?php

namespace Tests\Feature;

use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Trash\TrashManager;
use App\Services\Trash\UserTrashHandler;
use App\Services\UserDeactivator;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Aksi hapus, pulihkan, hapus permanen, hapus massal, dan filter "Data dihapus" di lima menu (spec §3.1–§3.4). */
class TrashUiTest extends TestCase
{
    use RefreshDatabase;

    private static function explanation(string $noun): string
    {
        return ucfirst($noun).' dipindahkan ke tempat sampah dan hilang dari daftar. Super admin bisa memulihkannya atau menghapusnya permanen.';
    }

    /** Kalimat tambahan modal hapus yang mengarahkan ke Batalkan atau Nonaktifkan; null untuk menu lain. */
    private static function deleteAdvice(string $key): ?string
    {
        return match ($key) {
            'document_request' => 'Untuk menghentikan pengajuan yang sah, pakai Batalkan — riwayatnya tetap tercatat. Hapus hanya untuk data uji atau salah input.',
            'user' => 'Staf keluar atau pindah? Pakai Nonaktifkan. Hapus hanya untuk user yang salah dibuat.',
            default => null,
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(9, 0));
        Storage::fake('s3');
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(PermissionSeeder::class);
    }

    /** @return array<string, array{string}> */
    public static function resources(): array
    {
        return ['badan usaha' => ['company'], 'jenis dokumen' => ['document_type'], 'dokumen' => ['document'], 'user' => ['user'], 'pengajuan' => ['document_request']];
    }

    /**
     * Data tiap menu: halaman, sebutan, izin, data bebas, data yang dipakai (dengan kalimat keterkaitan dan isian modal),
     * dan data di tempat sampah yang hapus permanennya ditolak (null bila menu itu tidak pernah menolak).
     *
     * @return array{list: class-string, detail: class-string, page: string, resource: class-string, noun: string, model: string, field: ?string,
     *     free: \Closure(): Model, used: \Closure(): array{0: Model, 1: string, 2: array<string, mixed>, 3: ?string}, blocked: (\Closure(User): Model)|null}
     */
    private function scenario(string $key): array
    {
        return match ($key) {
            'company' => [
                'list' => ListCompanies::class, 'detail' => EditCompany::class, 'page' => 'edit', 'resource' => CompanyResource::class,
                'noun' => 'badan usaha', 'model' => 'Company', 'field' => 'name',
                'free' => fn (): Model => Company::factory()->create(),
                'used' => function (): array {
                    $company = Company::factory()->create();
                    Document::factory()->create(['company_id' => $company->id]);

                    return [$company, 'Badan usaha ini masih dipakai 1 dokumen.', [], null];
                },
                'blocked' => function (User $actor): Model {
                    $company = Company::factory()->create();
                    Document::factory()->create(['company_id' => $company->id]);
                    $this->trash($company, $actor);

                    return $company;
                },
            ],
            'document_type' => [
                'list' => ListDocumentTypes::class, 'detail' => EditDocumentType::class, 'page' => 'edit', 'resource' => DocumentTypeResource::class,
                'noun' => 'jenis dokumen', 'model' => 'DocumentType', 'field' => 'name',
                'free' => fn (): Model => DocumentType::factory()->create(),
                'used' => function (): array {
                    $type = DocumentType::factory()->create();
                    Document::factory()->create(['document_type_id' => $type->id]);

                    return [$type, 'Jenis dokumen ini masih dipakai 1 dokumen.', [], null];
                },
                'blocked' => function (User $actor): Model {
                    $type = DocumentType::factory()->create();
                    Document::factory()->create(['document_type_id' => $type->id]);
                    $this->trash($type, $actor);

                    return $type;
                },
            ],
            'document' => [
                'list' => ListDocuments::class, 'detail' => ViewDocument::class, 'page' => 'view', 'resource' => DocumentResource::class,
                'noun' => 'dokumen', 'model' => 'Document', 'field' => null,
                'free' => fn (): Model => Document::factory()->create(),
                'used' => function (): array {
                    $document = Document::factory()->create();
                    $request = DocumentRequest::factory()->create(['status' => 'archived', 'document_id' => $document->id]);

                    return [$document, "Dokumen ini hasil pengajuan #{$request->id}.", [], 'tanda (dihapus)'];
                },
                'blocked' => function (User $actor): Model {
                    $document = Document::factory()->create();
                    DocumentRequest::factory()->create(['status' => 'archived', 'document_id' => $document->id]);
                    $this->trash($document, $actor);

                    return $document;
                },
            ],
            'user' => [
                'list' => ListUsers::class, 'detail' => EditUser::class, 'page' => 'edit', 'resource' => UserResource::class,
                'noun' => 'user', 'model' => 'User', 'field' => 'name',
                'free' => fn (): Model => User::factory()->create(),
                'used' => function (): array {
                    $user = User::factory()->create();
                    Document::factory()->create(['pic_user_id' => $user->id]);

                    return [$user, '1 dokumen sebagai PIC', ['document_pic' => User::factory()->create()->id], null];
                },
                'blocked' => function (User $actor): Model {
                    $user = User::factory()->create();
                    DocumentRequest::factory()->create(['status' => 'archived', 'requester_id' => $user->id]);
                    $this->trash($user, $actor);

                    return $user;
                },
            ],
            'document_request' => [
                'list' => ListDocumentRequests::class, 'detail' => EditDocumentRequest::class, 'page' => 'edit', 'resource' => DocumentRequestResource::class,
                'noun' => 'pengajuan', 'model' => 'DocumentRequest', 'field' => 'title',
                'free' => fn (): Model => DocumentRequest::factory()->create(['status' => 'submitted']),
                'used' => fn (): array => [DocumentRequest::factory()->signing()->create(), 'Pengajuan ini masih berjalan (Menunggu Tanda Tangan). Pengingat terkait pengajuan ini akan berhenti.', [], null],
                'blocked' => null,
            ],
        };
    }

    /** @param list<string> $permissions */
    private function signInWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** Izin lengkap satu menu, termasuk Restore:* dan ForceDelete:* yang tidak membuka tempat sampah. */
    private function signInWithAllPermissionsFor(string $model): User
    {
        $actions = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny'];
        $permissions = array_map(fn (string $action): string => "{$action}:{$model}", $actions);

        return $this->signInWith($model === 'DocumentRequest' ? [...$permissions, 'ViewAll:DocumentRequest'] : $permissions);
    }

    private function signInAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(config('filament-shield.super_admin.name'));
        $this->actingAs($user);

        return $user;
    }

    private function trash(Model $record, User $actor): void
    {
        app(TrashManager::class)->trash($record, $actor);
    }

    /**
     * Daftar dengan filter "Dengan data dihapus". Untuk User, filter Status "Aktif" bawaan dikosongkan, karena pada pilihan ini
     * user di tempat sampah tidak ikut disebut aktif.
     *
     * @param  array{list: class-string}  $scenario
     */
    private function listWithTrashed(array $scenario, string $key): Testable
    {
        $list = Livewire::test($scenario['list'])->filterTable('trashed', true);

        return $key === 'user' ? $list->filterTable('status', null) : $list;
    }

    private function trashed(\Closure $factory, User $actor): Model
    {
        $record = $factory();
        $this->trash($record, $actor);

        return $record;
    }

    private function assertNotifiedWithBody(string $title, string $body): void
    {
        $notification = collect([...session('filament.claimed_notifications', []), ...session('filament.notifications', [])])->firstWhere('title', $title);
        $this->assertNotNull($notification, "Notifikasi \"{$title}\" tidak dikirim.");
        $this->assertSame($body, $notification['body']);
    }

    /** Aksi massal hanya mengirim notifikasi hasilnya sendiri, tanpa notifikasi bawaan Filament ("Data berhasil dihapus"). */
    private function assertOnlyNotified(string $title): void
    {
        $titles = collect([...session('filament.claimed_notifications', []), ...session('filament.notifications', [])])->pluck('title')->all();
        $this->assertSame([$title], $titles);
    }

    #[DataProvider('resources')]
    public function test_delete_action_moves_record_to_trash_with_usage_in_modal(string $key): void
    {
        $scenario = $this->scenario($key);
        $permissions = array_map(fn (string $action): string => "{$action}:{$scenario['model']}", ['ViewAny', 'View', 'Update', 'Delete']);
        $this->signInWith($key === 'document_request' ? [...$permissions, 'ViewAll:DocumentRequest'] : $permissions);
        [$record, $summary, $data, $dontSee] = $scenario['used']();

        $modal = Livewire::test($scenario['detail'], ['record' => $record->getRouteKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee(array_filter(["Hapus {$scenario['noun']} ini?", self::explanation($scenario['noun']), $summary, self::deleteAdvice($key), 'Ya, hapus', 'Kembali']))
            ->assertMountedActionModalDontSee('Data dipindahkan');
        if ($dontSee !== null) {
            $modal->assertMountedActionModalDontSee($dontSee);
        }
        $this->assertNotSoftDeleted($record);

        Livewire::test($scenario['detail'], ['record' => $record->getRouteKey()])
            ->callAction('delete', data: $data)->assertHasNoActionErrors()
            ->assertNotified(ucfirst($scenario['noun']).' dipindahkan ke tempat sampah')
            ->assertRedirect($scenario['resource']::getUrl('index'));
        $this->assertSoftDeleted($record);

        $fromTable = $scenario['free']();
        Livewire::test($scenario['list'])->callTableAction('delete', $fromTable)->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$fromTable]);
        $this->assertSoftDeleted($fromTable);
    }

    #[DataProvider('resources')]
    public function test_non_super_admin_never_sees_trash_filter_restore_or_force_delete(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = User::factory()->create()->assignRole(config('filament-shield.super_admin.name'));
        $trashed = $this->trashed($scenario['free'], $superAdmin);
        $active = $scenario['free']();
        $this->signInWithAllPermissionsFor($scenario['model']);

        Livewire::test($scenario['list'])
            ->assertTableFilterHidden('trashed')
            ->set('tableFilters.trashed.value', '0')
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$trashed])
            ->assertTableColumnHidden('deleted_at')
            ->assertTableActionVisible('delete', $active)
            ->assertTableActionHidden('restore', $active)->assertTableActionHidden('forceDelete', $active)
            ->assertTableBulkActionVisible('delete')
            ->assertTableBulkActionHidden('restore')->assertTableBulkActionHidden('forceDelete');

        $this->assertFalse($scenario['resource']::canRestore($trashed));
        $this->assertFalse($scenario['resource']::canForceDelete($trashed));
        $this->assertFalse($scenario['resource']::canRestoreAny());
        $this->assertFalse($scenario['resource']::canForceDeleteAny());
        $this->get($scenario['resource']::getUrl($scenario['page'], ['record' => $trashed]))->assertNotFound();
    }

    #[DataProvider('resources')]
    public function test_super_admin_can_restore_and_force_delete_from_trash_view(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $toRestore = $this->trashed($scenario['free'], $superAdmin);
        $toPurge = $this->trashed($scenario['free'], $superAdmin);
        $bulkRestored = [$this->trashed($scenario['free'], $superAdmin), $this->trashed($scenario['free'], $superAdmin)];
        $active = $scenario['free']();

        $list = Livewire::test($scenario['list'])
            ->assertTableFilterVisible('trashed')->assertTableColumnHidden('deleted_at')
            ->assertCanNotSeeTableRecords([$toRestore, $toPurge])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$toRestore, $toPurge, ...$bulkRestored])->assertCanNotSeeTableRecords([$active])
            ->assertTableColumnVisible('deleted_at')
            ->assertTableActionVisible('restore', $toRestore)->assertTableActionVisible('forceDelete', $toRestore)
            ->assertTableActionHidden('delete', $toRestore)
            ->assertTableBulkActionVisible('restore')->assertTableBulkActionVisible('forceDelete')->assertTableBulkActionHidden('delete');
        Livewire::test($scenario['list'])->filterTable('trashed', false)
            ->mountTableAction('forceDelete', $toPurge)
            ->assertMountedActionModalSee(["Hapus permanen {$scenario['noun']} ini?", 'Tindakan ini tidak bisa dibatalkan.']);

        $list->callTableAction('restore', $toRestore)->assertHasNoTableActionErrors()
            ->assertNotified($key === 'user' ? 'User dipulihkan dalam keadaan nonaktif' : ucfirst($scenario['noun']).' dipulihkan');
        $this->assertNotSoftDeleted($toRestore);
        $list->callTableAction('forceDelete', $toPurge)->assertHasNoTableActionErrors()->assertNotified(ucfirst($scenario['noun']).' dihapus permanen');
        $this->assertModelMissing($toPurge);
        $list->callTableBulkAction('restore', $bulkRestored);
        $this->assertOnlyNotified("2 {$scenario['noun']} dipulihkan");
        foreach ($bulkRestored as $record) {
            $this->assertNotSoftDeleted($record);
        }

        if ($scenario['blocked'] !== null) {
            $blocked = ($scenario['blocked'])($superAdmin);
            $message = app(TrashManager::class)->usage($blocked)->blockMessage();
            Livewire::test($scenario['list'])->filterTable('trashed', false)
                ->callTableAction('forceDelete', $blocked);
            $this->assertNotifiedWithBody('Hapus permanen ditolak', $message);
            $this->assertSoftDeleted($blocked);
        }
    }

    #[DataProvider('resources')]
    public function test_bulk_force_delete_processes_free_rows_and_reports_blocked_ones(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $free = [$this->trashed($scenario['free'], $superAdmin), $this->trashed($scenario['free'], $superAdmin)];
        $blocked = $scenario['blocked'] === null ? null : ($scenario['blocked'])($superAdmin);
        $expected = "2 {$scenario['noun']} dihapus permanen";
        if ($blocked !== null) {
            $expected .= ', 1 dilewati: '.$blocked->trashLabel().' ('.app(TrashManager::class)->usage($blocked)->blockMessage().')';
        }

        Livewire::test($scenario['list'])->filterTable('trashed', false)
            ->mountTableBulkAction('forceDelete', array_filter([...$free, $blocked]))
            ->assertMountedActionModalSee(['Hapus permanen '.($blocked === null ? 2 : 3)." {$scenario['noun']}?", 'Tindakan ini tidak bisa dibatalkan.',
                ...($blocked === null ? [] : ['1 dari 3 akan ditolak karena masih dipakai.'])])
            ->callMountedTableBulkAction();
        $this->assertOnlyNotified($expected);

        foreach ($free as $record) {
            $this->assertModelMissing($record);
        }
        if ($blocked !== null) {
            $this->assertSoftDeleted($blocked);
        }
    }

    #[DataProvider('resources')]
    public function test_bulk_delete_moves_selected_rows_to_trash(string $key): void
    {
        $scenario = $this->scenario($key);
        $this->signInAsSuperAdmin();
        $selected = [$scenario['free'](), $scenario['free']()];
        [$used] = $scenario['used']();
        $usageSentence = match ($key) {
            'company', 'document_type' => '1 di antaranya masih dipakai dokumen.',
            'document' => '1 di antaranya masih dipakai pengajuan.',
            'user' => '1 di antaranya punya tugas yang harus dialihkan, jadi dilewati; hapus satu per satu.',
            'document_request' => '3 di antaranya masih berjalan; pengingat terkait pengajuan itu akan berhenti.',
        };

        Livewire::test($scenario['list'])
            ->mountTableBulkAction('delete', [...$selected, $used])
            ->assertMountedActionModalSee(array_filter(["Hapus 3 {$scenario['noun']}?", self::explanation($scenario['noun']), $usageSentence, self::deleteAdvice($key)]))
            ->assertMountedActionModalDontSee('baris lain dilewati')
            ->callMountedTableBulkAction();
        $this->assertOnlyNotified($key === 'user'
            ? '2 user dipindahkan ke tempat sampah, 1 dilewati: '.$used->trashLabel().' ('.UserTrashHandler::HAS_WORK_REASON.')'
            : "3 {$scenario['noun']} dipindahkan ke tempat sampah");

        foreach ($selected as $record) {
            $this->assertSoftDeleted($record);
        }
        $key === 'user' ? $this->assertNotSoftDeleted($used) : $this->assertSoftDeleted($used);
    }

    #[DataProvider('resources')]
    public function test_trashed_record_detail_is_read_only_for_super_admin_and_404_for_others(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $trashed = $this->trashed($scenario['free'], $superAdmin);
        $url = $scenario['resource']::getUrl($scenario['page'], ['record' => $trashed]);

        $detail = Livewire::test($scenario['detail'], ['record' => $trashed->getRouteKey()])->assertSuccessful()
            ->assertActionVisible('restore')->assertActionVisible('forceDelete')->assertActionHidden('delete');
        if ($scenario['field'] !== null) {
            $detail->assertFormFieldDisabled($scenario['field'])->call('save')->assertForbidden();
        } else {
            Livewire::test(EditDocument::class, ['record' => $trashed->getRouteKey()])->assertForbidden();
        }
        $this->get($url)->assertOk();

        $this->signInWithAllPermissionsFor($scenario['model']);
        $this->get($url)->assertNotFound();
    }

    public function test_user_delete_modal_requires_replacement_when_user_has_work(): void
    {
        $admin = $this->signInWith(['ViewAny:User', 'View:User', 'Update:User', 'Delete:User', 'DeleteAny:User']);
        $leaver = User::factory()->create(['name' => 'Staf Keluar']);
        $document = Document::factory()->create(['pic_user_id' => $leaver->id]);
        $replacement = User::factory()->create(['name' => 'Pengganti']);

        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee(['Hapus user ini?', '1 dokumen sebagai PIC', 'Pengganti PIC dokumen', 'Peran dan izinnya tetap disimpan bila user dipulihkan.'])
            ->assertMountedActionModalDontSee('agar bisa diaktifkan kembali')
            ->assertActionMounted('delete');

        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->callAction('delete', data: ['document_pic' => null])
            ->assertHasActionErrors(['document_pic' => 'required']);
        $this->assertNotSoftDeleted($leaver);

        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->callAction('delete', data: ['document_pic' => $replacement->id])->assertHasNoActionErrors()
            ->assertNotified('User dipindahkan ke tempat sampah');
        $this->assertSoftDeleted($leaver);
        $this->assertNotNull($leaver->fresh()->deactivated_at);
        $this->assertSame($replacement->id, $document->fresh()->pic_user_id);

        $reviewer = User::factory()->create();
        DocumentRequest::factory()->create(['status' => 'submitted', 'reviewer_id' => $reviewer->id]);
        Livewire::test(EditUser::class, ['record' => $reviewer->getRouteKey()])
            ->mountAction('delete')->assertActionNotMounted('delete')
            ->assertNotified('User belum bisa dihapus');

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])->assertActionHidden('delete');
        $this->assertSame('Anda tidak dapat menghapus akun sendiri.', app(UserDeactivator::class)->deletionDenialReason($admin, $admin));
    }

    public function test_trashed_user_page_hides_activation_actions(): void
    {
        $superAdmin = $this->signInAsSuperAdmin();
        $user = $this->trashed(fn (): User => User::factory()->create(), $superAdmin);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertActionHidden('reactivate')->assertActionHidden('deactivate');
        Livewire::test(ListUsers::class)->filterTable('trashed', false)->assertCanSeeTableRecords([$user])
            ->assertTableActionHidden('reactivate', $user);
    }

    public function test_force_delete_modal_names_trashed_children_that_go_too(): void
    {
        $superAdmin = $this->signInAsSuperAdmin();
        $company = Company::factory()->create();
        $this->trash(Document::factory()->create(['company_id' => $company->id]), $superAdmin);
        $this->trash($company, $superAdmin);

        $list = Livewire::test(ListCompanies::class)->filterTable('trashed', false)
            ->mountTableAction('forceDelete', $company)
            ->assertMountedActionModalSee(['Tindakan ini tidak bisa dibatalkan.', '1 dokumen di tempat sampah yang memakai badan usaha ini juga akan dihapus permanen beserta semua versi dan filenya.']);
        $this->assertFalse($list->instance()->getMountedAction()->getModalSubmitAction()->isDisabled());

        // Anak yang bukan dokumen tidak punya versi dan file, jadi kalimatnya tanpa "beserta semua versi dan filenya".
        $type = DocumentType::factory()->create();
        $this->trash(DocumentRequest::factory()->create(['document_type_id' => $type->id]), $superAdmin);
        $this->trash($type, $superAdmin);
        Livewire::test(ListDocumentTypes::class)->filterTable('trashed', false)
            ->mountTableAction('forceDelete', $type)
            ->assertMountedActionModalSee('1 pengajuan di tempat sampah yang memakai jenis dokumen ini juga akan dihapus permanen.')
            ->assertMountedActionModalDontSee('beserta semua versi dan filenya');
    }

    /** Anak di tempat sampah yang ikut terhapus permanen tetapi masih dipakai data aktif juga menghalangi, sejak modal dibuka. */
    public function test_force_delete_modal_and_bulk_count_a_blocked_trashed_child(): void
    {
        $superAdmin = $this->signInAsSuperAdmin();
        $company = Company::factory()->create(['name' => 'PT Induk Berantai']);
        $document = Document::factory()->create(['company_id' => $company->id, 'title' => 'Kontrak Anak']);
        $request = DocumentRequest::factory()->create(['status' => 'archived', 'document_id' => $document->id]);
        $this->trash($document, $superAdmin);
        $this->trash($company, $superAdmin);
        $free = $this->trashed(fn (): Company => Company::factory()->create(), $superAdmin);
        $message = "Tidak bisa dihapus permanen: dokumen \"Kontrak Anak\" di tempat sampah masih dipakai pengajuan #{$request->id}. Hapus dulu pengajuan itu, lalu coba lagi.";
        $this->assertSame($message, app(TrashManager::class)->forceDeleteBlockMessage($company));

        $list = Livewire::test(ListCompanies::class)->filterTable('trashed', false)
            ->mountTableAction('forceDelete', $company)
            ->assertMountedActionModalSee($message)->assertMountedActionModalDontSee(TrashActions::IRREVERSIBLE);
        $this->assertTrue($list->instance()->getMountedAction()->getModalSubmitAction()->isDisabled());
        $list->callMountedTableAction();
        $this->assertNotifiedWithBody('Hapus permanen ditolak', $message);
        $this->assertSoftDeleted($company);
        $this->assertSoftDeleted($document);

        Livewire::test(ListCompanies::class)->filterTable('trashed', false)
            ->mountTableBulkAction('forceDelete', [$company, $free])
            ->assertMountedActionModalSee('1 dari 2 akan ditolak karena masih dipakai.')
            ->assertMountedActionModalDontSee('beserta semua versi dan filenya');
    }

    /** @return array<string, array{string}> */
    public static function blockingResources(): array
    {
        return array_diff_key(self::resources(), ['pengajuan' => true]);
    }

    #[DataProvider('blockingResources')]
    public function test_force_delete_modal_explains_a_block_and_disables_the_submit_button(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $blocked = ($scenario['blocked'])($superAdmin);
        $message = app(TrashManager::class)->usage($blocked)->blockMessage();

        $list = Livewire::test($scenario['list'])->filterTable('trashed', false)
            ->mountTableAction('forceDelete', $blocked)
            ->assertMountedActionModalSee(["Hapus permanen {$scenario['noun']} ini?", $message])
            ->assertMountedActionModalDontSee(TrashActions::IRREVERSIBLE);
        $this->assertTrue($list->instance()->getMountedAction()->getModalSubmitAction()->isDisabled());

        // Pemeriksaan di server tetap berlaku walaupun tombolnya dilewati.
        $list->callMountedTableAction();
        $this->assertNotifiedWithBody('Hapus permanen ditolak', $message);
        $this->assertSoftDeleted($blocked);
    }

    #[DataProvider('resources')]
    public function test_trashed_rows_show_when_and_by_whom_they_were_deleted(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $superAdmin->update(['name' => 'Admin Pusat']);
        $trashed = $this->trashed($key === 'document_request' ? fn (): Model => DocumentRequest::factory()->handover()->create() : $scenario['free'], $superAdmin);
        $active = $scenario['free']();

        $list = $this->listWithTrashed($scenario, $key)
            ->assertTableColumnFormattedStateSet('deleted_at', '05/11/2026 16:00 WIB oleh Admin Pusat', $trashed);
        $this->assertFalse($list->instance()->getTable()->getColumn('deleted_at')->isBadge());

        // Badan Usaha, Jenis Dokumen, User, dan Dokumen: status "Dihapus" abu-abu; Pengajuan tetap status alurnya, tanpa Keterangan.
        match ($key) {
            'company', 'document_type' => $list->assertTableColumnFormattedStateSet('is_active', 'Dihapus', $trashed)
                ->assertTableColumnFormattedStateSet('is_active', 'Aktif', $active),
            'user' => $list->assertTableColumnStateSet('status', 'Dihapus', $trashed)->assertTableColumnStateSet('status', 'Aktif', $active),
            'document' => $list->assertTableColumnFormattedStateSet('expiry_status', 'Dihapus', $trashed),
            'document_request' => $list->assertTableColumnFormattedStateSet('status', 'Menunggu Dokumen Fisik', $trashed)
                ->assertTableColumnStateSet('handover_note', null, $trashed),
        };
    }

    /**
     * Di tampilan "Hanya data dihapus" kolom status baru tampil mulai layar `md`, supaya di layar HP tombol Lihat, Pulihkan, dan
     * Hapus permanen muat tanpa menggeser tabel. Di tampilan lain kolom status tampil di semua layar.
     */
    #[DataProvider('resources')]
    public function test_status_column_steps_aside_on_small_screens_only_in_the_trash_view(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $this->trashed($key === 'document_request' ? fn (): Model => DocumentRequest::factory()->handover()->create() : $scenario['free'], $superAdmin);
        $column = ['company' => 'is_active', 'document_type' => 'is_active', 'user' => 'status', 'document' => 'expiry_status', 'document_request' => 'status'][$key];
        $visibleFrom = fn (Testable $list): ?string => $list->instance()->getTable()->getColumn($column)->getVisibleFrom();

        $this->assertNull($visibleFrom(Livewire::test($scenario['list'])->assertTableColumnVisible($column)));
        $this->assertNull($visibleFrom($this->listWithTrashed($scenario, $key)->assertTableColumnVisible($column)));
        $this->assertSame('md', $visibleFrom(Livewire::test($scenario['list'])->filterTable('trashed', false)->assertTableColumnVisible($column)));
    }

    #[DataProvider('resources')]
    public function test_trashed_rows_offer_view_instead_of_edit(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $trashed = $this->trashed($scenario['free'], $superAdmin);
        $active = $scenario['free']();
        $editAction = match ($key) {
            'document_request' => 'open',
            default => 'edit',
        };

        $list = $this->listWithTrashed($scenario, $key)
            ->assertTableActionHidden($editAction, $trashed)->assertTableActionVisible($editAction, $active)
            ->assertTableActionVisible('view', $trashed)
            ->assertTableActionHasIcon('view', 'heroicon-o-eye', $trashed)
            ->assertTableActionHasLabel('view', "Lihat {$scenario['noun']}", $trashed)
            ->assertTableActionHasUrl('view', $scenario['resource']::getUrl($scenario['page'], ['record' => $trashed]), $trashed);
        if ($key !== 'document') {
            $list->assertTableActionHidden('view', $active);
        }
    }

    #[DataProvider('resources')]
    public function test_trashed_record_page_shows_notice_and_returns_to_the_trash_view(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $superAdmin->update(['name' => 'Admin Pusat']);
        $trashed = $this->trashed($scenario['free'], $superAdmin);
        $notice = 'Data ini ada di tempat sampah — dihapus 05/11/2026 16:00 WIB oleh Admin Pusat. Pulihkan untuk mengubahnya.';
        $trashIndex = $scenario['resource']::getUrl('index', ['filters' => ['trashed' => ['value' => '0']]]);

        $page = Livewire::test($scenario['detail'], ['record' => $trashed->getRouteKey()])
            ->assertSee($notice)->assertActionHasUrl('back', $trashIndex);
        if ($key !== 'document') {
            $title = $key === 'document_request' ? $trashed->title : $trashed->name;
            $this->assertSame("Detail {$title}", (string) $page->instance()->getTitle());
            $this->assertSame('Detail', $page->instance()->getBreadcrumb());
        }

        // Setelah dipulihkan: tetap di halaman yang kini bisa diubah, dengan judul biasa.
        $page->callAction('restore')->assertHasNoActionErrors()->assertNoRedirect()
            ->assertDontSee($notice)->assertActionHasUrl('back', $scenario['resource']::getUrl('index'));
        if ($key !== 'document') {
            $this->assertStringStartsNotWith('Detail', (string) $page->instance()->getTitle());
        }
        // Pengajuan yang sudah diajukan memang hanya-baca bagi selain pengaju, jadi hanya tiga menu ini yang diperiksa.
        if (in_array($key, ['company', 'document_type', 'user'], true)) {
            $page->assertFormFieldEnabled($scenario['field']);
        }

        $toPurge = $this->trashed($scenario['free'], $superAdmin);
        Livewire::test($scenario['detail'], ['record' => $toPurge->getRouteKey()])
            ->callAction('forceDelete')->assertHasNoActionErrors()->assertRedirect($trashIndex);
        $this->assertModelMissing($toPurge);
    }

    #[DataProvider('resources')]
    public function test_empty_trash_view_says_the_trash_is_empty(string $key): void
    {
        $scenario = $this->scenario($key);
        $this->signInAsSuperAdmin();
        $scenario['free']();

        Livewire::test($scenario['list'])->filterTable('trashed', false)
            ->assertSee('Tempat sampah kosong')->assertSee("Tidak ada {$scenario['noun']} di tempat sampah.");
        Livewire::test($scenario['list'])->assertDontSee('Tempat sampah kosong');

        // Tempat sampah berisi, tetapi pencarian tidak cocok: keadaan kosong biasa, bukan "Tempat sampah kosong".
        $this->trashed($scenario['free'], auth()->user());
        Livewire::test($scenario['list'])->filterTable('trashed', false)->searchTable('zzz-tidak-ada-yang-cocok')
            ->assertCountTableRecords(0)
            ->assertDontSee('Tempat sampah kosong')->assertDontSee("Tidak ada {$scenario['noun']} di tempat sampah.");
    }

    /** Hapus (bisa dipulihkan) abu-abu, Pulihkan abu-abu, Hapus permanen merah dengan ikon berbeda; tombol modal sesuai tingkat bahayanya. */
    #[DataProvider('resources')]
    public function test_trash_actions_follow_one_color_icon_and_wording_convention(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $active = $scenario['free']();
        $trashed = $this->trashed($scenario['free'], $superAdmin);
        $noun = $scenario['noun'];

        $this->listWithTrashed($scenario, $key)
            ->assertTableActionHasColor('delete', 'gray', $active)->assertTableActionHasIcon('delete', 'heroicon-o-trash', $active)
            ->assertTableActionHasColor('restore', 'gray', $trashed)->assertTableActionHasIcon('restore', 'heroicon-o-arrow-uturn-left', $trashed)
            ->assertTableActionHasColor('forceDelete', 'danger', $trashed)->assertTableActionHasIcon('forceDelete', 'heroicon-o-x-circle', $trashed);

        $expectations = [
            'delete' => [$active, "Hapus {$noun}", 'Ya, hapus', 'danger'],
            'restore' => [$trashed, "Pulihkan {$noun}", 'Ya, pulihkan', 'primary'],
            'forceDelete' => [$trashed, "Hapus permanen {$noun}", 'Ya, hapus permanen', 'danger'],
        ];
        foreach ($expectations as $name => [$record, $tooltip, $submitLabel, $submitColor]) {
            $action = $this->listWithTrashed($scenario, $key)->mountTableAction($name, $record)->instance()->getMountedAction();
            $this->assertSame($tooltip, $action->getTooltip(), $name);
            $this->assertSame([$submitLabel, $submitColor], [$action->getModalSubmitAction()->getLabel(), $action->getModalSubmitAction()->getColor()], $name);
        }

        $bulk = $this->listWithTrashed($scenario, $key)
            ->assertTableBulkActionHasColor('delete', 'gray')->assertTableBulkActionHasIcon('delete', 'heroicon-o-trash')
            ->assertTableBulkActionHasColor('restore', 'gray')->assertTableBulkActionHasIcon('restore', 'heroicon-o-arrow-uturn-left')
            ->assertTableBulkActionHasColor('forceDelete', 'danger')->assertTableBulkActionHasIcon('forceDelete', 'heroicon-o-x-circle');
        $submit = $bulk->mountTableBulkAction('delete', [$active])->instance()->getMountedAction()->getModalSubmitAction();
        $this->assertSame(['Ya, hapus', 'danger'], [$submit->getLabel(), $submit->getColor()]);
    }

    public function test_restored_user_notification_points_to_the_reactivate_button(): void
    {
        $superAdmin = $this->signInAsSuperAdmin();
        $user = $this->trashed(fn (): User => User::factory()->create(), $superAdmin);

        Livewire::test(ListUsers::class)->filterTable('trashed', false)
            ->mountTableAction('restore', $user)
            ->assertMountedActionModalSee('User kembali dalam keadaan nonaktif. Bila user perlu masuk lagi, klik tombol Aktifkan kembali di halaman user.')
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        $this->assertNotifiedWithBody('User dipulihkan dalam keadaan nonaktif', 'Klik tombol Aktifkan kembali di halaman user.');
        $notification = collect([...session('filament.claimed_notifications', []), ...session('filament.notifications', [])])->firstWhere('title', 'User dipulihkan dalam keadaan nonaktif');
        $this->assertSame([['Buka user', UserResource::getUrl('edit', ['record' => $user])]], array_map(fn (array $action): array => [$action['label'], $action['url']], $notification['actions']));
    }

    public function test_active_status_filter_shows_trashed_users_only_in_the_trash_view(): void
    {
        $superAdmin = $this->signInAsSuperAdmin();
        $active = User::factory()->create();
        $inactive = User::factory()->create(['deactivated_at' => now(), 'deactivated_by' => $superAdmin->id]);
        $trashed = $this->trashed(fn (): User => User::factory()->create(), $superAdmin);

        Livewire::test(ListUsers::class)->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$trashed, $inactive]);
        Livewire::test(ListUsers::class)->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])->assertCanNotSeeTableRecords([$active, $inactive]);
        Livewire::test(ListUsers::class)->filterTable('trashed', true)->filterTable('status', false)
            ->assertCanSeeTableRecords([$inactive, $trashed])->assertCanNotSeeTableRecords([$active]);
    }

    #[DataProvider('resources')]
    public function test_bulk_actions_skip_irrelevant_rows_and_never_report_nothing_as_success(string $key): void
    {
        $scenario = $this->scenario($key);
        $superAdmin = $this->signInAsSuperAdmin();
        $noun = $scenario['noun'];
        $active = [$scenario['free'](), $scenario['free']()];
        $trashed = $this->trashed($scenario['free'], $superAdmin);

        foreach (['delete' => [[$trashed], 'dihapus'], 'restore' => [$active, 'dipulihkan'], 'forceDelete' => [$active, 'dihapus permanen']] as $name => [$records, $verb]) {
            $this->listWithTrashed($scenario, $key)
                ->mountTableBulkAction($name, $records)
                ->assertSet('mountedActions', []);
            $this->assertNotificationStatus("Tidak ada {$noun} di pilihan ini yang bisa {$verb}.", 'warning');
        }

        $this->listWithTrashed($scenario, $key)
            ->mountTableBulkAction('delete', [...$active, $trashed])
            ->assertMountedActionModalSee("Hapus 2 {$noun}? (1 baris lain dilewati.)");
        $this->listWithTrashed($scenario, $key)
            ->mountTableBulkAction('restore', [$active[0], $trashed])
            ->assertMountedActionModalSee("Pulihkan 1 {$noun}? (1 baris lain dilewati.)");

        if ($scenario['blocked'] !== null) {
            $blocked = ($scenario['blocked'])($superAdmin);
            Livewire::test($scenario['list'])->filterTable('trashed', false)->callTableBulkAction('forceDelete', [$blocked]);
            $title = "0 {$noun} dihapus permanen, 1 dilewati: ".$blocked->trashLabel().' ('.app(TrashManager::class)->usage($blocked)->blockMessage().')';
            $this->assertNotificationStatus($title, 'warning');
        }
    }

    private function assertNotificationStatus(string $title, string $status): void
    {
        $notification = collect([...session('filament.claimed_notifications', []), ...session('filament.notifications', [])])->firstWhere('title', $title);
        $this->assertNotNull($notification, "Notifikasi \"{$title}\" tidak dikirim.");
        $this->assertSame($status, $notification['status']);
    }
}
