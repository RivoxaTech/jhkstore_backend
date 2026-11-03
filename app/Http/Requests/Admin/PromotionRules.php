<?php

namespace App\Http\Requests\Admin;

use App\Domain\Promotion\Models\Discount;
use Illuminate\Validation\Rule;

/** The rules discounts and coupons share: type and value, scope with its targets, and the date window. */
trait PromotionRules
{
    /** @return array<string, mixed> */
    protected function commonRules(bool $requireValue): array
    {
        $partial = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $scope = (string) $this->input('scope');
        $table = ['products' => 'products', 'categories' => 'categories', 'sub_categories' => 'sub_categories'][$scope] ?? null;
        $percent = $this->input('type') === 'percent';

        return [
            'type' => [$requireValue ? $partial : 'required_unless:free_delivery,true', 'nullable', Rule::in(['percent', 'fixed'])],
            'value' => [$requireValue ? $partial : 'required_unless:free_delivery,true', 'nullable', 'numeric',
                $percent ? 'min:0.01' : 'gt:0', $percent ? 'max:100' : 'max:1000000000'],
            'scope' => [$partial, Rule::in(Discount::SCOPES)],
            'target_ids' => [$scope !== '' && $scope !== 'store' ? 'required' : 'nullable', 'array', 'max:500'],
            'target_ids.*' => ['integer', $table ? Rule::exists($table, 'id') : 'prohibited'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function commonMessages(): array
    {
        return [
            'type.required' => 'Choose a percentage or a fixed amount.',
            'type.in' => 'Choose a percentage or a fixed amount.',
            'value.required' => 'Enter the value.',
            'value.min' => 'A percentage must be at least 0.01.',
            'value.max' => 'A percentage can be at most 100.',
            'value.gt' => 'The amount must be more than 0.',
            'scope.in' => 'Choose the whole store, products, categories or sub-categories.',
            'target_ids.required' => 'Choose at least one product, category or sub-category.',
            'target_ids.*.exists' => 'One of the chosen items does not exist.',
            'target_ids.*.prohibited' => 'The whole store has no list of items.',
            'ends_at.after' => 'The end must be after the start.',
        ];
    }

    /** Empty dates become null; the whole store has no target list. */
    protected function prepareTargets(): void
    {
        $data = [];
        foreach (['starts_at', 'ends_at'] as $key) {
            if ($this->has($key) && $this->input($key) === '') {
                $data[$key] = null;
            }
        }
        if ($this->input('scope') === 'store') {
            $data['target_ids'] = [];
        }
        $this->merge($data);
    }
}
