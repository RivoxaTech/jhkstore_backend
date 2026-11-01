<?php

namespace App\Http\Requests\Admin;

use App\Domain\Auth\AdminAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Create (POST /users) or update (admin user in the URL; send only what changes) an admin user. */
class SaveAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $admin = $this->route('adminUser');
        $req = $admin ? 'sometimes' : 'required';

        return [
            'username' => $admin ? ['prohibited'] : ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('admins', 'username')],
            'name' => [$req, 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('admins', 'email')->ignore($admin?->id)],
            'password' => [$req === 'required' ? 'required' : 'sometimes', 'nullable', 'string', Password::min(10)->letters()->numbers(), 'max:128'],
            'role' => [$req, Rule::in(array_keys(AdminAccess::ROLES))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.prohibited' => 'The username cannot be changed.',
            'username.regex' => 'Use letters, numbers, dots, dashes and underscores only.',
            'role.in' => 'Choose one of the roles.',
        ];
    }
}
