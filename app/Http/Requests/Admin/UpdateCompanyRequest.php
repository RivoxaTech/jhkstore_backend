<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\CompanyPayloadRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    use CompanyPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $company = $this->route('company');

        return [
            ...$this->companyRules(catalogueRequired: false, ignoreCompanyId: $company->id, partial: true),
            ...$this->logoRules(required: false),
            ...$this->catalogueRules(required: false),
        ];
    }
}
