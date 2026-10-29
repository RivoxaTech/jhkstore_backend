<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** Customer sign-up (the OTP / email verification steps come later). */
class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('mobile')) {
            $this->merge(['mobile' => preg_replace('/\D/', '', (string) $this->input('mobile'))]);
        }
        if ($this->filled('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'mobile' => ['required', 'string', 'regex:/^[0-9]{7,15}$/', 'unique:users,mobile'],
            'password' => ['required', 'string', 'min:8', 'max:190', 'confirmed'],
            'professional_type' => ['nullable', 'string', 'max:30'],
            'cart_token' => ['nullable', 'string', 'size:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Enter your full name.',
            'email.required' => 'Enter your email address.',
            'email.unique' => 'An account with this email already exists. Please log in.',
            'mobile.required' => 'Enter your mobile number.',
            'mobile.regex' => 'Enter a valid mobile number (7 to 15 digits).',
            'mobile.unique' => 'An account with this mobile number already exists. Please log in.',
            'password.min' => 'The password must be at least 8 characters.',
            'password.confirmed' => 'The passwords do not match.',
        ];
    }
}
