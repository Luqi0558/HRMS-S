<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
    h1 { margin: 0; color: #047857; font-size: 22px; }
    h2 { font-size: 12px; margin: 20px 0 8px; }
    .muted { color: #64748b; } .header { border-bottom: 2px solid #059669; padding-bottom: 16px; }
    .right { float: right; text-align: right; } .clear { clear: both; }
    table { border-collapse: collapse; width: 100%; } th, td { border-bottom: 1px solid #dbe4ef; padding: 8px; text-align: left; }
    th { background: #f1f5f9; color: #475569; font-size: 9px; text-transform: uppercase; } .amount { text-align: right; }
    .net { background: #ecfdf5; font-size: 13px; font-weight: bold; } .footer { margin-top: 24px; color: #64748b; font-size: 8px; }
  </style>
</head>
<body>
  <div class="header">
    <div class="right"><strong>PAYSLIP</strong><br><span class="muted">{{ $slip->period?->format('F Y') }}</span></div>
    <h1>{{ $companyName }}</h1><div class="muted">Authoritative salary-slip record</div><div class="clear"></div>
  </div>
  <h2>Employee</h2>
  <table><tr><th>Name</th><td>{{ $slip->employee?->name }}</td><th>Employee code</th><td>{{ $slip->employee?->employee_code ?? '—' }}</td></tr><tr><th>Department</th><td>{{ $slip->employee?->department ?? '—' }}</td><th>Status</th><td>{{ ucfirst($slip->status) }}</td></tr></table>
  <h2>Earnings</h2>
  <table><tr><th>Component</th><th class="amount">Amount</th></tr><tr><td>Base salary</td><td class="amount">{{ number_format((float) $slip->base_salary, 2) }}</td></tr><tr><td>House allowance</td><td class="amount">{{ number_format((float) $slip->house_allowance, 2) }}</td></tr><tr><td>Medical allowance</td><td class="amount">{{ number_format((float) $slip->medical_allowance, 2) }}</td></tr><tr><td>Bonus / adjustment</td><td class="amount">{{ number_format((float) $slip->bonus, 2) }}</td></tr></table>
  <h2>Deductions</h2>
  <table><tr><th>Component</th><th class="amount">Amount</th></tr><tr><td>Tax deduction</td><td class="amount">{{ number_format((float) $slip->tax_deduction, 2) }}</td></tr><tr><td>Attendance deduction</td><td class="amount">{{ number_format((float) $slip->attendance_deduction, 2) }}</td></tr><tr><td>Advance deduction</td><td class="amount">{{ number_format((float) $slip->advance_deduction, 2) }}</td></tr><tr class="net"><td>Net pay</td><td class="amount">{{ number_format((float) $slip->net_pay, 2) }}</td></tr></table>
  <div class="footer">Issued {{ $slip->issued_at?->format('d M Y H:i') ?? '—' }} by {{ $slip->issuer?->name ?? '—' }}@if($slip->approved_at) · Approved {{ $slip->approved_at->format('d M Y H:i') }} by {{ $slip->approver?->name ?? '—' }}@endif@if($slip->paid_at) · Paid {{ $slip->paid_at->format('d M Y') }}@endif<br>This document is rendered by {{ $companyName }} from the stored salary-slip record.</div>
</body>
</html>
