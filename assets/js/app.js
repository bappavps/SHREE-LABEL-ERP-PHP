// ============================================================
// Shree Label ERP — Global UI Script
// ============================================================
(function () {
    'use strict';

    // Auto-dismiss flash alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function (el) {
        var btn = el.querySelector('.alert-close');
        if (btn) {
            btn.addEventListener('click', function () { el.remove(); });
        }
        setTimeout(function () { if (el.isConnected) el.remove(); }, 5000);
    });

    // Mobile sidebar toggle
    var toggleBtn = document.getElementById('sidebarToggle');
    var sidebar   = document.querySelector('.sidebar');
    var appShell  = document.querySelector('.app-shell');
    if (toggleBtn && sidebar && appShell) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 900) {
                sidebar.classList.toggle('is-open');
            } else {
                appShell.classList.toggle('sidebar-collapsed');
            }
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
            if (window.innerWidth > 900) {
                sidebar.classList.remove('is-open');
            }
        });
    }

    // Topbar controls
    var fullscreenBtn = document.getElementById('topbarFullscreenBtn');
    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', function () {
            if (!document.fullscreenElement) {
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen();
                }
            } else if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        });
    }

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

    // Sidebar accordion behavior
    var groups = Array.prototype.slice.call(document.querySelectorAll('.nav-group'));
    var accordion = groups.map(function (group) {
        return {
            group: group,
            toggle: group.querySelector('.nav-group-toggle'),
            sub: group.querySelector('.nav-sub')
        };
    }).filter(function (x) { return x.toggle && x.sub; });

    function closeAllAccordion() {
        accordion.forEach(function (item) {
            item.group.classList.remove('is-open');
            item.toggle.setAttribute('aria-expanded', 'false');
        });
    }

    // Open the current section if one of its submenu links is active
    var currentPath = window.location.pathname.replace(/\/$/, '');
    accordion.forEach(function (item) {
        var hasActiveClass = item.group.querySelector('.nav-sub-item.active');
        var hasPathMatch = Array.prototype.some.call(item.group.querySelectorAll('.nav-sub-item[href]'), function (link) {
            var href = (link.getAttribute('href') || '').replace(/\/$/, '');
            return href && currentPath.indexOf(href) !== -1;
        });

        if (hasActiveClass || hasPathMatch) {
            item.group.classList.add('is-open');
            item.toggle.setAttribute('aria-expanded', 'true');
        } else {
            item.toggle.setAttribute('aria-expanded', 'false');
        }

        item.toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var wasOpen = item.group.classList.contains('is-open');
            closeAllAccordion();
            if (!wasOpen) {
                item.group.classList.add('is-open');
                item.toggle.setAttribute('aria-expanded', 'true');
            }
        });
    });

    // Nested accordion under Physical Stock Check
    var nested = Array.prototype.slice.call(document.querySelectorAll('.nav-sub-nest'));
    nested.forEach(function (node) {
        var toggle = node.querySelector('.nav-sub-parent-toggle');
        var children = node.querySelector('.nav-sub-children');
        if (!toggle || !children) return;

        var hasActiveChild = node.querySelector('.nav-sub-item.active') ||
            Array.prototype.some.call(node.querySelectorAll('.nav-sub-item[href]'), function (link) {
                var href = (link.getAttribute('href') || '').replace(/\/$/, '');
                return href && currentPath.indexOf(href) !== -1;
            });

        if (hasActiveChild) {
            node.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = node.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    // Confirm delete
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
                e.preventDefault();
            }
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

}());
