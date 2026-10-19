/**
 * SiteSage Chat -> Google Sheets lead webhook.
 *
 * Setup (about 3 minutes):
 * 1. Create a Google Sheet. Extensions > Apps Script. Paste this file.
 * 2. Change SHARED_SECRET below to a long random string.
 * 3. Deploy > New deployment > Web app.
 *    Execute as: Me. Who has access: Anyone.
 * 4. Copy the web app URL (https://script.google.com/macros/s/.../exec).
 * 5. In WordPress: SiteSage Chat > Settings > Lead capture, paste the URL
 *    into "Google Sheets webhook URL" and the same secret into "Webhook secret".
 *
 * Every new lead becomes a row in the "Leads" tab. Requests with a wrong
 * secret are ignored.
 */

var SHARED_SECRET = 'change-me-to-a-long-random-string';
var SHEET_NAME = 'Leads';
var HEADERS = ['Received', 'Created (UTC)', 'Name', 'Phone', 'Email', 'Question', 'Page', 'Site'];

function doPost(e) {
  var data;
  try {
    data = JSON.parse(e.postData.contents);
  } catch (err) {
    return json_({ ok: false, error: 'invalid_json' });
  }

  if (!data || data.secret !== SHARED_SECRET) {
    return json_({ ok: false, error: 'forbidden' });
  }

  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var sheet = getSheet_();
    sheet.appendRow([
      new Date(),
      clean_(data.created_at),
      clean_(data.name),
      clean_(data.phone),
      clean_(data.email),
      clean_(data.question),
      clean_(data.page_url),
      clean_(data.site)
    ]);
  } finally {
    lock.releaseLock();
  }

  return json_({ ok: true });
}

/** Simple GET so you can check the deployment in a browser. */
function doGet() {
  return json_({ ok: true, service: 'SiteSage Chat leads webhook' });
}

function getSheet_() {
  var book = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = book.getSheetByName(SHEET_NAME) || book.insertSheet(SHEET_NAME);
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(HEADERS);
    sheet.setFrozenRows(1);
  }
  return sheet;
}

/** Trim, cap length and stop spreadsheet formulas (=, +, -, @). */
function clean_(value) {
  var text = String(value === undefined || value === null ? '' : value).slice(0, 1000).trim();
  return /^[=+\-@]/.test(text) ? "'" + text : text;
}

function json_(payload) {
  return ContentService.createTextOutput(JSON.stringify(payload)).setMimeType(ContentService.MimeType.JSON);
}
