<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The can.module middleware on the route handles authorization.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Unique among LIVE rows only. employees.email is indexed rather
            // than unique at the database level, because a unique constraint
            // also matches soft-deleted rows and would block re-hiring someone
            // previously removed. That makes this rule the actual enforcement.
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('employees', 'email')->whereNull('deleted_at'),
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],

            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'joiningDate' => ['nullable', 'date'],
            'employmentType' => [
                'nullable',
                Rule::in(['Full-time', 'Part-time', 'Contract', 'Internship']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Someone with this email address already exists.',
        ];
    }
}
