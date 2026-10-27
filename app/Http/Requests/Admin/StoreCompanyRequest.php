<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\CompanyPayloadRules;
use Illuminate\Foundation\Http\FormRequest;

/** Admin "Create new company": same fields as the public form (logo required, catalogue optional). */
class StoreCompanyRequest extends FormRequest
{
    use CompanyPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->companyRules(catalogueRequired: false),
            ...$this->brandRules(),
            ...$this->logoRules(required: true),
            ...$this->catalogueRules(required: false),
        ];
    }
}
