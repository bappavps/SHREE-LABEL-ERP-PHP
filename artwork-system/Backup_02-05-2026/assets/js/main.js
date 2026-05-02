/**
 * Main JS for Dashboard
 */

document.addEventListener('DOMContentLoaded', function() {
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
