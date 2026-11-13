<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'between:3,100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', 'string', 'in:'.implode(',', Role::values())],
            'password' => ['nullable', 'string', Password::min(12)->mixedCase()->numbers()],
            'must_change_password' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return (new StoreUserRequest)->messages();
    }
}
