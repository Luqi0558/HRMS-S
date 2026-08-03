<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'moduleId' => ['required', 'integer', 'exists:project_modules,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(['todo', 'in_progress', 'review', 'done', 'blocked'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigneeId' => ['nullable', 'integer', 'exists:employees,id'],
            'deadline' => ['nullable', 'date'],
        ];
    }
}
