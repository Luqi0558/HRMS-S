<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f6f8fb;color:#14213d;font-family:Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e1e9f0;border-radius:14px;">
    <tr><td style="padding:28px 30px;">
      <div style="margin-bottom:18px;color:#0f8b76;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Orbit HRMS · Medical claims</div>
      <h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#14213d;">A medical claim needs review</h1>
      <p style="margin:0 0 22px;color:#64748b;font-size:15px;line-height:1.55;">{{ $requesterName }} submitted a {{ strtolower($claimType) }} for <strong style="color:#14213d;">{{ $claimAmount }}</strong>.</p>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e1e9f0;border-radius:10px;background:#f9fcfd;">
        <tr><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#64748b;font-size:13px;">Employee</td><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $requesterName }}</td></tr>
        <tr><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#64748b;font-size:13px;">Claim type</td><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $claimType }}</td></tr>
        <tr><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#64748b;font-size:13px;">Incurred on</td><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $incurredOn }}</td></tr>
        <tr><td style="padding:12px 16px;color:#64748b;font-size:13px;">Receipt</td><td style="padding:12px 16px;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $hasReceipt ? 'Attached in HRMS' : 'Not attached' }}</td></tr>
      </table>
      @if (filled($description))
        <div style="margin-top:18px;padding:14px 16px;border-left:3px solid #0f8b76;background:#f5fbf9;color:#475569;font-size:13px;line-height:1.55;">
          <strong style="display:block;margin-bottom:4px;color:#14213d;">Claim details</strong>
          {{ $description }}
        </div>
      @endif
      <h2 style="margin:24px 0 10px;font-size:15px;color:#14213d;">Annual medical allowance</h2>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #d8eee8;border-radius:10px;background:#f5fbf9;">
        <tr><td style="padding:11px 16px;border-bottom:1px solid #d8eee8;color:#64748b;font-size:13px;">Annual allowance</td><td style="padding:11px 16px;border-bottom:1px solid #d8eee8;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $annualAllowance }}</td></tr>
        <tr><td style="padding:11px 16px;border-bottom:1px solid #d8eee8;color:#64748b;font-size:13px;">Approved / paid this year</td><td style="padding:11px 16px;border-bottom:1px solid #d8eee8;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $usedThisYear }}</td></tr>
        <tr><td style="padding:11px 16px;border-bottom:1px solid #d8eee8;color:#64748b;font-size:13px;">Currently remaining</td><td style="padding:11px 16px;border-bottom:1px solid #d8eee8;color:#0f8b76;font-size:13px;font-weight:700;text-align:right;">{{ $remainingBeforeClaim }}</td></tr>
        <tr><td style="padding:11px 16px;color:#64748b;font-size:13px;">Remaining if fully approved</td><td style="padding:11px 16px;color:#0f8b76;font-size:13px;font-weight:700;text-align:right;">{{ $remainingIfApproved }}</td></tr>
      </table>
      <p style="margin:24px 0 0;text-align:center;"><a href="{{ $reviewUrl }}" style="display:inline-block;padding:11px 18px;border-radius:8px;background:#0f8b76;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">Review medical claims</a></p>
    </td></tr>
  </table>
</body>
</html>
