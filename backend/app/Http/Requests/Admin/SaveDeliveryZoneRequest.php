<?php

namespace App\Http\Requests\Admin;

use App\Domain\Delivery\Models\DeliveryZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A delivery zone: where it applies (city, area or postcode values), the fee and the optional free-above amount, minimum order and ETA text. */
class SaveDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$update, 'string', 'max:120'],
            'match_type' => [$update, Rule::in(DeliveryZone::MATCH_TYPES)],
            'match_values' => [$update, 'array', 'min:1', 'max:200'],
            'match_values.*' => ['nullable', 'string', 'max:60'],   // blank entries are dropped when saving
            'fee' => [$update, 'numeric', 'min:0', 'max:1000000'],
            'free_above' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'eta_text' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the zone.',
            'match_type.required' => 'Choose what the zone matches: city, area or postcode.',
            'match_type.in' => 'Choose city, area or postcode.',
            'match_values.required' => 'Enter at least one value the zone matches.',
            'match_values.min' => 'Enter at least one value the zone matches.',
            'fee.required' => 'Enter the delivery fee of the zone (0 for free).',
        ];
    }
}
