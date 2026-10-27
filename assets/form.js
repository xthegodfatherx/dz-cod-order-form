/* DZ COD Order Form — front-end. No dependencies. */
(function () {
	'use strict';
	if (typeof window.DZCOD === 'undefined') { return; }
	var C = window.DZCOD;
	var dataPromise = null;

	function loadData() {
		if (!dataPromise) {
			dataPromise = fetch(C.dataUrl, { credentials: 'same-origin' }).then(function (r) {
				if (!r.ok) { throw new Error('data'); }
				return r.json();
			});
		}
		return dataPromise;
	}

	function money(n) {
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

	function option(value, label) {
		var o = document.createElement('option');
		o.value = value;
		o.textContent = label;
		return o;
	}

	function initForm(form) {
		var $ = function (sel) { return form.querySelector(sel); };
		var wilaya = $('[name="wilaya"]');
		var commune = $('[name="commune"]');
		var qty = $('[name="qty"]');
		var variation = $('[name="variation_id"]');
		var msg = $('.dzcod-message');
		var submit = $('.dzcod-submit');
		var data = null;
		var ar = C.lang === 'ar';

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
			var w = wilaya.value;
			var p = C.prices[w];
			var free = C.freeOver > 0 && sub >= C.freeOver;
			var home = form.querySelector('.dzcod-price-home');
			var desk = form.querySelector('.dzcod-price-desk');
			if (home) { home.textContent = p ? (free ? C.i18n.free : money(p[0])) : ''; }
			if (desk) { desk.textContent = p ? (free ? C.i18n.free : money(p[1])) : ''; }
			var ship = p ? (free ? 0 : (deliveryType() === 'desk' ? p[1] : p[0])) : null;
			$('.dzcod-sum-product').textContent = money(sub) + (q > 1 ? ' (×' + q + ')' : '');
			$('.dzcod-sum-shipping').textContent = ship === null ? '—' : (ship === 0 ? C.i18n.free : money(ship));
			$('.dzcod-sum-total').textContent = money(sub + (ship || 0));
			var addr = form.querySelector('.dzcod-address');
			if (addr) { addr.style.display = deliveryType() === 'desk' ? 'none' : ''; }
		}

		function fillCommunes() {
			commune.innerHTML = '';
			commune.appendChild(option('', C.i18n.chooseCommune));
			var w = data && data[wilaya.value];
			commune.disabled = !w;
			if (w) {
				w.c.forEach(function (c) {
					// Value is always the Latin name; label follows the chosen language.
					commune.appendChild(option(c[0], ar ? c[1] : c[0]));
				});
			}
		}

		loadData().then(function (d) {
			data = d;
			wilaya.innerHTML = '';
			wilaya.appendChild(option('', C.i18n.chooseWilaya));
			Object.keys(d).sort().forEach(function (code) {
				if (!C.prices[code]) { return; }
				wilaya.appendChild(option(code, code + ' - ' + (ar ? d[code].ar : d[code].n)));
			});
		}).catch(function () {
			msg.textContent = C.i18n.error;
			msg.className = 'dzcod-message is-error';
		});

		wilaya.addEventListener('change', function () { fillCommunes(); update(); });
		form.addEventListener('change', update);
		form.addEventListener('input', function (e) { if (e.target === qty) { update(); } });
		Array.prototype.forEach.call(form.querySelectorAll('.dzcod-qty-btn'), function (b) {
			b.addEventListener('click', function () {
				qty.value = Math.max(1, Math.min(99, (parseInt(qty.value, 10) || 1) + parseInt(b.getAttribute('data-step'), 10)));
				update();
			});
		});
		update();

		function showError(text, field) {
			msg.textContent = text;
			msg.className = 'dzcod-message is-error';
			Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (el) { el.classList.remove('is-invalid'); });
			var el = field ? form.querySelector('[name="' + field + '"]') : null;
			if (el) { el.classList.add('is-invalid'); el.focus(); }
		}

		function validate() {
			var name = $('[name="name"]');
			if (name.value.trim().length < 3) { return [C.i18n.invalidName, 'name']; }
			if (!/^0(?:[567]\d{8}|[234]\d{7})$/.test(normalizePhone($('[name="phone"]').value))) { return [C.i18n.invalidPhone, 'phone']; }
			if (!wilaya.value) { return [C.i18n.chooseWilaya, 'wilaya']; }
			if (!commune.value) { return [C.i18n.chooseCommune, 'commune']; }
			var addr = $('[name="address"]');
			if (addr && addr.getAttribute('data-required-home') && deliveryType() === 'home' && addr.value.trim().length < 4) { return [C.i18n.invalidAddress, 'address']; }
			if (variation && !variation.value) { return [C.i18n.chooseOption, 'variation_id']; }
			return null;
		}

		function send(retried) {
			var fd = new FormData(form);
			fd.append('action', 'dzcod_place_order');
			fd.append('nonce', C.nonce);
			fd.append('product_id', form.getAttribute('data-product'));
			fd.set('phone', normalizePhone(fd.get('phone')));
			return fetch(C.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.success && res.data && res.data.code === 'nonce' && !retried) {
						var nf = new FormData();
						nf.append('action', 'dzcod_nonce');
						return fetch(C.ajaxUrl, { method: 'POST', body: nf, credentials: 'same-origin' })
							.then(function (r) { return r.json(); })
							.then(function (n) { C.nonce = n.data.nonce; return send(true); });
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
	// Elementor editor / popups render forms later.
	window.addEventListener('elementor/frontend/init', boot);
	document.addEventListener('dzcod:boot', boot);
}());
