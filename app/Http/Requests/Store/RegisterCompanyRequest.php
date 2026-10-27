<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\CompanyPayloadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Public "Sell on Dentalkart" form (two steps). Sent as multipart/form-data.
 * The company logo is required; the product catalogue is optional.
 */
class RegisterCompanyRequest extends FormRequest
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
