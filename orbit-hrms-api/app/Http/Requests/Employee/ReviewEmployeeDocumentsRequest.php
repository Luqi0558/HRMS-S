<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewEmployeeDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route permission and EmployeeService scope checks authorize the actor.
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['verified', 'correction_requested'])],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:action,correction_requested'],
        ];
    }
}
