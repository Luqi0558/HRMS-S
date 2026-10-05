<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class SetEmployeeBlockedRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Module middleware and EmployeeService enforce authority and scope.
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked' => ['required', 'boolean'],
        ];
    }
}
