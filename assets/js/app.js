// ============================================================
// ERP System — Global UI Script
// ============================================================

/**
 * Shared SQM calculator — reused across Paper Stock & Reports.
 * SQM = (Width_mm × Length_mtr) / 1000
 * Returns 0 if inputs are non-numeric.
 */
window.erpCalcSQM = function(widthMm, lengthMtr) {
    var w = parseFloat(widthMm) || 0;
    var l = parseFloat(lengthMtr) || 0;
    return (w > 0 && l > 0) ? (w / 1000) * l : 0;
};

(function () {
    'use strict';

    function setupCenterMessageUI() {
        if (document.getElementById('erpCenterMessageOverlay')) {
            return;
        }

        var style = document.createElement('style');
        style.id = 'erpCenterMessageStyle';
        style.textContent = '' +
            '#erpCenterMessageOverlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.45);z-index:9999;padding:18px}' +
            '#erpCenterMessageOverlay.is-open{display:flex}' +
            '#erpCenterMessageCard{width:min(560px,95vw);background:linear-gradient(145deg,#ffffff,#f8fafc);border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 26px 60px rgba(2,6,23,.28);overflow:hidden}' +
            '#erpCenterMessageHead{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(90deg,#0f172a,#1e293b);color:#fff}' +
            '#erpCenterMessageTitle{font-size:15px;font-weight:700;letter-spacing:.2px}' +
            '#erpCenterMessageClose{border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:8px;padding:5px 9px;cursor:pointer}' +
            '#erpCenterMessageBody{padding:18px;color:#0f172a;font-size:15px;line-height:1.5;max-height:58vh;overflow:auto;white-space:pre-wrap}' +
            '#erpCenterMessageFooter{display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;background:#f8fafc;border-top:1px solid #e2e8f0}' +
            '#erpCenterMessageAction,#erpCenterMessageCancel,#erpCenterMessageOk{border:0;border-radius:10px;padding:9px 14px;font-weight:600;cursor:pointer}' +
            '#erpCenterMessageAction{background:#0f766e;color:#fff;display:none}' +
            '#erpCenterMessageCancel{background:#e2e8f0;color:#334155}' +
            '#erpCenterMessageOk{background:#1d4ed8;color:#fff}' +
            '@media (max-width:640px){#erpCenterMessageBody{font-size:14px;padding:14px}#erpCenterMessageHead,#erpCenterMessageFooter{padding:12px}}';
        document.head.appendChild(style);

        var overlay = document.createElement('div');
        overlay.id = 'erpCenterMessageOverlay';
        overlay.innerHTML = '' +
            '<div id="erpCenterMessageCard" role="dialog" aria-modal="true" aria-live="polite">' +
                '<div id="erpCenterMessageHead">' +
                    '<div id="erpCenterMessageTitle">ERP Message</div>' +
                    '<button id="erpCenterMessageClose" type="button" aria-label="Close">X</button>' +
                '</div>' +
                '<div id="erpCenterMessageBody"></div>' +
                '<div id="erpCenterMessageFooter">' +
                    '<button id="erpCenterMessageAction" type="button"></button>' +
                    '<button id="erpCenterMessageCancel" type="button">Cancel</button>' +
                    '<button id="erpCenterMessageOk" type="button">OK</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        var queue = [];
        var showing = false;
        var titleEl = document.getElementById('erpCenterMessageTitle');
        var bodyEl = document.getElementById('erpCenterMessageBody');
        var actionBtn = document.getElementById('erpCenterMessageAction');
        var cancelBtn = document.getElementById('erpCenterMessageCancel');
        var okBtn = document.getElementById('erpCenterMessageOk');
        var activeItem = null;

        function hideCurrent(decision) {
            overlay.classList.remove('is-open');
            var item = activeItem;
            activeItem = null;
            showing = false;
            if (item) {
                if (decision === 'ok') {
                    if (typeof item.onOk === 'function') {
                        try { item.onOk(); } catch (e) {}
                    }
                } else {
                    if (typeof item.onCancel === 'function') {
                        try { item.onCancel(); } catch (e) {}
                    }
                }
            }
            if (queue.length > 0) {
                setTimeout(showNext, 80);
            }
        }

        function showNext() {
            if (showing || queue.length === 0) return;
            showing = true;
            var item = queue.shift();
            activeItem = item;
            titleEl.textContent = item.title || 'ERP Message';
            bodyEl.textContent = item.message || '';

            if (typeof item.action === 'function') {
                actionBtn.style.display = 'inline-block';
                actionBtn.textContent = item.actionLabel || 'Open';
                actionBtn.onclick = function () {
                    try { item.action(); } catch (e) {}
                    hideCurrent('ok');
                };
            } else {
                actionBtn.style.display = 'none';
                actionBtn.onclick = null;
            }

            cancelBtn.style.display = item.hideCancel ? 'none' : 'inline-block';
            cancelBtn.textContent = item.cancelLabel || 'Cancel';
            okBtn.textContent = item.okLabel || 'OK';

            overlay.classList.add('is-open');
        }

        function enqueueCenterMessage(payload) {
            if (!payload) return;
            queue.push(payload);
            showNext();
        }

        okBtn.addEventListener('click', function () { hideCurrent('ok'); });
        cancelBtn.addEventListener('click', function () { hideCurrent('cancel'); });
        document.getElementById('erpCenterMessageClose').addEventListener('click', function () { hideCurrent('cancel'); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                // Keep blocker strict: outside click must not dismiss the modal.
                return;
            }
        });

        window.erpCenterMessage = function (message, options) {
            var opts = options || {};
            enqueueCenterMessage({
                title: String(opts.title || 'ERP Message'),
                message: String(message == null ? '' : message),
                actionLabel: opts.actionLabel || '',
                action: typeof opts.action === 'function' ? opts.action : null,
                okLabel: opts.okLabel || 'OK',
                cancelLabel: opts.cancelLabel || 'Cancel',
                hideCancel: !!opts.hideCancel,
                onOk: typeof opts.onOk === 'function' ? opts.onOk : null,
                onCancel: typeof opts.onCancel === 'function' ? opts.onCancel : null
            });
        };

        window.showERPToast = function (message, type) {
            var msg = String(message == null ? '' : message).trim();
            if (!msg) return;
            var kind = String(type || 'info').toLowerCase();
            var titleMap = {
                success: 'Success Message',
                ok: 'Success Message',
                error: 'Error Message',
                bad: 'Error Message',
                warning: 'Warning Message',
                warn: 'Warning Message',
                info: 'Info Message'
            };
            window.erpCenterMessage(msg, {
                title: titleMap[kind] || 'Message',
                okLabel: 'OK',
                cancelLabel: 'Cancel'
            });
        };

        window.showERPConfirm = function (message, onOk, options) {
            var opts = options || {};
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:12000;background:rgba(15,23,42,.52);display:flex;align-items:center;justify-content:center;padding:16px';
            overlay.innerHTML = '' +
                '<div style="width:min(520px,96vw);background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 26px 60px rgba(2,6,23,.28);overflow:hidden">' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(90deg,#0f172a,#1e293b);color:#fff">' +
                        '<div style="font-size:15px;font-weight:700;letter-spacing:.2px">' + String(opts.title || 'Please Confirm') + '</div>' +
                        '<button type="button" id="erpConfirmClose" style="border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:8px;padding:5px 9px;cursor:pointer">X</button>' +
                    '</div>' +
                    '<div style="padding:18px;color:#0f172a;font-size:15px;line-height:1.5;white-space:pre-wrap">' + String(message == null ? '' : message).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>' +
                    '<div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;background:#f8fafc;border-top:1px solid #e2e8f0">' +
                        '<button type="button" id="erpConfirmCancel" style="border:1px solid #cbd5e1;background:#fff;color:#374151;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer">' + String(opts.cancelLabel || 'Cancel') + '</button>' +
                        '<button type="button" id="erpConfirmOk" style="border:0;background:#1d4ed8;color:#fff;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer">' + String(opts.okLabel || 'Confirm') + '</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(overlay);

            var done = false;
            function finish(ok) {
                if (done) return;
                done = true;
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                if (ok) {
                    if (typeof onOk === 'function') onOk();
                } else if (typeof opts.onCancel === 'function') {
                    opts.onCancel();
                }
            }

            overlay.querySelector('#erpConfirmOk').addEventListener('click', function () { finish(true); });
            overlay.querySelector('#erpConfirmCancel').addEventListener('click', function () { finish(false); });
            overlay.querySelector('#erpConfirmClose').addEventListener('click', function () { finish(false); });
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    // Do not allow outside click to close confirmation.
                    return;
                }
            });
        };

        window.showERPPrompt = function (message, defaultValue, options) {
            var opts = options || {};
            return new Promise(function (resolve) {
                var overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;z-index:12000;background:rgba(15,23,42,.52);display:flex;align-items:center;justify-content:center;padding:16px';
                overlay.innerHTML = '' +
                    '<div style="width:min(560px,96vw);background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 26px 60px rgba(2,6,23,.28);overflow:hidden">' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(90deg,#0f172a,#1e293b);color:#fff">' +
                            '<div style="font-size:15px;font-weight:700;letter-spacing:.2px">' + String(opts.title || 'Input Required') + '</div>' +
                            '<button type="button" data-erp-prompt-close style="border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:8px;padding:5px 9px;cursor:pointer">X</button>' +
                        '</div>' +
                        '<div style="padding:16px 18px;color:#0f172a;font-size:14px;line-height:1.45;white-space:pre-wrap">' + String(message == null ? '' : message).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>' +
                        '<div style="padding:0 18px 16px 18px">' +
                            '<input type="text" data-erp-prompt-input style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;outline:none" value="' + String(defaultValue == null ? '' : defaultValue).replace(/"/g, '&quot;') + '">' +
                        '</div>' +
                        '<div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;background:#f8fafc;border-top:1px solid #e2e8f0">' +
                            '<button type="button" data-erp-prompt-cancel style="border:1px solid #cbd5e1;background:#fff;color:#374151;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer">' + String(opts.cancelLabel || 'Cancel') + '</button>' +
                            '<button type="button" data-erp-prompt-ok style="border:0;background:#1d4ed8;color:#fff;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer">' + String(opts.okLabel || 'OK') + '</button>' +
                        '</div>' +
                    '</div>';
                document.body.appendChild(overlay);

                var done = false;
                var input = overlay.querySelector('[data-erp-prompt-input]');

                function finish(value) {
                    if (done) return;
                    done = true;
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    resolve(value);
                }

                overlay.querySelector('[data-erp-prompt-ok]').addEventListener('click', function () {
                    finish(String((input && input.value) || ''));
                });
                overlay.querySelector('[data-erp-prompt-cancel]').addEventListener('click', function () {
                    finish(null);
                });
                overlay.querySelector('[data-erp-prompt-close]').addEventListener('click', function () {
                    finish(null);
                });
                if (input) {
                    setTimeout(function () {
                        input.focus();
                        input.select();
                    }, 0);
                    input.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter') {
                            ev.preventDefault();
                            finish(String(input.value || ''));
                        } else if (ev.key === 'Escape') {
                            ev.preventDefault();
                            finish(null);
                        }
                    });
                }

                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) {
                        // Keep strict modal behavior; outside click does not dismiss.
                        return;
                    }
                });
            });
        };

        window.alert = function (message) {
            window.erpCenterMessage(String(message == null ? '' : message), { title: 'Notification' });
        };
    }

    setupCenterMessageUI();

    // Convert server flash alerts to blocking center messages.
    document.querySelectorAll('.alert').forEach(function (el) {
        var msgNode = el.querySelector('span');
        var message = (msgNode ? msgNode.textContent : el.textContent) || '';
        message = String(message).trim();
        if (message) {
            window.erpCenterMessage(message, {
                title: 'System Message',
                okLabel: 'OK',
                cancelLabel: 'Cancel'
            });
        }
        if (el.isConnected) el.remove();
    });

    // Mobile sidebar toggle
    var toggleBtn = document.getElementById('sidebarToggle');
    var sidebar   = document.querySelector('.sidebar');
    var appShell  = document.querySelector('.app-shell');
    if (toggleBtn && sidebar && appShell) {
        var hoverCollapseTimer = null;
        var isSidebarHovered = !!(sidebar.matches && sidebar.matches(':hover'));

        function closeAllSidebarTabs() {
            document.querySelectorAll('.nav-group.is-open').forEach(function (node) {
                node.classList.remove('is-open');
                var toggle = node.querySelector('.nav-group-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            });

            document.querySelectorAll('.nav-sub-nest.is-open').forEach(function (node) {
                node.classList.remove('is-open');
                var toggle = node.querySelector('.nav-sub-nest-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            });

            localStorage.removeItem('erp_sidebar_open_groups_v1');
            localStorage.removeItem('erp_sidebar_open_nested_v1');
        }

        function isDesktopSidebar() {
            return window.innerWidth > 900;
        }

        function isTypingTarget(el) {
            if (!el) return false;
            var tag = String(el.tagName || '').toLowerCase();
            if (tag === 'textarea' || tag === 'select') return true;
            if (tag === 'input') {
                var type = String(el.type || '').toLowerCase();
                return ['text', 'search', 'number', 'email', 'url', 'tel', 'password'].indexOf(type) !== -1;
            }
            return !!el.closest('[contenteditable="true"]');
        }

        function shouldPauseSidebarAutoCollapse() {
            if (!isDesktopSidebar()) return true;
            return isTypingTarget(document.activeElement);
        }

        var lastSidebarDesktopMode = isDesktopSidebar();

        function cancelDesktopAutoCollapse() {
            if (hoverCollapseTimer) {
                clearTimeout(hoverCollapseTimer);
                hoverCollapseTimer = null;
            }
        }

        function scheduleDesktopAutoCollapse() {
            if (!isDesktopSidebar()) return;
            if (appShell.classList.contains('sidebar-collapsed')) return;
            if (shouldPauseSidebarAutoCollapse()) return;
            if (isSidebarHovered) return;

            cancelDesktopAutoCollapse();
            var delayMs = parseInt(appShell.getAttribute('data-sidebar-collapse-delay-ms') || '1000', 10);
            hoverCollapseTimer = setTimeout(function () {
                appShell.classList.add('sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', '1');
                closeAllSidebarTabs();
                hoverCollapseTimer = null;
            }, delayMs);
        }

        // Desktop: restore saved preference from localStorage.
        // Default to collapsed only if no preference has been set yet.
        if (isDesktopSidebar()) {
            var sidebarPref = localStorage.getItem('erp_sidebar_collapsed');
            if (sidebarPref === null || sidebarPref === '1') {
                appShell.classList.add('sidebar-collapsed');
                closeAllSidebarTabs();
            } else {
                appShell.classList.remove('sidebar-collapsed');
            }
        }

        toggleBtn.addEventListener('click', function () {
            if (!isDesktopSidebar()) {
                sidebar.classList.toggle('is-open');
            } else {
                cancelDesktopAutoCollapse();
                appShell.classList.toggle('sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', appShell.classList.contains('sidebar-collapsed') ? '1' : '0');
                if (appShell.classList.contains('sidebar-collapsed')) {
                    closeAllSidebarTabs();
                } else {
                    // Keep expanded while pointer is inside sidebar; when outside, start configured delay.
                    isSidebarHovered = !!(sidebar.matches && sidebar.matches(':hover'));
                    scheduleDesktopAutoCollapse();
                }
            }
        });

        sidebar.addEventListener('mouseenter', function () {
            isSidebarHovered = true;
            cancelDesktopAutoCollapse();
        });

        sidebar.addEventListener('mouseleave', function () {
            isSidebarHovered = false;
            scheduleDesktopAutoCollapse();
        });

        document.addEventListener('focusin', function () {
            if (shouldPauseSidebarAutoCollapse()) {
                cancelDesktopAutoCollapse();
            }
        });

        document.addEventListener('focusout', function () {
            // Delay a tick so document.activeElement is updated after blur.
            setTimeout(function () {
                if (!shouldPauseSidebarAutoCollapse()) {
                    isSidebarHovered = !!(sidebar.matches && sidebar.matches(':hover'));
                    scheduleDesktopAutoCollapse();
                }
            }, 0);
        });

        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 900
                && sidebar.classList.contains('is-open')
                && !sidebar.contains(e.target)
                && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('is-open');
            }
        });
        window.addEventListener('resize', function () {
            cancelDesktopAutoCollapse();

            var desktopNow = isDesktopSidebar();
            if (desktopNow === lastSidebarDesktopMode) {
                return;
            }
            lastSidebarDesktopMode = desktopNow;

            if (desktopNow) {
                sidebar.classList.remove('is-open');
                if (localStorage.getItem('erp_sidebar_collapsed') === null) {
                    appShell.classList.add('sidebar-collapsed');
                    localStorage.setItem('erp_sidebar_collapsed', '1');
                }
                closeAllSidebarTabs();
            }
        });
    }

    // Topbar controls
    var fullscreenBtn = document.getElementById('topbarFullscreenBtn');

    function isFullscreenActive() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function requestFullscreenSafe() {
        var root = document.documentElement;
        if (root.requestFullscreen) return root.requestFullscreen();
        if (root.webkitRequestFullscreen) {
            root.webkitRequestFullscreen();
            return Promise.resolve();
        }
        return Promise.reject(new Error('Fullscreen API not supported'));
    }

    function exitFullscreenSafe() {
        if (document.exitFullscreen) return document.exitFullscreen();
        if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
            return Promise.resolve();
        }
        return Promise.resolve();
    }

    function syncFullscreenButtonUi() {
        if (!fullscreenBtn) return;
        var icon = fullscreenBtn.querySelector('i');
        var active = isFullscreenActive();
        fullscreenBtn.setAttribute('aria-label', active ? 'Exit Fullscreen' : 'Enter Fullscreen');
        fullscreenBtn.setAttribute('title', active ? 'Exit Fullscreen' : 'Enter Fullscreen');
        if (icon) {
            icon.className = active ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-angle-expand';
        }
    }

    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', function () {
            if (!isFullscreenActive()) {
                requestFullscreenSafe().catch(function () {
                    syncFullscreenButtonUi();
                });
            } else {
                exitFullscreenSafe().catch(function () {});
            }
        });
    }

    document.addEventListener('fullscreenchange', function () {
        syncFullscreenButtonUi();
    });
    document.addEventListener('webkitfullscreenchange', function () {
        syncFullscreenButtonUi();
    });

    syncFullscreenButtonUi();

    function redirectFromButton(btnId) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            var href = btn.getAttribute('data-href');
            if (href) window.location.href = href;
        });
    }
    redirectFromButton('topbarProfileBtn');
    redirectFromButton('topbarPowerBtn');

    // Topbar notifications (department-wise)
    (function () {
        var notifBtn = document.getElementById('topbarNotificationBtn');
        var notifDot = document.getElementById('topbarNotificationDot');
        var notifPanel = document.getElementById('topbarNotificationPanel');
        var notifList = document.getElementById('topbarNotificationList');
        var markAllBtn = document.getElementById('topbarNotifMarkAll');
        if (!notifBtn || !notifDot || !notifPanel || !notifList || !markAllBtn) return;

        var apiBase = notifBtn.getAttribute('data-notif-api') || '';
        if (!apiBase) return;
        var fallbackHref = notifBtn.getAttribute('data-href') || '';

        var departments = (notifBtn.getAttribute('data-notif-departments') || '').trim();
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';
        var pollingTimer = null;
        var pollingMs = 5000;
        var seenUnreadIds = {};
        var firstUnreadFetch = true;

        function escHtml(v) {
            return String(v || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function timeAgo(ts) {
            if (!ts) return '';
            var d = new Date(ts.replace(' ', 'T'));
            if (isNaN(d.getTime())) return '';
            var sec = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000));
            if (sec < 60) return sec + ' sec ago';
            var min = Math.floor(sec / 60);
            if (min < 60) return min + ' min ago';
            var hr = Math.floor(min / 60);
            if (hr < 24) return hr + ' hr ago';
            var day = Math.floor(hr / 24);
            return day + ' day ago';
        }

        function appBasePrefix() {
            var source = apiBase || fallbackHref || window.location.pathname || '';
            var match = source.match(/^(.*?)(?:\/modules\/|$)/i);
            if (!match) return '';
            return match[1] || '';
        }

        function withAppBase(path) {
            var cleanPath = String(path || '').replace(/^\/+/, '');
            var base = appBasePrefix().replace(/\/+$/, '');
            return (base ? base : '') + '/' + cleanPath;
        }

        function buildDeptUrl(dept) {
            if (dept === 'jumbo_slitting') return withAppBase('modules/operators/jumbo/index.php');
            if (dept === 'flexo_printing') return withAppBase('modules/operators/printing/index.php');
            if (dept === 'flatbed') return withAppBase('modules/operators/flatbed/index.php');
            if (dept === 'rotery') return withAppBase('modules/operators/rotery/index.php');
            if (dept === 'barcode') return withAppBase('modules/operators/barcode/index.php');
            if (dept === 'label_slitting') return withAppBase('modules/operators/label-slitting/index.php');
            if (dept === 'packing') return withAppBase('modules/operators/packing/index.php');
            if (dept === 'dispatch') return withAppBase('modules/dispatch/index.php');
            if (dept === 'planning') return withAppBase('modules/planning/index.php');
            return withAppBase('modules/approval/index.php');
        }

        function fetchNotifications(unreadOnly, done) {
            var url = apiBase + '?action=get_notifications&limit=25';
            if (unreadOnly) url += '&unread=1';
            if (departments) url += '&departments=' + encodeURIComponent(departments);

            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (res) {
                    if (!res || !res.ok) return;
                    var unread = parseInt(res.unread_count || 0, 10) || 0;
                    var rows = res.notifications || [];
                    notifDot.style.display = unread > 0 ? 'inline-block' : 'none';
                    if (unreadOnly) {
                        var latest = null;
                        rows.forEach(function (n) {
                            var id = String(n.id || '');
                            if (!id) return;
                            if (!seenUnreadIds[id]) {
                                seenUnreadIds[id] = true;
                                latest = n;
                            }
                        });
                        if (!firstUnreadFetch && latest && typeof window.erpCenterMessage === 'function') {
                            var latestId = parseInt(latest.id || 0, 10) || 0;
                            var latestDept = String(latest.department || '').trim();
                            var latestTarget = String(latest.target_url || '').trim() || buildDeptUrl(latestDept);
                            window.erpCenterMessage(String(latest.message || 'New notification'), {
                                title: String(latest.job_no || latest.department || 'New Notification'),
                                okLabel: 'Open Job Card',
                                cancelLabel: 'Cancel',
                                onOk: function () {
                                    markRead(latestId, function () {
                                        window.location.href = latestTarget;
                                    });
                                }
                            });
                        }
                        firstUnreadFetch = false;
                    }
                    if (typeof done === 'function') done(rows);
                })
                .catch(function () {
                    // Ignore silently when jobs API is not accessible for this role.
                });
        }

        function renderList(items) {
            if (!items || !items.length) {
                notifList.innerHTML = '<div class="np-empty">No notifications</div>';
                return;
            }
            notifList.innerHTML = items.map(function (n) {
                var title = (n.job_no || n.department || 'Notification');
                var targetUrl = String(n.target_url || '');
                return '' +
                    '<div class="np-item" data-nid="' + escHtml(n.id) + '" data-dept="' + escHtml(n.department || '') + '" data-url="' + escHtml(targetUrl) + '">' +
                    '<div class="np-item-title">' + escHtml(title) + '</div>' +
                    '<div class="np-item-msg">' + escHtml(n.message || '') + '</div>' +
                    '<div class="np-item-time">' + escHtml(timeAgo(n.created_at)) + '</div>' +
                    '</div>';
            }).join('');
        }

        function markRead(notificationId, cb) {
            if (!csrfToken) {
                if (typeof cb === 'function') cb();
                return;
            }
            var body = new URLSearchParams();
            body.set('csrf_token', csrfToken);
            if (notificationId) {
                body.set('notification_id', String(notificationId));
            } else if (departments) {
                body.set('departments', departments);
            }
            fetch(apiBase + '?action=mark_notification_read', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).finally(function () {
                if (typeof cb === 'function') cb();
            });
        }

        notifBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var open = notifPanel.style.display === 'block';
            notifPanel.style.display = open ? 'none' : 'block';
            if (!open) {
                fetchNotifications(false, function (rows) { renderList(rows); });
            }
        });

        markAllBtn.addEventListener('click', function () {
            markRead(0, function () {
                fetchNotifications(false, function (rows) { renderList(rows); });
            });
        });

        notifList.addEventListener('click', function (e) {
            var item = e.target.closest('.np-item');
            if (!item) return;
            var nid = parseInt(item.getAttribute('data-nid') || '0', 10) || 0;
            var dept = (item.getAttribute('data-dept') || '').trim();
            var targetUrl = (item.getAttribute('data-url') || '').trim();
            markRead(nid, function () {
                window.location.href = targetUrl || buildDeptUrl(dept);
            });
        });

        document.addEventListener('click', function (e) {
            if (!notifPanel.contains(e.target) && !notifBtn.contains(e.target)) {
                notifPanel.style.display = 'none';
            }
        });

        fetchNotifications(true);
        pollingTimer = setInterval(function () { fetchNotifications(true); }, pollingMs);

        // Browser may throttle timers on background tabs; refresh on focus/visibility for near real-time notifications.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) fetchNotifications(true);
        });
        window.addEventListener('focus', function () {
            fetchNotifications(true);
        });

        window.addEventListener('beforeunload', function () {
            if (pollingTimer) clearInterval(pollingTimer);
        });
    })();

    // Live topbar date and time
    var dateTimeNode = document.getElementById('topbarDateTime');
    function updateDateTime() {
        if (!dateTimeNode) return;
        var now = new Date();
        dateTimeNode.textContent = now.toLocaleString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
    }
    if (dateTimeNode) {
        updateDateTime();
        setInterval(updateDateTime, 1000);
    }

    // Sidebar accordion behavior (multi-open with persisted state)
    var groups = Array.prototype.slice.call(document.querySelectorAll('.nav-group'));
    var accordion = groups.map(function (group) {
        var labelNode = group.querySelector('.nav-group-toggle .nav-item-main span:last-child');
        var key = labelNode ? (labelNode.textContent || '').trim() : '';
        return {
            group: group,
            toggle: group.querySelector('.nav-group-toggle'),
            sub: group.querySelector('.nav-sub'),
            key: key
        };
    }).filter(function (x) { return x.toggle && x.sub; });

    var sidebarGroupsStorageKey = 'erp_sidebar_open_groups_v1';
    var sidebarNestedStorageKey = 'erp_sidebar_open_nested_v1';
    function loadOpenSet(storageKey) {
        try {
            var raw = localStorage.getItem(storageKey);
            var arr = raw ? JSON.parse(raw) : [];
            if (!Array.isArray(arr)) return new Set();
            return new Set(arr.map(function (x) { return String(x); }));
        } catch (e) {
            return new Set();
        }
    }
    function saveOpenSet(storageKey, setObj) {
        try {
            localStorage.setItem(storageKey, JSON.stringify(Array.from(setObj)));
        } catch (e) {}
    }
    var openGroupsSet = loadOpenSet(sidebarGroupsStorageKey);

    // Open the current section if one of its submenu links is active
    var currentPath = window.location.pathname.replace(/\/$/, '');
    var isAdminPlateToolsRoute = currentPath === '/modules/master/plate-data-tools-master.php';
    var isUserPlateToolsRoute = currentPath.indexOf('/modules/plate-tools/') === 0;
    function pathMatches(current, href) {
        if (!href) return false;
        if (current === href) return true;
        return current.indexOf(href + '/') === 0;
    }
    accordion.forEach(function (item) {
        var scope = (item.group.getAttribute('data-nav-scope') || '').trim();
        var hasActiveClass = item.group.querySelector('.nav-sub-item.active');
        var hasPathMatch = Array.prototype.some.call(item.group.querySelectorAll('.nav-sub-item[href]'), function (link) {
            var href = (link.getAttribute('href') || '').replace(/\/$/, '');
            return pathMatches(currentPath, href);
        });

        if (scope === 'plate-tools-user' && isAdminPlateToolsRoute) {
            hasActiveClass = false;
            hasPathMatch = false;
        }
        if (scope === 'plate-tools-admin' && isUserPlateToolsRoute) {
            hasActiveClass = false;
            hasPathMatch = false;
        }

        var shouldOpen = (item.key && openGroupsSet.has(item.key)) || hasActiveClass || hasPathMatch;
        if (scope === 'plate-tools-user' && isAdminPlateToolsRoute) {
            shouldOpen = false;
            if (item.key) openGroupsSet.delete(item.key);
        }
        if (scope === 'plate-tools-admin' && isUserPlateToolsRoute) {
            shouldOpen = false;
            if (item.key) openGroupsSet.delete(item.key);
        }

        if (shouldOpen) {
            item.group.classList.add('is-open');
            item.toggle.setAttribute('aria-expanded', 'true');
        } else {
            item.toggle.setAttribute('aria-expanded', 'false');
        }

        item.toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var wasOpen = item.group.classList.contains('is-open');
            if (wasOpen) {
                item.group.classList.remove('is-open');
                item.toggle.setAttribute('aria-expanded', 'false');
                if (item.key) openGroupsSet.delete(item.key);
            } else {
                item.group.classList.add('is-open');
                item.toggle.setAttribute('aria-expanded', 'true');
                if (item.key) openGroupsSet.add(item.key);
            }
            saveOpenSet(sidebarGroupsStorageKey, openGroupsSet);
        });
    });

    // Nested accordion under Physical Stock Check
    var openNestedSet = loadOpenSet(sidebarNestedStorageKey);
    var nested = Array.prototype.slice.call(document.querySelectorAll('.nav-sub-nest'));

    function directChildByClass(parent, className) {
        if (!parent) return null;
        for (var i = 0; i < parent.children.length; i++) {
            var child = parent.children[i];
            if (child.classList && child.classList.contains(className)) {
                return child;
            }
        }
        return null;
    }

    function nestedNodeKey(node) {
        if (!node) return '';
        var raw = (node.getAttribute('data-nested-key') || '').trim();
        if (raw) return raw;
        var t = directChildByClass(node, 'nav-sub-parent-toggle');
        return t ? (t.textContent || '').trim().replace(/\s+/g, ' ') : '';
    }

    function collapseNestedDescendants(rootNode) {
        if (!rootNode) return;
        var descendants = rootNode.querySelectorAll('.nav-sub-nest.is-open');
        descendants.forEach(function (descNode) {
            descNode.classList.remove('is-open');
            var descToggle = directChildByClass(descNode, 'nav-sub-parent-toggle');
            if (descToggle) descToggle.setAttribute('aria-expanded', 'false');
            var descKey = nestedNodeKey(descNode);
            if (descKey) openNestedSet.delete(descKey);
        });
    }

    nested.forEach(function (node) {
        var toggle = directChildByClass(node, 'nav-sub-parent-toggle');
        var children = directChildByClass(node, 'nav-sub-children');
        if (!toggle || !children) return;
        var nestedKey = nestedNodeKey(node);

        var hasActiveChild = node.querySelector('.nav-sub-item.active') ||
            Array.prototype.some.call(node.querySelectorAll('.nav-sub-item[href]'), function (link) {
                var href = (link.getAttribute('href') || '').replace(/\/$/, '');
                return pathMatches(currentPath, href);
            });

        if (openNestedSet.has(nestedKey) || hasActiveChild) {
            node.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = node.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (isOpen) {
                openNestedSet.add(nestedKey);
                collapseNestedDescendants(node);
            } else {
                collapseNestedDescendants(node);
                openNestedSet.delete(nestedKey);
            }
            saveOpenSet(sidebarNestedStorageKey, openNestedSet);
        });
    });

    // Unified custom confirmation flow (no browser-native confirm popups)
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        if (el.tagName === 'FORM') {
            if (el.dataset.confirmBound === '1') return;
            el.dataset.confirmBound = '1';
            el.addEventListener('submit', function (e) {
                if (el.dataset.confirmBypass === '1') {
                    el.dataset.confirmBypass = '0';
                    return;
                }
                e.preventDefault();
                var msg = el.getAttribute('data-confirm') || 'Are you sure?';
                if (typeof window.showERPConfirm === 'function') {
                    window.showERPConfirm(msg, function () {
                        el.dataset.confirmBypass = '1';
                        el.submit();
                    }, { title: 'Please Confirm', okLabel: 'OK', cancelLabel: 'Cancel' });
                    return;
                }
                el.dataset.confirmBypass = '1';
                el.submit();
            });
            return;
        }

        if (el.dataset.confirmBound === '1') return;
        el.dataset.confirmBound = '1';
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var msg = el.getAttribute('data-confirm') || 'Are you sure?';
            var proceed = function () {
                if (el.tagName === 'A') {
                    var href = el.getAttribute('href');
                    if (href) window.location.href = href;
                    return;
                }
                var form = el.closest('form');
                if (form) {
                    form.dataset.confirmBypass = '1';
                    form.submit();
                }
            };
            if (typeof window.showERPConfirm === 'function') {
                window.showERPConfirm(msg, proceed, { title: 'Please Confirm', okLabel: 'OK', cancelLabel: 'Cancel' });
                return;
            }
            proceed();
        });
    });

    // Auto-calculate SQM when width/length changes
    function calcSQM() {
        var w = parseFloat(document.getElementById('width_mm')  && document.getElementById('width_mm').value)  || 0;
        var l = parseFloat(document.getElementById('length_mtr') && document.getElementById('length_mtr').value) || 0;
        var out = document.getElementById('sqm_display');
        if (out) out.textContent = ((w / 1000) * l).toFixed(2);
        var inp = document.getElementById('sqm');
        if (inp) inp.value = ((w / 1000) * l).toFixed(4);
    }
    ['width_mm','length_mtr'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', calcSQM);
    });

    // Estimate cost calculator
    function calcEstimate() {
        var labelLen  = parseFloat(document.getElementById('label_length_mm')  && document.getElementById('label_length_mm').value)  || 0;
        var labelW    = parseFloat(document.getElementById('label_width_mm')   && document.getElementById('label_width_mm').value)   || 0;
        var qty       = parseInt(document.getElementById('quantity')           && document.getElementById('quantity').value)         || 0;
        var matRate   = parseFloat(document.getElementById('material_rate')    && document.getElementById('material_rate').value)    || 0;
        var printRate = parseFloat(document.getElementById('printing_rate')    && document.getElementById('printing_rate').value)    || 0;
        var margin    = parseFloat(document.getElementById('margin_pct')       && document.getElementById('margin_pct').value)       || 20;
        var waste     = parseFloat(document.getElementById('waste_factor')     && document.getElementById('waste_factor').value)     || 1.15;

        var sqmPerLabel = (labelLen / 1000) * (labelW / 1000);
        var sqmReq      = sqmPerLabel * qty * waste;
        var matCost     = sqmReq * matRate;
        var printCost   = sqmReq * printRate;
        var totalCost   = matCost + printCost;
        var sellPrice   = totalCost * (1 + margin / 100);
        var pricePerPcs = qty > 0 ? sellPrice / qty : 0;

        function setVal(id, val) {
            var el = document.getElementById(id);
            if (el) {
                if (el.tagName === 'INPUT') el.value = val;
                else el.textContent = val;
            }
        }

        setVal('sqm_required',   sqmReq.toFixed(4));
        setVal('sqm_display',    sqmReq.toFixed(2));
        setVal('material_cost',  matCost.toFixed(2));
        setVal('printing_cost',  printCost.toFixed(2));
        setVal('total_cost',     totalCost.toFixed(2));
        setVal('selling_price',  sellPrice.toFixed(2));
        setVal('price_per_pcs_display', pricePerPcs.toFixed(4));
    }

    ['label_length_mm','label_width_mm','quantity','material_rate',
     'printing_rate','margin_pct','waste_factor'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', calcEstimate);
    });

    // Number formatting display (no-op on hidden inputs, only on display spans)
    document.querySelectorAll('[data-format-number]').forEach(function (el) {
        var raw = parseFloat(el.textContent);
        if (!isNaN(raw)) el.textContent = raw.toLocaleString('en-IN', {maximumFractionDigits: 2});
    });

    // Inline search / filter for tables
    var tableSearch = document.getElementById('tableSearch');
    if (tableSearch) {
        tableSearch.addEventListener('input', function () {
            var val = this.value.toLowerCase();
            document.querySelectorAll('[data-table-body] tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        });
    }

    // Plate Data & Tools Master: nested parent/child toggle
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ptm-toggle]');
        if (!btn) return;

        var node = btn.nextElementSibling;
        if (!node || !node.classList || !node.classList.contains('ptm-tree-node')) return;

        e.preventDefault();
        var isOpen = node.classList.toggle('open');
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

        if (isOpen) {
            var openDescendants = node.querySelectorAll('.ptm-tree-node.open');
            openDescendants.forEach(function (childNode) {
                childNode.classList.remove('open');
                var childToggle = childNode.previousElementSibling;
                if (childToggle && childToggle.hasAttribute('data-ptm-toggle')) {
                    childToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });

}());
