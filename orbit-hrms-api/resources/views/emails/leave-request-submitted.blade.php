<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f6f8fb;color:#14213d;font-family:Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e1e9f0;border-radius:14px;">
    <tr><td style="padding:28px 30px;">
      <div style="margin-bottom:18px;color:#0f8b76;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Orbit HRMS · Leave management</div>
      <h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#14213d;">A leave request needs review</h1>
      <p style="margin:0 0 22px;color:#64748b;font-size:15px;line-height:1.55;">{{ $requesterName }} submitted a {{ strtolower($leaveType) }} request.</p>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e1e9f0;border-radius:10px;background:#f9fcfd;">
        <tr><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#64748b;font-size:13px;">Employee</td><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $requesterName }}</td></tr>
        <tr><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#64748b;font-size:13px;">Leave type</td><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $leaveType }}</td></tr>
        <tr><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#64748b;font-size:13px;">Dates</td><td style="padding:12px 16px;border-bottom:1px solid #e1e9f0;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $fromDate }} – {{ $toDate }}</td></tr>
        <tr><td style="padding:12px 16px;color:#64748b;font-size:13px;">Duration</td><td style="padding:12px 16px;color:#14213d;font-size:13px;font-weight:700;text-align:right;">{{ $days }} day{{ $days === '1.0' || $days === '1' ? '' : 's' }}</td></tr>
      </table>
      @if (filled($reason))
        <div style="margin-top:18px;padding:14px 16px;border-left:3px solid #0f8b76;background:#f5fbf9;color:#475569;font-size:13px;line-height:1.55;">
          <strong style="display:block;margin-bottom:4px;color:#14213d;">Reason provided</strong>
          {{ $reason }}
        </div>
      @endif
      <p style="margin:24px 0 0;text-align:center;"><a href="{{ $reviewUrl }}" style="display:inline-block;padding:11px 18px;border-radius:8px;background:#0f8b76;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">Review leave requests</a></p>
    </td></tr>
  </table>
</body>
</html>
