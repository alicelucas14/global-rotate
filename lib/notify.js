/**
 * Optional email alerts when a domain becomes BLOCKED or DOWN.
 *
 * Uses Gmail SMTP via the Nodemailer-free approach is not possible without a
 * dependency, so this uses the Resend HTTP API when configured (no SMTP libs
 * needed, works on serverless). Set:
 *   RESEND_API_KEY
 *   ALERT_FROM   (e.g. "Rotator <alerts@yourdomain.com>")
 *   ALERT_TO     (comma-separated recipients)
 *
 * If RESEND_API_KEY is not set, alerts are silently skipped.
 */

export async function sendAlert(subject, body) {
  const key = process.env.RESEND_API_KEY;
  const from = process.env.ALERT_FROM;
  const to = (process.env.ALERT_TO || '').split(',').map((s) => s.trim()).filter(Boolean);
  if (!key || !from || to.length === 0) return { sent: false, reason: 'not_configured' };

  try {
    const res = await fetch('https://api.resend.com/emails', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${key}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        from,
        to,
        subject,
        text: body
      })
    });
    return { sent: res.ok, status: res.status };
  } catch (err) {
    return { sent: false, error: String(err && err.message || err) };
  }
}
