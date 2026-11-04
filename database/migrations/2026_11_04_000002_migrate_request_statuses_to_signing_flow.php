<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Pindahkan data tahap persetujuan lama ke alur draf, tanda tangan, dan serah terima fisik.
     * Aman dijalankan ulang.
     */
    public function up(): void
    {
        DB::table('document_requests')->where('status', 'review')->update(['status' => 'submitted']);
        DB::table('document_requests')->where('status', 'approved')->update(['status' => 'signing']);

        DB::table('reminder_templates')->whereNotNull('request_templates')->get(['id', 'request_templates'])->each(function (object $row): void {
            $templates = json_decode($row->request_templates, true);
            if (! is_array($templates)) {
                return;
            }
            $cleaned = array_diff_key($templates, array_flip(['review', 'approved']));
            if ($cleaned !== $templates) {
                DB::table('reminder_templates')->where('id', $row->id)->update(['request_templates' => $cleaned === [] ? null : json_encode($cleaned)]);
            }
        });

        $escalation = Permission::findOrCreate('ReceiveEscalation:DocumentRequest', 'web');
        $viewAll = Permission::findOrCreate('ViewAll:DocumentRequest', 'web');

        if (Permission::where('name', 'Approve:DocumentRequest')->where('guard_name', 'web')->exists()) {
            User::permission('Approve:DocumentRequest')->get()->each(fn (User $user) => $user->givePermissionTo($escalation, $viewAll));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Hanya status yang dibalik; pemberian izin dibiarkan. */
    public function down(): void
    {
        DB::table('document_requests')->where('status', 'signing')->whereNull('draft_path')->update(['status' => 'approved']);
    }
};
