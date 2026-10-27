/**
 * DZ COD Order Form → Google Sheets
 *
 * 1. Open your Google Sheet → Extensions → Apps Script, paste this file.
 * 2. Set SECRET to the same value as in WooCommerce → DZ COD Form → Google Sheets.
 * 3. Deploy → New deployment → Web app. Execute as: Me. Who has access: Anyone.
 * 4. Copy the web app URL into the plugin settings and click "Send a test row".
 */
var SECRET = 'change-me';
var SHEET_NAME = 'Orders';
var COLUMNS = ['order_id', 'date', 'name', 'phone', 'wilaya', 'commune', 'address', 'delivery_type', 'products', 'shipping', 'total', 'status', 'duplicate_of', 'tracking', 'phone2', 'note', 'extra'];

function getSheet_() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(SHEET_NAME) || ss.insertSheet(SHEET_NAME);
  // Create or upgrade the header row (new columns are added on the right).
  if (sheet.getLastRow() === 0 || sheet.getLastColumn() < COLUMNS.length) {
    sheet.getRange(1, 1, 1, COLUMNS.length).setValues([COLUMNS]).setFontWeight('bold');
    sheet.setFrozenRows(1);
  }
  return sheet;
}

// Stop customer input such as "=IMPORTXML(...)" from running as a formula.
function safe_(v) {
  if (typeof v === 'string' && /^[=+\-@]/.test(v)) { return "'" + v; }
  return v === undefined || v === null ? '' : v;
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  var data;
  try { data = JSON.parse(e.postData.contents); } catch (err) { return json_({ ok: false, error: 'bad json' }); }
  if (!data || data.secret !== SECRET) { return json_({ ok: false, error: 'forbidden' }); }

  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var sheet = getSheet_();
    if (data.event === 'order' || data.event === 'test') {
      var row = data.row || {};
      sheet.appendRow(COLUMNS.map(function (c) { return safe_(row[c]); }));
      // Keep phone numbers as text so the leading 0 is not lost.
      sheet.getRange(sheet.getLastRow(), COLUMNS.indexOf('phone') + 1).setNumberFormat('@').setValue(String(row.phone || ''));
    } else if (data.event === 'status' || data.event === 'tracking') {
      var col = COLUMNS.indexOf(data.event) + 1;
      var ids = sheet.getRange(1, 1, Math.max(1, sheet.getLastRow()), 1).getValues();
      for (var i = ids.length - 1; i >= 1; i--) {
        if (String(ids[i][0]) === String(data.order_id)) {
          sheet.getRange(i + 1, col).setNumberFormat('@').setValue(String(safe_(data[data.event])));
          break;
        }
      }
    }
    return json_({ ok: true });
  } finally {
    lock.releaseLock();
  }
}
