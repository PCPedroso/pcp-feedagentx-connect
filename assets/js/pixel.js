/**
 * FeedAgentX – OpenAI Measurement Pixel for WooCommerce.
 *
 * Reads window.faxcPixel (printed by the plugin), waits for consent, then
 * loads OpenAI's SDK and sends the page's events. Until consent is known
 * nothing is loaded and nothing leaves the browser.
 *
 * For custom cookie banners:
 *   FeedAgentXPixel.grantConsent();   // start measuring
 *   FeedAgentXPixel.revokeConsent();  // stop and remove OpenAI's cookies
 */
(function (w, d) {
	'use strict';

	var cfg = w.faxcPixel;
	if (!cfg || !cfg.pixelId || w.FeedAgentXPixel) {
		return;
	}

	// Shared with the server: the consent it records on the order at checkout.
	var CONSENT_COOKIE = 'faxc_mc';
	var YEAR = 31536000;
	var SECURE = w.location.protocol === 'https:' ? ';Secure' : '';
	var granted = false;
	var started = false;
	var held = [];

	function log() {
		if (cfg.debug && w.console && w.console.log) {
			w.console.log.apply(w.console, ['[FeedAgentX pixel]'].concat([].slice.call(arguments)));
		}
	}

	function readCookie(name) {
		var match = d.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : null;
	}

	function writeCookie(name, value, maxAge) {
		d.cookie = name + '=' + value + ';path=' + (cfg.cookiePath || '/') + ';max-age=' + maxAge + ';SameSite=Lax' + SECURE;
	}

	// OpenAI's documented queue stub: calls wait here until the SDK loads.
	if (!w.oaiq) {
		var stub = function () {
			stub.q.push(arguments);
		};
		stub.q = [];
		w.oaiq = stub;
	}

	function loadSdk() {
		var js = d.createElement('script');
		js.async = true;
		js.src = cfg.sdk;
		var first = d.getElementsByTagName('script')[0];
		if (first && first.parentNode) {
			first.parentNode.insertBefore(js, first);
		} else {
			(d.head || d.documentElement).appendChild(js);
		}
	}

	function send(event) {
		if (event[2]) {
			w.oaiq('measure', event[0], event[1], event[2]);
		} else {
			w.oaiq('measure', event[0], event[1]);
		}
		log('measure', event[0], event[1], event[2] || '');
	}

	function track(events) {
		if (!events || !events.length) {
			return;
		}
		for (var i = 0; i < events.length; i++) {
			if (granted) {
				send(events[i]);
			} else {
				held.push(events[i]);
			}
		}
	}

	function start() {
		// Consent first, then init: the order OpenAI's docs give.
		w.oaiq('consent', true);
		var init = { pixelId: cfg.pixelId };
		if (cfg.debug) {
			init.debug = true;
		}
		if (cfg.user) {
			init.user = cfg.user;
		}
		w.oaiq('init', init);
		loadSdk();
		started = true;
		log('started');
	}

	function setGranted(value) {
		if (value === granted) {
			return;
		}
		granted = value;
		if (granted) {
			if (started) {
				w.oaiq('consent', true);
			} else {
				start();
			}
			var queue = held;
			held = [];
			for (var i = 0; i < queue.length; i++) {
				send(queue[i]);
			}
		} else if (started) {
			// Stops measurement and makes the SDK remove its cookies.
			w.oaiq('consent', false);
			log('consent withdrawn');
		}
	}

	// ───────────── Consent ─────────────

	function consentApi() {
		return typeof w.wp_has_consent === 'function';
	}

	function bannerDecides() {
		return !!(cfg.cmp || cfg.cmpWaitsForType || (consentApi() && w.wp_consent_type));
	}

	/** @return {{granted: boolean, known: boolean}} */
	function decide() {
		if (bannerDecides()) {
			// The WP Consent API loads after this script, and some banners
			// set their consent type later still. Until they answer: no.
			if (!consentApi() || (cfg.cmpWaitsForType && typeof w.wp_consent_type === 'undefined')) {
				return { granted: false, known: false };
			}
			return { granted: !!w.wp_has_consent('marketing'), known: true };
		}
		var stored = readCookie(CONSENT_COOKIE);
		if (stored === '0') {
			return { granted: false, known: true };
		}
		if (cfg.consentMode === 'require') {
			return { granted: stored === '1', known: stored !== null };
		}
		return { granted: true, known: true };
	}

	function reevaluate() {
		var answer = decide();
		if (answer.known && bannerDecides()) {
			// The server cannot ask a browser-side banner; leave it the answer.
			writeCookie(CONSENT_COOKIE, answer.granted ? '1' : '0', YEAR);
		}
		setGranted(answer.granted);
	}

	// ───────────── Add to cart ─────────────

	function decodePending(value) {
		try {
			var b64 = value.replace(/-/g, '+').replace(/_/g, '/');
			while (b64.length % 4) {
				b64 += '=';
			}
			var binary = w.atob(b64);
			var text;
			if (w.TextDecoder) {
				var bytes = new Uint8Array(binary.length);
				for (var i = 0; i < binary.length; i++) {
					bytes[i] = binary.charCodeAt(i);
				}
				text = new w.TextDecoder('utf-8').decode(bytes);
			} else {
				text = decodeURIComponent(escape(binary));
			}
			var events = JSON.parse(text);
			return Array.isArray(events) ? events : [];
		} catch (err) {
			log('could not read add-to-cart events');
			return [];
		}
	}

	// The add-to-cart response left its events in a cookie; send them once.
	function takePending() {
		var raw = readCookie(cfg.pendingCookie);
		if (!raw) {
			return;
		}
		writeCookie(cfg.pendingCookie, '', 0);
		var events = decodePending(raw);
		var added = [];
		for (var i = 0; i < events.length; i++) {
			if (events[i] && events[i][0] === 'items_added' && events[i][1]) {
				added.push(['items_added', events[i][1]]);
			}
		}
		track(added);
	}

	w.FeedAgentXPixel = {
		grantConsent: function () {
			writeCookie(CONSENT_COOKIE, '1', YEAR);
			reevaluate();
		},
		revokeConsent: function () {
			writeCookie(CONSENT_COOKIE, '0', YEAR);
			reevaluate();
		},
		/** Send a supported OpenAI event, e.g. track('lead_created', { type: 'customer_action' }). */
		track: function (name, data, options) {
			track([[name, data, options]]);
		}
	};

	track(cfg.events);
	takePending();
	reevaluate();

	// WP Consent API: a banner granted or withdrew consent, or defined its type late.
	d.addEventListener('wp_listen_for_consent_change', function (e) {
		var changed = e && e.detail ? e.detail : {};
		if (Object.prototype.hasOwnProperty.call(changed, 'marketing')) {
			var allow = changed.marketing === 'allow';
			writeCookie(CONSENT_COOKIE, allow ? '1' : '0', YEAR);
			setGranted(allow);
		}
	});
	d.addEventListener('wp_consent_type_defined', reevaluate);

	// Block themes: the Store API answered an add-to-cart.
	d.addEventListener('wc-blocks_added_to_cart', takePending);

	function ready() {
		reevaluate();
		// Classic themes: an AJAX add-to-cart finished.
		if (w.jQuery) {
			w.jQuery(d.body).on('added_to_cart', takePending);
		}
		takePending();
	}

	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}
	w.addEventListener('load', reevaluate);
})(window, document);
