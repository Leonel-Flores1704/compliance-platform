<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'between:3,100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:'.implode(',', Role::values())],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()],
            'must_change_password' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.between' => 'Name must be between 3 and 100 characters.',
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'A user with this email already exists.',
            'role.required' => 'Select a role.',
            'role.in' => 'Select a role.',
            'password.required' => 'Password must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.',
            'password.min' => 'Password must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.',
            'password.mixed' => 'Password must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.',
            'password.numbers' => 'Password must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.',
        ];
    }
}
