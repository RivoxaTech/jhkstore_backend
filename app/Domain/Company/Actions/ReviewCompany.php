<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Company\Models\Company;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Admin decisions on a company: approve, reject, suspend. Its brands follow the company status. */
class ReviewCompany
{
    public function approve(Company $company, Admin $admin): Company
    {
        return $this->transition($company, $admin, CompanyStatus::Approved, null, true, 'company.approved');
    }

    public function reject(Company $company, Admin $admin, string $reason): Company
    {
        return $this->transition($company, $admin, CompanyStatus::Rejected, $reason, false, 'company.rejected');
    }

    public function suspend(Company $company, Admin $admin, ?string $reason = null): Company
    {
        return $this->transition($company, $admin, CompanyStatus::Suspended, $reason, false, 'company.suspended');
    }

    private function transition(Company $company, Admin $admin, CompanyStatus $to, ?string $reason, bool $brandsActive, string $action): Company
    {
        return DB::transaction(function () use ($company, $admin, $to, $reason, $brandsActive, $action) {
            $from = $company->status;

            $company->forceFill([
                'status' => $to,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $company->brands()->update(['is_active' => $brandsActive]);

            Audit::log($admin, $action, $company, ['from' => $from->value, 'to' => $to->value, 'reason' => $reason]);

            return $company->fresh(['brands', 'registrationType', 'vendorType', 'originCountry', 'reviewer']);
        });
    }
}
