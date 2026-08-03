<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['todo', 'in_progress', 'review', 'done', 'blocked'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigneeId' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            // moduleId is absent: moving a ticket between modules also moves
            // it between projects, which is a re-scope rather than an edit.
        ];
    }
}
