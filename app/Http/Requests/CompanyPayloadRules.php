<?php

namespace App\Http\Requests;

use App\Domain\Company\Rules\UniqueCompanyGst;
use Illuminate\Validation\Rule;

/** Validation shared by the public seller form and the admin "create company" form. */
trait CompanyPayloadRules
{
    protected function companyRules(bool $catalogueRequired, ?int $ignoreCompanyId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:190'],
            'registration_type_id' => [$req, 'integer', Rule::exists('company_registration_types', 'id')->where('is_active', true)],
            'email' => [$req, 'email:rfc', 'max:190'],
            'origin_country_id' => [$req, 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'vendor_type_id' => [$req, 'integer', Rule::exists('vendor_types', 'id')->where('is_active', true)],
            'contact_number' => [$req, 'string', 'max:25', 'regex:/^[0-9+\-\s()]{7,25}$/'],
            'vat_gst_number' => [$req, 'string', 'max:30', new UniqueCompanyGst($ignoreCompanyId)],

            'contact_name' => [$req, 'string', 'max:120'],
            'contact_mobile' => [$req, 'string', 'max:25', 'regex:/^[0-9+\-\s()]{7,25}$/'],
            'contact_email' => [$req, 'email:rfc', 'max:190'],
            'contact_whatsapp' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]{7,25}$/'],
            'address' => [$req, 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'city' => [$req, 'string', 'max:100'],
            'zip_code' => [$req, 'string', 'max:12'],
            'promotion_support' => [$req, 'string', 'min:200', 'max:500'],
        ];
    }

    protected function brandRules(bool $required = true): array
    {
        return [
            'brands' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'brands.*.name' => ['required', 'string', 'max:160', 'distinct:ignore_case'],
            'brands.*.origin_country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
        ];
    }

    protected function catalogueRules(bool $required): array
    {
        return [
            'catalogue' => $required ? ['required', 'array', 'min:1', 'max:10'] : ['nullable', 'array', 'max:10'],
            'catalogue.*' => ['file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:10240'],
        ];
    }

    /** Company logo: shown everywhere and used as the default logo of brands that have none of their own. */
    protected function logoRules(bool $required): array
    {
        return [
            'logo' => [$required ? 'required' : 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ];
    }

    /** Only the company table columns from validated input. */
    protected function companyData(): array
    {
        return collect($this->validated())->except(['brands', 'catalogue', 'logo'])->all();
    }
}
