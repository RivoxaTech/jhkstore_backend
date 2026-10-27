<?php

namespace App\Domain\Company\Rules;

use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Company\Models\Company;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A GST/VAT number may be registered only once, unless the earlier registration was rejected. */
class UniqueCompanyGst implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreCompanyId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Company::query()
            ->whereRaw('UPPER(vat_gst_number) = ?', [strtoupper(trim((string) $value))])
            ->where('status', '!=', CompanyStatus::Rejected->value)
            ->when($this->ignoreCompanyId, fn ($q) => $q->whereKeyNot($this->ignoreCompanyId))
            ->exists();

        if ($exists) {
            $fail('A company with this VAT/GST number is already registered.');
        }
    }
}
