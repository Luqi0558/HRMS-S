<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class ResolveInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public by design: the token itself is the credential.
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
        ];
    }
}
