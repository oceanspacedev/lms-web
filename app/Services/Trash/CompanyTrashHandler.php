<?php

namespace App\Services\Trash;

use App\Models\Company;

/** Badan Usaha: dipakai dokumen dan pengajuan lewat `company_id` (spec §4.2). */
class CompanyTrashHandler extends MasterDataTrashHandler
{
    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function foreignKey(): string
    {
        return 'company_id';
    }

    protected function subjectName(): string
    {
        return 'badan usaha';
    }
}
