/**
 * Alerts when a domain becomes BLOCKED or DOWN.
 *
 * Supports two channels — configure either or both:
 *
 * ── Telegram ──────────────────────────────────────────────────────────────
 *   TELEGRAM_BOT_TOKEN   Bot token from @BotFather
 *   TELEGRAM_CHAT_ID     Target chat / group / channel ID
 *
 * ── Email via Resend ──────────────────────────────────────────────────────
 *   RESEND_API_KEY
 *   ALERT_FROM   (e.g. "Rotator <alerts@yourdomain.com>")
 *   ALERT_TO     (comma-separated recipients)
 *
 * If neither channel is configured, alerts are silently skipped.
 */

/** Send a Telegram message. Returns { sent, ... }. */
export async function sendTelegram(text) {
  const token = process.env.TELEGRAM_BOT_TOKEN;
  const chatId = process.env.TELEGRAM_CHAT_ID;
  if (!token || !chatId) return { sent: false, reason: 'not_configured' };

  try {
    const res = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: chatId,
        text,
        parse_mode: 'HTML'
      })
    });
    return { sent: res.ok, status: res.status };
  } catch (err) {
    return { sent: false, error: String(err && err.message || err) };
  }
}

/** Send an email alert via Resend. Returns { sent, ... }. */
export async function sendEmail(subject, body) {
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
      body: JSON.stringify({ from, to, subject, text: body })
    });
    return { sent: res.ok, status: res.status };
  } catch (err) {
    return { sent: false, error: String(err && err.message || err) };
  }
}

/**
 * Unified alert — fires Telegram and/or email depending on what's configured.
 * subject is used as the email subject and as the first line of the Telegram message.
 */
export async function sendAlert(subject, body) {
  const tgText = `<b>${subject}</b>\n\n${body}`;
  const [tg, email] = await Promise.all([
    sendTelegram(tgText),
    sendEmail(subject, body)
  ]);
  return { telegram: tg, email };
}

