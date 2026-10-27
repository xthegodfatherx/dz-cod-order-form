/* DZ COD Order Form — admin screens. Each block only runs when its markup is on the page. */
(function () {
	'use strict';
	var A = window.DZCOD_ADMIN || {};
	var $ = function (sel, root) { return (root || document).querySelector(sel); };
	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

	function post(fields) {
		var fd = new FormData();
		Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
		return fetch(A.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	// Delivery prices: "apply to all".
	var bulk = $('#dzcod-bulk-apply');
	if (bulk) {
		bulk.addEventListener('click', function () {
			var h = $('#dzcod-bulk-home').value, d = $('#dzcod-bulk-desk').value;
			if (h !== '') { $$('.dzcod-home').forEach(function (i) { i.value = h; }); }
			if (d !== '') { $$('.dzcod-desk').forEach(function (i) { i.value = d; }); }
		});
	}

	// Delivery prices: import from the delivery company or a Google Sheet (chunked).
	var log = $('.dzcod-import-log');
	if (log) {
		var run = function (source, offset, btn) {
			var f = { action: 'dzcod_import_prices', nonce: A.importNonce, source: source, offset: offset };
			if (source === 'sheet') { f.url = $('#dzcod-sheet-url').value; }
			post(f).then(function (res) {
				var d = res.data || {};
				log.textContent = d.message || '';
				log.className = 'dzcod-import-log ' + (d.ok ? 'is-ok' : 'is-error');
				if (d.ok && !d.done) { run(source, d.next, btn); return; }
				btn.disabled = false;
				if (d.ok) { log.textContent += ' ' + A.i18n.reloading; setTimeout(function () { window.location.reload(); }, 1200); }
			}).catch(function () {
				btn.disabled = false;
				log.textContent = A.i18n.failed;
				log.className = 'dzcod-import-log is-error';
			});
		};
		$$('[data-dzcod-import]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!window.confirm(A.i18n.replacePrices)) { return; }
				b.disabled = true;
				log.className = 'dzcod-import-log';
				log.textContent = A.i18n.importing;
				run(b.getAttribute('data-dzcod-import'), 0, b);
			});
		});
	}

	// Delivery company picker: show only the fields of the chosen company.
	var radios = $$('.dzcod-couriers input[type=radio]');
	if (radios.length) {
		var sync = function () {
			var cur = $('.dzcod-couriers input[type=radio]:checked');
			var p = cur ? cur.getAttribute('data-platform') : '', v = cur ? cur.value : '';
			$$('.dzcod-p').forEach(function (r) {
				var show = r.classList.contains('dzcod-p-' + p) || (r.classList.contains('dzcod-p-ecotrack-custom') && v === 'ecotrack');
				r.style.display = show ? '' : 'none';
			});
			$$('.dzcod-courier').forEach(function (l) { l.classList.toggle('is-selected', l.querySelector('input').checked); });
		};
		radios.forEach(function (r) { r.addEventListener('change', sync); });
		sync();
	}

	// Confirmation desk: outcome buttons.
	$$('.dzcod-outcomes button').forEach(function (b) {
		b.addEventListener('click', function () {
			var tr = b.closest('tr'), msg = $('.dzcod-outcome-msg', tr), o = b.getAttribute('data-outcome');
			var buttons = $$('button', tr);
			if (o === 'cancel' && !window.confirm(A.i18n.cancelOrder)) { return; }
			buttons.forEach(function (x) { x.disabled = true; });
			post({ action: 'dzcod_desk', nonce: A.deskNonce, order_id: tr.getAttribute('data-order'), outcome: o }).then(function (res) {
				msg.textContent = (res.data && res.data.message) || '';
				tr.classList.add(res.success ? 'is-done' : 'is-error');
				if (!res.success) { buttons.forEach(function (x) { x.disabled = false; }); }
			}).catch(function () {
				msg.textContent = A.i18n.failed;
				buttons.forEach(function (x) { x.disabled = false; });
			});
		});
	});

	// Request a feature: open a pre-filled GitHub issue (nothing is sent by the server).
	var fb = $('#dzcod-feedback');
	if (fb) {
		fb.addEventListener('submit', function (e) {
			e.preventDefault();
			var type = $('[name=type]:checked', fb), title = $('#dzcod-fb-title').value.trim(), body = $('#dzcod-fb-body').value.trim();
			if (!title || !body) { return; }
			if ($('#dzcod-fb-env').checked) { body += '\n\n---\n' + A.env; }
			var field = type.value === 'bug' ? 'what' : (type.value === 'courier' ? 'api' : 'details');
			var url = A.repo + '/issues/new?template=' + encodeURIComponent(type.getAttribute('data-template')) +
				'&title=' + encodeURIComponent(title) + '&' + field + '=' + encodeURIComponent(body);
			window.open(url, '_blank', 'noopener');
		});
	}
}());
