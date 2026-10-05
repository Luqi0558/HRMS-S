<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalarySlipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = $this->resource->totals();

        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'payrollRunId' => $this->payroll_run_id,
            'requestId' => $this->salary_slip_request_id,
            'period' => $this->period?->toDateString(),
            // What SalarySlips.vue labels a slip with.
            'periodLabel' => $this->period?->format('F Y'),

            // Strings for the same reason as claim amounts: JSON numbers are
            // doubles, and a payable figure should not round in transit.
            'baseSalary' => (string) $this->base_salary,
            'houseAllowance' => (string) $this->house_allowance,
            'medicalAllowance' => (string) $this->medical_allowance,
            'bonus' => (string) $this->bonus,
            'taxDeduction' => (string) $this->tax_deduction,
            'attendanceDeduction' => (string) $this->attendance_deduction,
            'attendanceSummary' => $this->attendance_summary,
            'advanceDeduction' => (string) $this->advance_deduction,

            'allowances' => (string) $totals['allowances'],
            'deductions' => (string) $totals['deductions'],
            'netPay' => (string) $this->net_pay,

            'notes' => $this->notes,
            'status' => $this->status,
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),

            // Null until the slip is settled; the screen shows the method and
            // reference on a paid row.
            'payment' => $this->when($this->status === 'paid', fn () => [
                'method' => $this->payment_method,
                'reference' => $this->payment_reference,
                'paidDate' => $this->paid_on?->toDateString(),
                'notes' => $this->payment_notes,
            ]),

            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'department' => $this->whenLoaded('employee', fn () => $this->employee?->department),
            'issuedByName' => $this->whenLoaded('issuer', fn () => $this->issuer?->name),
            'approvedByName' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'paidByName' => $this->whenLoaded('payer', fn () => $this->payer?->name),
        ];
    }
}
