(function () {
	// The id saved in localStorage is the oldest one this browser had, so it wins over a fresh cookie.
	var n = 't5fob_did', ok = /^[a-f0-9]{32}$/, m = document.cookie.match(/(?:^|; )t5fob_did=([a-f0-9]{32})/), id = null;
	try { id = localStorage.getItem(n); } catch (e) {}
	if (!id || !ok.test(id)) { id = m && m[1]; }
	if (!id) {
		var a = new Uint8Array(16);
		crypto.getRandomValues(a);
		id = Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}
	document.cookie = n + '=' + id + ';path=/;max-age=63072000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	try { localStorage.setItem(n, id); } catch (e) {}
})();

// Blocked checkout: WhatsApp opens in its own app, so the page never navigates and the
// spinner would spin forever. Open WhatsApp ourselves and return false: WooCommerce then
// shows the server's "messages" box (with an Open WhatsApp button) and stops the spinner.
(function ($) {
	if (!$) { return; }
	$('form.checkout').on('checkout_place_order_success', function (e, result) {
		if (!result || !result.t5fob) { return; }
		window.location.href = result.redirect;
		return false;
	});
})(window.jQuery);

// Block checkout: the refusal carries the same box. Show it above the form and open WhatsApp.
(function (wp) {
	if (!wp || !wp.apiFetch) { return; }
	wp.apiFetch.use(function (options, next) {
		return next(options).catch(function (err) {
			var d = err && err.code === 't5fob_blocked' && err.data;
			var form = d && d.t5fob_box && document.querySelector('.wc-block-checkout');
			if (form) {
				var old = document.querySelector('.t5fob-wa-box');
				if (old) { old.remove(); }
				form.insertAdjacentHTML('beforebegin', d.t5fob_box);
				document.querySelector('.t5fob-wa-box').scrollIntoView({ block: 'center' });
				window.location.href = d.t5fob_url;
			}
			throw err;
		});
	});
})(window.wp);
