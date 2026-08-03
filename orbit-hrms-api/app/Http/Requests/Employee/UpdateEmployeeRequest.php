<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware gates the module; EmployeeService gates the record.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'joiningDate' => ['sometimes', 'nullable', 'date'],
            'exitDate' => ['sometimes', 'nullable', 'date', 'after_or_equal:joiningDate'],
            'employmentType' => [
                'sometimes',
                Rule::in(['Full-time', 'Part-time', 'Contract', 'Internship']),
            ],
            'probationStatus' => ['sometimes', Rule::in(['Probation', 'Confirmed'])],
            // email and status are deliberately absent: changing an email
            // breaks the login it is tied to, and status transitions
            // (block/exit) are their own audited actions, not a field edit.
        ];
    }

    /** camelCase in, snake_case out — the frontend keys stay unchanged. */
    public function toAttributes(): array
    {
        $map = [
            'name' => 'name',
            'designation' => 'designation',
            'department' => 'department',
            'birthday' => 'birthday',
            'joiningDate' => 'joining_date',
            'exitDate' => 'exit_date',
            'employmentType' => 'employment_type',
            'probationStatus' => 'probation_status',
        ];

        $out = [];
        foreach ($this->validated() as $key => $value) {
            if (isset($map[$key])) {
                $out[$map[$key]] = $value;
            }
        }

        return $out;
    }
}
