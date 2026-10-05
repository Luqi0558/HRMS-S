<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f6f8fb;color:#14213d;font-family:Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e1e9f0;border-radius:14px;">
    <tr><td style="padding:28px 30px;">
      <div style="margin-bottom:18px;color:#0f8b76;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Orbit HRMS &middot; Welcome</div>
      <h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#14213d;">Welcome to Orbit HRMS, {{ $employeeName }}</h1>
      <p style="margin:0 0 22px;color:#64748b;font-size:15px;line-height:1.55;">Your employee profile has been created. Use the secure link below to set your password and complete your onboarding details.</p>
      <p style="margin:0 0 22px;text-align:center;"><a href="{{ $inviteUrl }}" style="display:inline-block;padding:11px 18px;border-radius:8px;background:#0f8b76;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">Complete onboarding</a></p>
      <div style="padding:14px 16px;border-left:3px solid #0f8b76;background:#f5fbf9;color:#475569;font-size:13px;line-height:1.55;">For your security, this invitation link expires on <strong style="color:#14213d;">{{ $expiresAt }}</strong>. If it expires, ask HR to resend it.</div>
    </td></tr>
  </table>
</body>
</html>
