<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The minimum needed to render a person in a list or avatar. Deliberately not
 * EmployeeResource: nesting the full employment record inside every project
 * and ticket would ship birthdays and joining dates to anyone who can see a
 * board.
 */
class EmployeeSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'designation' => $this->designation,
        ];
    }
}
