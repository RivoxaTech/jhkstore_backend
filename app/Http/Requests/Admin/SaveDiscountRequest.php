<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An automatic discount. `value`: a percentage (0.01 to 100) or a fixed amount (more than 0). `scope`: store | products | categories | sub_categories;
 * `target_ids` are the ids of the products / categories / sub-categories of the scope (required unless the scope is the whole store).
 */
class SaveDiscountRequest extends FormRequest
{
    use PromotionRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->commonRules(requireValue: true) + [
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Enter a name for the discount.'] + $this->commonMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTargets();
        if ($this->has('priority') && $this->input('priority') === null) {
            $this->merge(['priority' => 0]);
        }
    }
}
