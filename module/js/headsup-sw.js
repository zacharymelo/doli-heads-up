/* Heads-up service worker: opens the right page when a notification is tapped.
 *
 * A notification made by the page itself (new Notification) only reacts to a
 * tap while that page is running. On an iPhone Home Screen app the page is
 * asleep by the time someone taps the notification, so nothing happens. A
 * notification shown through this worker wakes it on a tap instead, wherever
 * the app is.
 *
 * Registered with its own folder as scope, so it never takes over Dolibarr's
 * pages or replaces another module's worker. It has no fetch handler: page
 * loads never pass through it.
 */
'use strict';

self.addEventListener('install', function () {
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(self.clients.claim());
});

self.addEventListener('notificationclick', function (event) {
	var data = event.notification.data || {};
	var url = new URL(data.url || data.fallback || '/', self.location.origin);
	event.notification.close();
	if (url.origin !== self.location.origin) {
		return;
	}
	event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		var win = null;
		list.forEach(function (c) {
			if (new URL(c.url).origin === self.location.origin && (!win || c.focused || (c.visibilityState === 'visible' && !win.focused))) {
				win = c;
			}
		});
		if (!win) {
			return self.clients.openWindow(url.href);
		}
		// The page is not ours to navigate (out of scope): it goes there itself
		win.postMessage({ headsup: 'open', url: url.href });
		return win.focus().catch(function () {
			return self.clients.openWindow(url.href);
		});
	}));
});
