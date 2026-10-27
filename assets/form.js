/* DZ COD Order Form — front-end. No dependencies. */
(function () {
	'use strict';

	var dataCache = {};   // dataUrl -> Promise of the full dataset.
	var communeCache = {}; // wilaya code -> communes.

	function readConfig(form) {
		try {
			var raw = form.getAttribute('data-config');
			if (raw) { return JSON.parse(raw); }
		} catch (e) { /* fall through */ }
		return window.DZCOD || null;
	}

	function post(url, fields) {
		var fd = fields instanceof FormData ? fields : new FormData();
		if (!(fields instanceof FormData)) {
			Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
		}
		return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	// Communes: static JSON file first (cacheable), then the AJAX endpoint if the file is blocked.
	function getCommunes(C, code) {
		if (communeCache[code]) { return Promise.resolve(communeCache[code]); }
		if (!dataCache[C.dataUrl]) {
			dataCache[C.dataUrl] = fetch(C.dataUrl, { credentials: 'same-origin' })
				.then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); });
		}
		return dataCache[C.dataUrl].then(function (d) {
			if (!d || !d[code]) { throw new Error('missing'); }
			return d[code].c;
		}).catch(function () {
			dataCache[C.dataUrl] = Promise.reject(new Error('blocked'));
			dataCache[C.dataUrl].catch(function () {});
			return post(C.ajaxUrl, { action: 'dzcod_communes', wilaya: code }).then(function (res) {
				if (!res || !res.success) { throw new Error('ajax'); }
				return res.data;
			});
		}).then(function (list) {
			communeCache[code] = list;
			return list;
		});
	}

	function money(C, n) {
		var c = C.currency, d = parseInt(c.decimals, 10) || 0;
		var parts = Number(n).toFixed(d).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand);
		var num = parts.join(c.decimal);
		switch (c.pos) {
			case 'left': return c.symbol + num;
			case 'left_space': return c.symbol + ' ' + num;
			case 'right': return num + c.symbol;
			default: return num + ' ' + c.symbol;
		}
	}

	function normalizePhone(p) {
		var d = String(p || '').replace(/\D+/g, '');
		if (d.indexOf('00213') === 0) { d = '0' + d.slice(5); }
		else if (d.indexOf('213') === 0 && d.length >= 11) { d = '0' + d.slice(3); }
		else if (d.length === 9 && /^[567]/.test(d)) { d = '0' + d; }
		return d;
	}
	function validPhone(p) { return /^0(?:[567]\d{8}|[234]\d{7})$/.test(normalizePhone(p)); }

	function option(value, label) {
		var o = document.createElement('option');
		o.value = value;
		o.textContent = label;
		return o;
	}

	function initForm(form) {
		var C = readConfig(form);
		if (!C) { return; }
		var $ = function (sel) { return form.querySelector(sel); };
		var wilaya = $('[name="wilaya"]');
		var commune = $('[name="commune"]');
		var qty = $('[name="qty"]');
		var variation = $('[name="variation_id"]');
		var msg = $('.dzcod-message');
		var submit = $('.dzcod-submit');
		var ar = C.lang === 'ar';
		var loadToken = 0;

		function unitPrice() {
			if (variation) {
				var o = variation.options[variation.selectedIndex];
				return parseFloat(o ? o.getAttribute('data-price') : 0) || 0;
			}
			return parseFloat(form.getAttribute('data-price')) || 0;
		}

		function deliveryType() {
			var r = form.querySelector('[name="delivery_type"]:checked') || form.querySelector('[name="delivery_type"]');
			return r ? r.value : 'home';
		}

		function update() {
			var q = Math.max(1, Math.min(99, parseInt(qty.value, 10) || 1));
			var sub = unitPrice() * q;
			var p = C.prices[wilaya.value];
			var free = C.freeOver > 0 && sub >= C.freeOver;
			var home = form.querySelector('.dzcod-price-home');
			var desk = form.querySelector('.dzcod-price-desk');
			if (home) { home.textContent = p ? (free ? C.i18n.free : money(C, p[0])) : ''; }
			if (desk) { desk.textContent = p ? (free ? C.i18n.free : money(C, p[1])) : ''; }
			var ship = p ? (free ? 0 : (deliveryType() === 'desk' ? p[1] : p[0])) : null;
			$('.dzcod-sum-product').textContent = money(C, sub) + (q > 1 ? ' (×' + q + ')' : '');
			$('.dzcod-sum-shipping').textContent = ship === null ? '—' : (ship === 0 ? C.i18n.free : money(C, ship));
			$('.dzcod-sum-total').textContent = money(C, sub + (ship || 0));
			var addr = form.querySelector('.dzcod-address');
			if (addr) { addr.style.display = deliveryType() === 'desk' ? 'none' : ''; }
		}

		function resetCommunes(label, disabled) {
			commune.innerHTML = '';
			commune.appendChild(option('', label));
			commune.disabled = disabled;
		}

		function loadCommunes() {
			var code = wilaya.value;
			var token = ++loadToken;
			if (!code) { resetCommunes(C.i18n.chooseCommune, true); return; }
			resetCommunes(C.i18n.loading, true);
			getCommunes(C, code).then(function (list) {
				if (token !== loadToken) { return; }
				resetCommunes(C.i18n.chooseCommune, false);
				list.forEach(function (c) {
					// Value is always the Latin name; label follows the chosen language.
					commune.appendChild(option(c[0], ar ? c[1] : c[0]));
				});
				if (list.length === 1) { commune.selectedIndex = 1; }
			}).catch(function () {
				if (token !== loadToken) { return; }
				resetCommunes(C.i18n.chooseCommune, true);
				showError(C.i18n.communesError);
			});
		}

		wilaya.addEventListener('change', function () { loadCommunes(); update(); });
		form.addEventListener('change', update);
		form.addEventListener('input', function (e) { if (e.target === qty) { update(); } });
		Array.prototype.forEach.call(form.querySelectorAll('.dzcod-qty-btn'), function (b) {
			b.addEventListener('click', function () {
				qty.value = Math.max(1, Math.min(99, (parseInt(qty.value, 10) || 1) + parseInt(b.getAttribute('data-step'), 10)));
				update();
			});
		});
		// Browser back/forward cache can restore a chosen wilaya with an empty commune list.
		if (wilaya.value) { loadCommunes(); }
		update();

		function showError(text, field) {
			msg.textContent = text;
			msg.className = 'dzcod-message is-error';
			Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (el) { el.classList.remove('is-invalid'); });
			var el = field ? form.querySelector('[name="' + field + '"]') : null;
			if (el) { el.classList.add('is-invalid'); try { el.focus(); } catch (ignore) {} }
		}

		function val(name) { var el = $('[name="' + name + '"]'); return el ? String(el.value || '').trim() : null; }

		function validate() {
			if (val('name').length < 3) { return [C.i18n.invalidName, 'name']; }
			if (!validPhone(val('phone'))) { return [C.i18n.invalidPhone, 'phone']; }
			var p2 = val('phone2');
			if (p2 !== null && (p2 ? !validPhone(p2) : C.required.phone2)) { return [C.i18n.invalidPhone, 'phone2']; }
			var em = val('email');
			if (em !== null && (em ? !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em) : C.required.email)) { return [C.i18n.invalidEmail, 'email']; }
			if (!wilaya.value) { return [C.i18n.chooseWilaya, 'wilaya']; }
			if (!commune.value) { return [C.i18n.chooseCommune2, 'commune']; }
			var ad = val('address');
			if (ad !== null && C.required.address && deliveryType() === 'home' && ad.length < 4) { return [C.i18n.invalidAddress, 'address']; }
			if (val('note') !== null && C.required.note && !val('note')) { return [C.i18n.requiredField, 'note']; }
			var bad = null;
			Array.prototype.forEach.call(form.querySelectorAll('.dzcod-custom[data-required="1"] [name]'), function (el) {
				if (bad) { return; }
				var empty = el.type === 'checkbox' ? !el.checked : !String(el.value || '').trim();
				if (empty) { bad = [C.i18n.requiredField, el.name]; }
			});
			if (bad) { return bad; }
			if (variation && !variation.value) { return [C.i18n.chooseOption, 'variation_id']; }
			return null;
		}

		function send(retried) {
			var fd = new FormData(form);
			fd.append('action', 'dzcod_place_order');
			fd.append('nonce', C.nonce);
			fd.append('product_id', form.getAttribute('data-product'));
			fd.set('phone', normalizePhone(fd.get('phone')));
			if (fd.has('phone2') && fd.get('phone2')) { fd.set('phone2', normalizePhone(fd.get('phone2'))); }
			return post(C.ajaxUrl, fd).then(function (res) {
				if (!res.success && res.data && res.data.code === 'nonce' && !retried) {
					return post(C.ajaxUrl, { action: 'dzcod_nonce' }).then(function (n) { C.nonce = n.data.nonce; return send(true); });
				}
				return res;
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var err = validate();
			if (err) { showError(err[0], err[1]); return; }
			submit.disabled = true;
			var label = submit.textContent;
			submit.textContent = C.i18n.sending;
			msg.className = 'dzcod-message';
			msg.textContent = '';

			send(false).then(function (res) {
				if (!res || !res.success) {
					var d = (res && res.data) || {};
					showError(d.message || C.i18n.error, d.field);
					return;
				}
				var o = res.data.order;
				document.dispatchEvent(new CustomEvent('dzcod:order', { detail: o }));
				// Ad pixels, if the site already loads them. eventID lets you dedupe with server events.
				try {
					if (window.fbq) { window.fbq('track', 'Purchase', { value: o.total, currency: o.currency, content_type: 'product', content_ids: o.items.map(function (i) { return String(i.id); }) }, { eventID: 'dzcod-' + o.id }); }
					if (window.ttq) { window.ttq.track('CompletePayment', { value: o.total, currency: o.currency, event_id: 'dzcod-' + o.id }); }
					if (window.gtag) { window.gtag('event', 'purchase', { transaction_id: String(o.id), value: o.total, currency: o.currency }); }
				} catch (ignore) {}

				if (res.data.redirect) { window.location.href = res.data.redirect; return; }
				form.classList.add('is-done');
				msg.textContent = res.data.message;
				msg.className = 'dzcod-message is-success';
				msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}).catch(function () {
				showError(C.i18n.error);
			}).then(function () {
				submit.disabled = false;
				submit.textContent = label;
			});
		});
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('form.dzcod-form:not([data-ready])'), function (f) {
			f.setAttribute('data-ready', '1');
			initForm(f);
		});
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
	// Elementor editor, popups and AJAX-loaded content render forms later.
	window.addEventListener('elementor/frontend/init', boot);
	document.addEventListener('dzcod:boot', boot);
	if (window.MutationObserver) {
		new MutationObserver(function () { if (document.querySelector('form.dzcod-form:not([data-ready])')) { boot(); } })
			.observe(document.documentElement, { childList: true, subtree: true });
	}
}());
