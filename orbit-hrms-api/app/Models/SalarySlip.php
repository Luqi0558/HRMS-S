<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued salary slip.
 *
 * Every figure is a snapshot taken when the slip was issued, not a reference
 * to the current salary — a raise next month must not change what June's slip
 * says was paid. Nothing here is updated after issue.
 */
class SalarySlip extends Model
{
    protected $fillable = [
        'employee_id', 'payroll_run_id', 'salary_slip_request_id', 'period', 'status',
        'base_salary', 'house_allowance', 'medical_allowance', 'bonus',
        'tax_deduction', 'attendance_deduction', 'advance_deduction',
        'net_pay', 'notes', 'attendance_summary', 'issued_by', 'issued_at',
        'approved_by', 'approved_at',
        'payment_method', 'payment_reference', 'paid_on', 'payment_notes',
        'paid_by', 'paid_at',
    ];

    protected $casts = [
        'period' => 'date',
        'issued_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_on' => 'date',
        'paid_at' => 'datetime',
        'base_salary' => 'encrypted',
        'house_allowance' => 'encrypted',
        'medical_allowance' => 'encrypted',
        'bonus' => 'encrypted',
        'tax_deduction' => 'encrypted',
        'attendance_deduction' => 'encrypted',
        'advance_deduction' => 'encrypted',
        'net_pay' => 'encrypted',
        'notes' => 'encrypted',
        'attendance_summary' => 'encrypted:array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SalarySlipRequest::class, 'salary_slip_request_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by');
    }

    /** Allowances and deductions as the slip presents them. */
    public function totals(): array
    {
        $allowances = (float) $this->house_allowance
            + (float) $this->medical_allowance
            + (float) $this->bonus;

        $deductions = (float) $this->tax_deduction
            + (float) $this->attendance_deduction
            + (float) $this->advance_deduction;

        return ['allowances' => $allowances, 'deductions' => $deductions];
    }
}
