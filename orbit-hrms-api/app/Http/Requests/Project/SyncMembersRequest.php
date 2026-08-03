<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class SyncMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The complete set, not a delta — the modal sends everyone who
            // should be on the project after the change.
            'employeeIds' => ['present', 'array', 'max:200'],
            'employeeIds.*' => ['integer', 'exists:employees,id'],
        ];
    }
}
