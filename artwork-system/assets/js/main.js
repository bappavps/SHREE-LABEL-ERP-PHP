/**
 * Main JS for Dashboard
 */

document.addEventListener('DOMContentLoaded', function() {
        function base64ToBytes(base64) {
            const raw = atob(base64 || '');
            const out = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i += 1) {
                out[i] = raw.charCodeAt(i);
            }
            return out;
        }

    const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js';
    const PDFJS_WORKER_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

    function loadPdfJs() {
        if (window.pdfjsLib) {
            return Promise.resolve(window.pdfjsLib);
        }

        return new Promise(function(resolve, reject) {
            const existing = document.querySelector('script[data-pdfjs="dashboard-thumbs"]');
            if (existing) {
                if (window.pdfjsLib) {
                    resolve(window.pdfjsLib);
                    return;
                }
                existing.addEventListener('load', function() {
                    resolve(window.pdfjsLib);
                });
                existing.addEventListener('error', reject);
                return;
            }

            const script = document.createElement('script');
            script.src = PDFJS_CDN;
            script.async = true;
            script.dataset.pdfjs = 'dashboard-thumbs';
            script.onload = function() {
                resolve(window.pdfjsLib);
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    async function renderPdfThumb(canvas) {
        const src = canvas.dataset.pdfSrc;
        if (!src) {
            return;
        }

        const pdfjs = await loadPdfJs();
        if (!pdfjs) {
            return;
        }

        pdfjs.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_CDN;

        const response = await fetch(src, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error('Failed to load PDF bytes');
        }
        const payload = await response.json();
        if (!payload || payload.status !== 'success' || !payload.data || !payload.data.bytes) {
            throw new Error('Invalid preview payload');
        }
        const bytes = base64ToBytes(payload.data.bytes);

        const loadingTask = pdfjs.getDocument({ data: bytes });
        const pdfDoc = await loadingTask.promise;
        const page = await pdfDoc.getPage(1);
        const initialViewport = page.getViewport({ scale: 1 });
        const targetWidth = Math.max(canvas.parentElement ? canvas.parentElement.clientWidth : 0, 280);
        const scale = targetWidth / initialViewport.width;
        const viewport = page.getViewport({ scale: scale });
        const context = canvas.getContext('2d', { alpha: false });

        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.style.width = '100%';
        canvas.style.height = '100%';

        await page.render({
            canvasContext: context,
            viewport: viewport
        }).promise;
    }

    function initPdfThumbs() {
        const canvases = document.querySelectorAll('.pdf-thumb-canvas[data-pdf-src]');
        if (!canvases.length) {
            return;
        }

        canvases.forEach(function(canvas) {
            const parent = canvas.closest('.project-thumb');
            let fallbackIcon = null;

            if (parent) {
                fallbackIcon = parent.querySelector('.pdf-thumb-fallback');
                if (!fallbackIcon) {
                    fallbackIcon = document.createElement('i');
                    fallbackIcon.className = 'fas fa-file-pdf pdf-thumb-fallback';
                    parent.appendChild(fallbackIcon);
                }
            }

            renderPdfThumb(canvas).catch(function() {
                if (parent) {
                    canvas.style.display = 'none';
                    if (fallbackIcon) {
                        fallbackIcon.style.display = 'grid';
                    }
                }
            }).then(function() {
                if (fallbackIcon) {
                    fallbackIcon.style.display = 'none';
                }
            });
        });
    }

    initPdfThumbs();

    // Notification Polling
    const notifBadge = document.getElementById('notif-count');
    const notifBell = document.getElementById('notif-bell');
    const notifDropdown = document.getElementById('notif-dropdown');
    const notifList = document.getElementById('notif-list');
    const notifWrap = document.getElementById('notification-wrap');
    let notificationItems = [];

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatTime(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value.replace(' ', 'T') + 'Z');
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString();
    }

    function renderNotifications() {
        if (!notifList) {
            return;
        }

        if (!Array.isArray(notificationItems) || notificationItems.length === 0) {
            notifList.innerHTML = '<div class="notification-empty">No new notifications</div>';
            return;
        }

        notifList.innerHTML = notificationItems.map(function(item) {
            const msg = escapeHtml(item.message || 'Notification');
            const time = escapeHtml(formatTime(item.created_at || ''));
            return '<div class="notification-item">' +
                '<p class="notification-message">' + msg + '</p>' +
                '<p class="notification-time">' + time + '</p>' +
            '</div>';
        }).join('');
    }
    
    function checkNotifications() {
        fetch('../api/get-notifications.php')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const count = Number((data.data || {}).unread_count || 0);
                    notificationItems = Array.isArray((data.data || {}).items) ? data.data.items : [];
                    if (notifBadge) {
                        notifBadge.innerText = String(count);
                        notifBadge.style.display = count > 0 ? 'block' : 'none';
                    }
                    renderNotifications();
                }
            })
            .catch(err => console.error('Notification error:', err));
    }

    if (notifBadge) {
        checkNotifications();
        setInterval(checkNotifications, 5000);
    }

    if (notifBell && notifDropdown) {
        notifBell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = notifDropdown.style.display === 'block';
            notifDropdown.style.display = isOpen ? 'none' : 'block';
            notifBell.classList.toggle('active', !isOpen);
            if (!isOpen) {
                renderNotifications();
            }
        });

        document.addEventListener('click', function(e) {
            if (!notifWrap || notifWrap.contains(e.target)) {
                return;
            }
            notifDropdown.style.display = 'none';
            notifBell.classList.remove('active');
        });
    }

    // Dropdown/Menu toggles could go here
});
