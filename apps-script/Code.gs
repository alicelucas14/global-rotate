/**
 * Ultra Rotator — Google Sheet manager (Apps Script)
 *
 * Evolved from your original UltraDomainVercel helper. This version lets you
 * manage the domain pool from a Google Sheet and PUSH it to the live Vercel
 * rotator via the DOMAINS env / a webhook, so non-technical teammates can add
 * or block mirrors without touching code.
 *
 * Sheet tab: "UltraDomainVercel"
 * Columns:   label | vercel_url | status | since_utc | notes
 *
 * Setup:
 *   1. Extensions -> Apps Script, paste this file.
 *   2. Project Settings -> Script properties, add:
 *        ROTATOR_STATUS_URL = https://your-project.vercel.app/api/status  (optional, for pull)
 *   3. Reload the sheet -> use the "Ultra Rotator" menu.
 */

const SHEET_NAME = 'UltraDomainVercel';
const HEADERS = ['label', 'vercel_url', 'status', 'since_utc', 'notes'];

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Ultra Rotator')
    .addItem('Add ACTIVE URL…', 'uiAddActive')
    .addItem('Mark BLOCKED…', 'uiMarkBlocked')
    .addSeparator()
    .addItem('Copy DOMAINS env value', 'uiCopyDomainsEnv')
    .addItem('Pull live status from server', 'uiPullStatus')
    .addToUi();
}

/** Timestamp when status is edited manually. */
function onEdit(e) {
  try {
    const s = e.range.getSheet();
    if (s.getName() !== SHEET_NAME) return;
    const row = e.range.getRow();
    const col = e.range.getColumn();
    if (row === 1) return;

    const headers = s.getRange(1, 1, 1, s.getLastColumn()).getValues()[0];
    const colStatus = headers.indexOf('status') + 1;
    const colSince = headers.indexOf('since_utc') + 1;

    if (col === colStatus) {
      const val = String(e.range.getValue()).toUpperCase().trim();
      if (['ACTIVE', 'BLOCKED', 'DOWN', 'NEED_DEPLOY'].includes(val)) {
        s.getRange(row, colSince).setValue(new Date().toISOString());
      }
    }
  } catch (err) {
    /* ignore */
  }
}

/* ===== UI helpers ===== */
function uiAddActive() {
  const ui = SpreadsheetApp.getUi();
  const label = ui.prompt('Add ACTIVE URL', 'Enter label (e.g., primary, mirror1):', ui.ButtonSet.OK_CANCEL)
    .getResponseText().trim().toLowerCase();
  if (!label) return;
  const url = ui.prompt('Add ACTIVE URL', 'Paste the full https URL:', ui.ButtonSet.OK_CANCEL)
    .getResponseText().trim();
  if (!/^https?:\/\/.+/i.test(url)) {
    ui.alert('Must be a full https:// URL.');
    return;
  }
  appendRow(label, url, 'ACTIVE', 'added via menu');
  ui.alert('Added.');
}

function uiMarkBlocked() {
  const ui = SpreadsheetApp.getUi();
  const url = ui.prompt('Mark BLOCKED', 'Paste the exact URL to mark BLOCKED:', ui.ButtonSet.OK_CANCEL)
    .getResponseText().trim();
  if (!url) return;
  const sh = getSheet();
  const rows = sh.getDataRange().getValues();
  const header = rows.shift();
  const idxUrl = header.indexOf('vercel_url');
  const idxStat = header.indexOf('status');
  const idxSince = header.indexOf('since_utc');
  let changed = false;
  rows.forEach((r, i) => {
    if (String(r[idxUrl]).trim().replace(/\/+$/, '') === url.replace(/\/+$/, '')) {
      sh.getRange(i + 2, idxStat + 1).setValue('BLOCKED');
      sh.getRange(i + 2, idxSince + 1).setValue(new Date().toISOString());
      changed = true;
    }
  });
  ui.alert(changed ? 'Marked BLOCKED.' : 'URL not found.');
}

/** Build the DOMAINS env value (ACTIVE first, then others) for Vercel. */
function uiCopyDomainsEnv() {
  const sh = getSheet();
  const rows = sh.getDataRange().getValues();
  const header = rows.shift();
  const idxUrl = header.indexOf('vercel_url');
  const idxStat = header.indexOf('status');
  const active = [];
  const rest = [];
  rows.forEach((r) => {
    const url = String(r[idxUrl]).trim().replace(/\/+$/, '');
    if (!url) return;
    const stat = String(r[idxStat]).toUpperCase().trim();
    if (stat === 'BLOCKED' || stat === 'DOWN') return; // exclude dead ones
    (stat === 'ACTIVE' ? active : rest).push(url);
  });
  const value = active.concat(rest).join(',');
  SpreadsheetApp.getUi().alert(
    'Set this as the DOMAINS env var on Vercel:\n\n' + (value || '(no usable domains)')
  );
}

/** Pull live status from the deployed rotator and write it back to the sheet. */
function uiPullStatus() {
  const ui = SpreadsheetApp.getUi();
  const url = PropertiesService.getScriptProperties().getProperty('ROTATOR_STATUS_URL');
  if (!url) {
    ui.alert('Set ROTATOR_STATUS_URL in Script properties first.');
    return;
  }
  try {
    const res = UrlFetchApp.fetch(url, { muteHttpExceptions: true });
    const state = JSON.parse(res.getContentText());
    const sh = getSheet();
    ensureHeaders(sh);
    (state.results || []).forEach((r) => {
      upsertRow(sh, r.url, r.status);
    });
    ui.alert('Pulled ' + (state.results ? state.results.length : 0) + ' rows. Active: ' + (state.active || '-'));
  } catch (err) {
    ui.alert('Failed: ' + err);
  }
}

/* ===== Core ===== */
function getSheet() {
  const sh = SpreadsheetApp.getActive().getSheetByName(SHEET_NAME);
  if (!sh) throw new Error('Sheet tab not found: ' + SHEET_NAME);
  return sh;
}

function ensureHeaders(sh) {
  const header = sh.getRange(1, 1, 1, HEADERS.length).getValues()[0];
  if (HEADERS.join('|') !== header.join('|')) {
    sh.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);
  }
}

function appendRow(label, url, status, notes) {
  const sh = getSheet();
  ensureHeaders(sh);
  sh.appendRow([label, url.replace(/\/+$/, ''), status.toUpperCase(), new Date().toISOString(), notes || '']);
}

/** Insert or update a row by URL. */
function upsertRow(sh, url, status) {
  const clean = String(url).trim().replace(/\/+$/, '');
  const rows = sh.getDataRange().getValues();
  const header = rows.shift();
  const idxUrl = header.indexOf('vercel_url');
  const idxStat = header.indexOf('status');
  const idxSince = header.indexOf('since_utc');
  for (let i = 0; i < rows.length; i++) {
    if (String(rows[i][idxUrl]).trim().replace(/\/+$/, '') === clean) {
      const prev = String(rows[i][idxStat]).toUpperCase().trim();
      if (prev !== String(status).toUpperCase().trim()) {
        sh.getRange(i + 2, idxStat + 1).setValue(String(status).toUpperCase());
        sh.getRange(i + 2, idxSince + 1).setValue(new Date().toISOString());
      }
      return;
    }
  }
  sh.appendRow(['auto', clean, String(status).toUpperCase(), new Date().toISOString(), 'from server']);
}
