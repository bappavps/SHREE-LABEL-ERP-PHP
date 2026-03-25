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
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('is-open');
        });
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('is-open')
                && !sidebar.contains(e.target)
                && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('is-open');
            }
        });
    }

    // Active nav item highlight based on current URL
    var currentPath = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(function (item) {
        var href = item.getAttribute('href') || item.getAttribute('data-href');
        if (href && currentPath.includes(href.replace(/\/index\.php$/, '').replace(/\.php$/, ''))) {
            item.classList.add('active');
        }
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
