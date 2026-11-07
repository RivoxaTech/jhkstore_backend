<?php

namespace App\Http\Requests\Admin;

use App\Domain\Wholesale\Models\WholesaleRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /admin/wholesale/{id}: the status and/or the internal note (send only what changes). */
class UpdateWholesaleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(WholesaleRequest::STATUSES)],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
