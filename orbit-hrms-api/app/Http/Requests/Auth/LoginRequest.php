<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // No min length here on purpose: enforcing password rules at login
            // tells an attacker what the rules are, and a legacy password that
            // no longer meets them must still be able to sign in.
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function deviceName(): string
    {
        return $this->input('device_name', 'spa');
    }
}
