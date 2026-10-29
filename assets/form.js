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
		var color = form.getAttribute('data-color');
		if (color && /^#[0-9a-f]{3,8}$/i.test(color)) { form.style.setProperty('--dzcod-primary', color); }
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

		function offerTotal(input, unit) {
			var q = parseInt(input.getAttribute('data-qty'), 10) || 1;
			var fixed = input.getAttribute('data-price'), pct = input.getAttribute('data-percent');
			if (fixed !== '' && fixed !== null) { return parseFloat(fixed); }
			var t = unit * q;
			if (pct !== '' && pct !== null) { t = t * (1 - parseFloat(pct) / 100); }
			var d = parseInt(C.currency.decimals, 10) || 0;
			return Math.round(t * Math.pow(10, d)) / Math.pow(10, d);
		}

		function update() {
			var unit = unitPrice();
			var offer = form.querySelector('[name="offer"]:checked');
			// Pack cards: show each pack's price and the crossed-out normal price.
			Array.prototype.forEach.call(form.querySelectorAll('[name="offer"]'), function (o) {
				var card = o.closest('.dzcod-offer');
				var total = offerTotal(o, unit), normal = unit * (parseInt(o.getAttribute('data-qty'), 10) || 1);
				card.querySelector('.dzcod-offer-total').textContent = money(C, total);
				card.querySelector('.dzcod-offer-was').textContent = normal > total + 0.009 ? money(C, normal) : '';
				card.classList.toggle('is-selected', o.checked);
			});
			if (offer) { qty.value = offer.getAttribute('data-qty'); }
			var q = Math.max(1, Math.min(99, parseInt(qty.value, 10) || 1));
			var sub = offer ? offerTotal(offer, unit) : unit * q;
			var p = C.prices[wilaya.value];
			var fMode = form.getAttribute('data-free-mode') || '', fWil = (form.getAttribute('data-free-wilayas') || '').split(',').filter(Boolean);
			var productFree = function (type) { return fMode !== '' && (fMode === 'all' || fMode === type) && (!fWil.length || fWil.indexOf(wilaya.value) !== -1); };
			var free = (offer && offer.getAttribute('data-free') === '1') || (C.freeOver > 0 && sub >= C.freeOver);
			var home = form.querySelector('.dzcod-price-home');
			var desk = form.querySelector('.dzcod-price-desk');
			if (home) { home.textContent = p ? ((free || productFree('home')) ? C.i18n.free : money(C, p[0])) : ''; }
			if (desk) { desk.textContent = p ? ((free || productFree('desk')) ? C.i18n.free : money(C, p[1])) : ''; }
			var ship = p ? ((free || productFree(deliveryType())) ? 0 : (deliveryType() === 'desk' ? p[1] : p[0])) : null;
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
		// Abandoned-order capture: once a valid phone is typed, save the form (debounced),
		// so the shop can call back visitors who leave without confirming.
		var leadToken = '', lastSent = '', leadTimer = null, done = false;
		function makeToken() {
			var a = new Uint8Array(16), out = '';
			(window.crypto || window.msCrypto).getRandomValues(a);
			for (var i = 0; i < a.length; i++) { out += ('0' + a[i].toString(16)).slice(-2); }
			return out;
		}
		function captureLead(leaving) {
			if (!C.leads || done || !validPhone(val('phone'))) { return; }
			if (!leadToken) { leadToken = makeToken(); }
			var fd = new FormData(form);
			fd.delete('dzcod_website');
			var sig = [];
			fd.forEach(function (v, k) { sig.push(k + '=' + v); });
			sig = sig.join('&');
			if (sig === lastSent) { return; }
			lastSent = sig;
			fd.append('action', 'dzcod_capture');
			fd.append('nonce', C.nonce);
			fd.append('lead_token', leadToken);
			fd.append('product_id', form.getAttribute('data-product'));
			fd.set('phone', normalizePhone(fd.get('phone')));
			fd.append('dzcod_website', ($('[name="dzcod_website"]') || {}).value || '');
			try { fetch(C.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: !!leaving }).catch(function () {}); } catch (ignore) {}
		}
		function scheduleLead() {
			clearTimeout(leadTimer);
			leadTimer = setTimeout(function () { captureLead(false); }, 2000);
		}
		form.addEventListener('input', scheduleLead);
		form.addEventListener('change', scheduleLead);
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') { clearTimeout(leadTimer); captureLead(true); }
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

		// One-click upsell card shown under the success message.
		function showUpsell(u, finish) {
			var box = document.createElement('div');
			box.className = 'dzcod-upsell';
			if (u.image) { var img = document.createElement('img'); img.src = u.image; img.alt = ''; box.appendChild(img); }
			var body = document.createElement('div');
			var t = document.createElement('strong'); t.textContent = u.title; body.appendChild(t);
			var n = document.createElement('div'); n.textContent = u.name; body.appendChild(n);
			var p = document.createElement('div'); p.className = 'dzcod-upsell-price';
			var now = document.createElement('b'); now.textContent = u.price; p.appendChild(now);
			if (u.was) { var was = document.createElement('s'); was.textContent = u.was; p.appendChild(was); }
			body.appendChild(p);
			var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'dzcod-submit dzcod-upsell-yes'; yes.textContent = u.yes;
			var no = document.createElement('button'); no.type = 'button'; no.className = 'dzcod-upsell-no'; no.textContent = u.no;
			body.appendChild(yes); body.appendChild(no);
			box.appendChild(body);
			msg.insertAdjacentElement('afterend', box);
			no.addEventListener('click', function () { box.remove(); finish(); });
			yes.addEventListener('click', function () {
				yes.disabled = true; no.disabled = true; yes.textContent = C.i18n.sending;
				post(C.ajaxUrl, { action: 'dzcod_upsell', nonce: C.nonce, order: u.order, product: u.product, token: u.token }).then(function (r) {
					var done = document.createElement('p');
					done.className = 'dzcod-message ' + (r && r.success ? 'is-success' : 'is-error');
					done.textContent = (r && r.data && r.data.message ? r.data.message : C.i18n.error) + (r && r.success && r.data.total ? ' — ' + r.data.total : '');
					box.replaceWith(done);
					if (r && r.success) { document.dispatchEvent(new CustomEvent('dzcod:upsell', { detail: u })); }
					finish();
				}).catch(function () { box.remove(); finish(); });
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
				done = true;
				clearTimeout(leadTimer);
				var o = res.data.order;
				document.dispatchEvent(new CustomEvent('dzcod:order', { detail: o }));
				// Ad pixels, if the site already loads them. eventID lets you dedupe with server events.
				try {
					// "Count a purchase when confirmed": the browser only reports a lead; the server sends Purchase later.
					var ids = o.items.map(function (i) { return String(i.id); });
					if (window.fbq) { window.fbq('track', C.purchase === false ? 'Lead' : 'Purchase', { value: o.total, currency: o.currency, content_type: 'product', content_ids: ids }, { eventID: (C.purchase === false ? 'dzcod-lead-' : 'dzcod-') + o.id }); }
					if (window.ttq) { window.ttq.track(C.purchase === false ? 'SubmitForm' : 'CompletePayment', { value: o.total, currency: o.currency, content_type: 'product', contents: o.items.map(function (i) { return { content_id: String(i.id), quantity: i.quantity }; }) }, { event_id: (C.purchase === false ? 'dzcod-lead-' : 'dzcod-') + o.id }); }
					if (window.gtag) { window.gtag('event', 'purchase', { transaction_id: String(o.id), value: o.total, currency: o.currency }); }
				} catch (ignore) {}

				var redirect = res.data.redirect;
				var finish = function () { if (redirect) { window.location.href = redirect; } };
				form.classList.add('is-done');
				msg.textContent = res.data.message;
				msg.className = 'dzcod-message is-success';
				if (res.data.upsell) { showUpsell(res.data.upsell, finish); } else { finish(); }
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
