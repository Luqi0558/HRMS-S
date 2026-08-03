<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'managerId' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            // slug is absent on purpose: it is in URLs the frontend has
            // already routed on, so renaming must not silently move a page.
        ];
    }
}
