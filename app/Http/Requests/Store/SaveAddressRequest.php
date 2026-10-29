<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\AddressPayloadRules;
use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) or update (PUT/PATCH sends only the changed fields) an address of the address book. */
class SaveAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(AddressPayloadRules::normalise($this->all()));
    }

    public function rules(): array
    {
        return AddressPayloadRules::rules('', partial: $this->isMethod('PUT') || $this->isMethod('PATCH')) + ['is_default' => ['sometimes', 'boolean']];
    }

    public function messages(): array
    {
        return AddressPayloadRules::messages();
    }
}
