(function () {
    'use strict';

    var dedupeStoreKey = 'erp_desktop_notified_ids';

    function isSupported() {
        return typeof window !== 'undefined' && 'Notification' in window;
    }

    function isLoggedInContext() {
        var path = String((window.location && window.location.pathname) || '').toLowerCase();
        return path.indexOf('/auth/login.php') === -1;
    }

    function canDisplayNow() {
        return document.visibilityState === 'visible' || isLoggedInContext();
    }

    function appBasePath() {
        var path = String((window.location && window.location.pathname) || '');
        var marker = '/modules/';
        var idx = path.toLowerCase().indexOf(marker);
        if (idx >= 0) {
            return path.slice(0, idx);
        }
        var authIdx = path.toLowerCase().indexOf('/auth/');
        if (authIdx >= 0) {
            return path.slice(0, authIdx);
        }
        return '';
    }

    function iconUrl() {
        return appBasePath() + '/pwa_icon.php?size=192';
    }

    function readSeenMap() {
        try {
            var raw = sessionStorage.getItem(dedupeStoreKey);
            if (!raw) return {};
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeSeenMap(map) {
        try {
            sessionStorage.setItem(dedupeStoreKey, JSON.stringify(map));
        } catch (e) {
            // Ignore storage failures silently.
        }
    }

    function requestNotificationPermission() {
        if (!isSupported()) return;
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    function showDesktopNotification(title, message, options) {
        if (!isSupported()) return;
        if (Notification.permission !== 'granted') return;
        if (!canDisplayNow()) return;

        var opts = options || {};
        var dedupeId = String(opts.dedupeId || '').trim();
        if (dedupeId !== '') {
            var seenMap = readSeenMap();
            if (seenMap[dedupeId]) return;
            seenMap[dedupeId] = 1;
            writeSeenMap(seenMap);
        }

        try {
            new Notification(String(title || 'ERP Notification'), {
                body: String(message || ''),
                icon: iconUrl(),
                tag: dedupeId || undefined
            });
        } catch (e) {
            // Ignore notification failures.
        }
    }

    function isImportantDepartment(department) {
        var d = String(department || '').trim().toLowerCase();
        return d === 'packing' || d === 'dispatch' || d === 'planning';
    }

    window.requestNotificationPermission = requestNotificationPermission;
    window.showDesktopNotification = showDesktopNotification;
    window.erpDesktopNotifications = {
        requestPermission: requestNotificationPermission,
        show: showDesktopNotification,
        isImportantDepartment: isImportantDepartment
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', requestNotificationPermission, { once: true });
    } else {
        requestNotificationPermission();
    }
})();
