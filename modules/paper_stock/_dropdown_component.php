
<?php
/**
 * Reusable modern dropdown component for Paper Stock Add/Edit pages.
 * Include this file AFTER the page header but before </body>.
 * Provides: initStatusDropdown(), initSearchDropdown()
 */
?>
<style>
/* ── Modern Dropdown Base ───────────────────────────────────── */
.erp-dd{position:relative;width:100%}

/* ── Status dropdown trigger (click-to-open, non-editable) ── */
.erp-dd-trigger{
  display:flex;align-items:center;gap:8px;
  width:100%;height:44px;padding:0 14px;
  border:2px solid var(--border,#e5e7eb);border-radius:12px;
  background:#fff;cursor:pointer;font-size:14px;font-weight:600;
  color:var(--text-main,#374151);transition:border-color .15s,box-shadow .15s;
  user-select:none;
}
.erp-dd-trigger:hover{border-color:#d1d5db}
.erp-dd-trigger:focus,.erp-dd-trigger.open{
  border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.15);outline:none;
}
.erp-dd-trigger .dd-placeholder{color:#9ca3af;font-weight:400}
.erp-dd-trigger .dd-arrow,.erp-dd-input-wrap .dd-arrow{
  margin-left:auto;width:18px;height:18px;flex-shrink:0;
  transition:transform .2s;opacity:.4;pointer-events:none;
}
.erp-dd-trigger.open .dd-arrow,.erp-dd-input-wrap.open .dd-arrow{transform:rotate(180deg)}
.erp-dd-trigger .dd-dot{
  width:9px;height:9px;border-radius:50%;flex-shrink:0;
}

/* ── Editable Search Input Trigger (Company / Type) ────────── */
.erp-dd-input-wrap{
  position:relative;display:flex;align-items:center;width:100%;
}
.erp-dd-input{
  width:100%;height:44px;padding:0 36px 0 14px;
  border:2px solid var(--border,#e5e7eb);border-radius:12px;
  background:#fff;font-size:14px;font-weight:600;
  color:var(--text-main,#374151);transition:border-color .15s,box-shadow .15s;
  outline:none;
}
.erp-dd-input::placeholder{color:#9ca3af;font-weight:400}
.erp-dd-input:hover{border-color:#d1d5db}
.erp-dd-input:focus,.erp-dd-input.open{
  border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.15);
}
.erp-dd-input-wrap .dd-arrow{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
}
.erp-dd-input-wrap.open .dd-arrow{transform:translateY(-50%) rotate(180deg)}

/* ── Dropdown Panel (Dark Theme) ─────────────────────────────── */
.erp-dd-panel{
  position:absolute;top:calc(100% + 6px);left:0;right:0;
  background:#111;border:1px solid #2a2a2a;border-radius:14px;
  box-shadow:0 20px 50px rgba(0,0,0,.45);
  z-index:9999;overflow:hidden;
  opacity:0;transform:translateY(-6px) scale(.98);
  pointer-events:none;transition:opacity .18s,transform .18s;
  max-height:0;
}
.erp-dd-panel.open{
  opacity:1;transform:translateY(0) scale(1);
  pointer-events:auto;max-height:340px;
}

/* ── List items ─────────────────────────────────────── */
.erp-dd-list{
  overflow-y:auto;max-height:280px;padding:6px 6px 8px;
  scrollbar-width:thin;scrollbar-color:#333 transparent;
}
.erp-dd-list::-webkit-scrollbar{width:5px}
.erp-dd-list::-webkit-scrollbar-track{background:transparent}
.erp-dd-list::-webkit-scrollbar-thumb{background:#333;border-radius:4px}

.erp-dd-item{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:10px;cursor:pointer;
  color:#ccc;font-size:13px;font-weight:500;
  transition:background .12s,color .12s;
}
.erp-dd-item:hover{background:#1e1e1e;color:#fff}
.erp-dd-item.active{background:#1a2e1a;color:#22c55e}
.erp-dd-item.active .dd-check{display:block}
.erp-dd-item .dd-dot{
  width:9px;height:9px;border-radius:50%;flex-shrink:0;
}
.erp-dd-item .dd-check{
  display:none;margin-left:auto;width:16px;height:16px;color:#22c55e;flex-shrink:0;
}
.erp-dd-item .dd-label{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── No results message ──────────────────────────── */
.erp-dd-empty{padding:14px 12px;color:#666;font-size:12px;text-align:center;font-style:italic}

/* ── Separator ───────────────────────────────────── */
.erp-dd-sep{height:1px;background:#222;margin:4px 12px}

/* ── Custom stage input row ──────────────────────── */
.erp-dd-custom{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:10px;cursor:pointer;
  color:#f97316;font-size:13px;font-weight:600;
  transition:background .12s;
}
.erp-dd-custom:hover{background:#1e1e1e}
.erp-dd-custom svg{width:16px;height:16px;flex-shrink:0}

.erp-dd-custom-input-wrap{
  padding:6px 12px 10px;display:none;
}
.erp-dd-custom-input-wrap.visible{display:block}
.erp-dd-custom-input{
  width:100%;height:38px;padding:0 12px;
  background:#0a1a0a;border:2px solid rgba(249,115,22,.3);border-radius:10px;
  color:#fff;font-size:13px;font-weight:600;outline:none;
}
.erp-dd-custom-input:focus{border-color:#f97316}

/* ── Responsive for mobile ───────────────────────── */
@media(max-width:600px){
  .erp-dd-panel{position:fixed;top:auto;bottom:0;left:0;right:0;border-radius:18px 18px 0 0;max-height:60vh}
  .erp-dd-panel.open{max-height:60vh}
}
</style>

<script>
(function(){
'use strict';

var statusColors = {
  'Main':          '#9333ea',
  'Job Assign':    '#ef4444',
  'Stock':         '#16a34a',
  'Consume':       '#94a3b8'
};

function getStatusColor(label){
  if (statusColors[label]) return statusColors[label];
  var n = String(label || '').toLowerCase();
  if (n.indexOf('slit') !== -1 || n.indexOf('jumbo') !== -1) return '#f97316';
  if (n.indexOf('print') !== -1) return '#06b6d4';
  if (n.indexOf('die') !== -1) return '#0ea5a4';
  if (n.indexOf('bar') !== -1 || n.indexOf('label') !== -1) return '#7c3aed';
  if (n.indexOf('pack') !== -1 || n.indexOf('dispatch') !== -1) return '#0f766e';
  return '#6b7280';
}

var svgArrow = '<svg class="dd-arrow" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg>';
var svgCheck = '<svg class="dd-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>';
var svgPlus = '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>';

function closeAllDropdowns(except){
  document.querySelectorAll('.erp-dd-panel.open').forEach(function(p){
    if(except && p === except) return;
    p.classList.remove('open');
    var wrap = p.closest('.erp-dd');
    if(!wrap) return;
    var trig = wrap.querySelector('.erp-dd-trigger');
    if(trig) trig.classList.remove('open');
    var iw = wrap.querySelector('.erp-dd-input-wrap');
    if(iw) iw.classList.remove('open');
    var inp = wrap.querySelector('.erp-dd-input');
    if(inp) inp.classList.remove('open');
  });
}

document.addEventListener('mousedown', function(e){
  if(!e.target.closest('.erp-dd')) closeAllDropdowns();
});
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeAllDropdowns();
});

/* ──────────────────────────────────────────────────────────────
   initStatusDropdown(container, hiddenInput, customInput, initialValue, presetList, allowCustom)
   ────────────────────────────────────────────────────────────── */
window.initStatusDropdown = function(container, hiddenInput, customInput, initialVal, presetList, allowCustom){
  var presets = Array.isArray(presetList) && presetList.length
    ? presetList.slice()
    : ['Main','Job Assign','Stock','Consume'];
  var customAllowed = (typeof allowCustom === 'boolean') ? allowCustom : true;
  var current = initialVal || (presets[0] || 'Main');
  var isCustom = presets.indexOf(current) === -1 && current !== '';
  if (isCustom && !customAllowed) {
    current = presets[0] || 'Main';
    isCustom = false;
  }

  var trigger = document.createElement('div');
  trigger.className = 'erp-dd-trigger';
  trigger.tabIndex = 0;
  trigger.setAttribute('role','combobox');
  trigger.setAttribute('aria-expanded','false');

  function setTriggerDisplay(val, color){
    var dot = color ? '<span class="dd-dot" style="background:'+color+'"></span>' : '';
    trigger.innerHTML = dot + '<span class="dd-label">'+(val||'<span class="dd-placeholder">Select Status</span>')+'</span>' + svgArrow;
  }

  var panel = document.createElement('div');
  panel.className = 'erp-dd-panel';
  var listHtml = '<div class="erp-dd-list">';
  presets.forEach(function(s){
    var c = getStatusColor(s);
    listHtml += '<div class="erp-dd-item'+((!isCustom && current===s)?' active':'')+'" data-value="'+s+'">';
    listHtml += '<span class="dd-dot" style="background:'+c+'"></span>';
    listHtml += '<span class="dd-label">'+s+'</span>';
    listHtml += svgCheck;
    listHtml += '</div>';
  });
  listHtml += '</div>';
  if (customAllowed) {
    listHtml += '<div class="erp-dd-sep"></div>';
    listHtml += '<div class="erp-dd-custom" data-action="custom">'+svgPlus+' Add Custom Stage...</div>';
    listHtml += '<div class="erp-dd-custom-input-wrap'+(isCustom?' visible':'')+'"><input class="erp-dd-custom-input" placeholder="Type custom stage name…" value="'+(isCustom?current.replace(/"/g,'&quot;'):'')+'" /></div>';
  }
  panel.innerHTML = listHtml;

  container.innerHTML = '';
  container.classList.add('erp-dd');
  container.appendChild(trigger);
  container.appendChild(panel);

  if(isCustom && customAllowed){
    setTriggerDisplay(current, '#f97316');
    hiddenInput.value = '__custom__';
    customInput.value = current;
  } else {
    setTriggerDisplay(current, getStatusColor(current));
    hiddenInput.value = current;
    customInput.value = '';
  }

  trigger.addEventListener('click', function(e){
    e.stopPropagation();
    var opening = !panel.classList.contains('open');
    closeAllDropdowns(panel);
    panel.classList.toggle('open', opening);
    trigger.classList.toggle('open', opening);
    trigger.setAttribute('aria-expanded', opening);
  });

  panel.querySelectorAll('.erp-dd-item').forEach(function(item){
    item.addEventListener('click', function(){
      var val = item.dataset.value;
      panel.querySelectorAll('.erp-dd-item').forEach(function(i){i.classList.remove('active')});
      item.classList.add('active');
      hiddenInput.value = val;
      customInput.value = '';
      setTriggerDisplay(val, getStatusColor(val));
      var customWrap = panel.querySelector('.erp-dd-custom-input-wrap');
      if (customWrap) customWrap.classList.remove('visible');
      panel.classList.remove('open');
      trigger.classList.remove('open');
    });
  });

  if (customAllowed) {
    panel.querySelector('[data-action="custom"]').addEventListener('click', function(){
      var wrap = panel.querySelector('.erp-dd-custom-input-wrap');
      wrap.classList.add('visible');
      var inp = wrap.querySelector('.erp-dd-custom-input');
      inp.focus();
      panel.querySelectorAll('.erp-dd-item').forEach(function(i){i.classList.remove('active')});
      hiddenInput.value = '__custom__';
    });

    var cInput = panel.querySelector('.erp-dd-custom-input');
    cInput.addEventListener('input', function(){
      customInput.value = this.value;
      setTriggerDisplay(this.value || 'Custom…', '#f97316');
    });
    cInput.addEventListener('keydown', function(e){
      if(e.key === 'Enter'){
        e.preventDefault();
        if(this.value.trim()){
          panel.classList.remove('open');
          trigger.classList.remove('open');
        }
      }
    });
  }

  trigger.addEventListener('keydown', function(e){
    if(e.key === 'Enter' || e.key === ' '){
      e.preventDefault();
      trigger.click();
    }
  });
};

/* ──────────────────────────────────────────────────────────────
   initSearchDropdown(container, hiddenInput, options, placeholder, strict)
   — Editable text input with dark suggestions panel.
   — User can type to filter; selecting from list commits the value.
   — strict=true: value MUST be selected from list; blur clears if not matched.
   — strict=false (default): free text also accepted.
   ────────────────────────────────────────────────────────────── */
window.initSearchDropdown = function(container, hiddenInput, options, placeholder, strict){
  strict = strict === true;
  var current = hiddenInput.value || '';

  // Build wrapper with editable input + arrow icon
  var wrap = document.createElement('div');
  wrap.className = 'erp-dd-input-wrap';

  var input = document.createElement('input');
  input.type = 'text';
  input.className = 'erp-dd-input';
  input.placeholder = placeholder || 'Type or search…';
  input.value = current;
  input.autocomplete = 'off';

  var arrowEl = document.createElement('span');
  arrowEl.innerHTML = svgArrow;
  arrowEl.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);pointer-events:none;display:flex';

  wrap.appendChild(input);
  wrap.appendChild(arrowEl);

  // Build suggestions panel (dark theme, no search bar — the input IS the search)
  var panel = document.createElement('div');
  panel.className = 'erp-dd-panel';

  function buildList(){
    var q = input.value.toLowerCase().trim();
    var html = '<div class="erp-dd-list">';
    var visibleCount = 0;
    options.forEach(function(o){
      var val = typeof o === 'string' ? o : o.value;
      var lbl = typeof o === 'string' ? o : (o.label || o.value);
      var matchQ = !q || val.toLowerCase().indexOf(q) !== -1;
      if(!matchQ) return;
      var isActive = val === input.value;
      html += '<div class="erp-dd-item'+(isActive?' active':'')+'" data-value="'+val.replace(/"/g,'&quot;')+'">';
      html += '<span class="dd-label">'+lbl+'</span>';
      html += svgCheck;
      html += '</div>';
      visibleCount++;
    });
    if(visibleCount === 0){
      html += '<div class="erp-dd-empty">'+(q ? (strict ? 'No matches in master or stock list.' : 'No matches — your typed value will be used') : 'No options available')+'</div>';
    }
    html += '</div>';
    panel.innerHTML = html;

    // Bind click events on new items
    panel.querySelectorAll('.erp-dd-item').forEach(function(item){
      item.addEventListener('mousedown', function(e){
        e.preventDefault(); // prevent input blur
        var val = item.dataset.value;
        input.value = val;
        hiddenInput.value = val;
        closePanel();
        hiddenInput.dispatchEvent(new Event('change', {bubbles:true}));
      });
    });
  }

  function openPanel(){
    if(panel.classList.contains('open')) return;
    closeAllDropdowns(panel);
    buildList();
    panel.classList.add('open');
    wrap.classList.add('open');
    input.classList.add('open');
  }

  function closePanel(){
    panel.classList.remove('open');
    wrap.classList.remove('open');
    input.classList.remove('open');
  }

  container.innerHTML = '';
  container.classList.add('erp-dd');
  container.appendChild(wrap);
  container.appendChild(panel);

  // Sync hidden input whenever user types (non-strict); strict mode requires selection
  input.addEventListener('input', function(){
    if(!strict){
      hiddenInput.value = this.value;
      hiddenInput.dispatchEvent(new Event('change', {bubbles:true}));
    } else {
      hiddenInput.value = '';
    }
    if(panel.classList.contains('open')){
      buildList();
    } else {
      openPanel();
    }
  });

  // Open on focus / click
  input.addEventListener('focus', function(){ openPanel(); });
  input.addEventListener('click', function(e){
    e.stopPropagation();
    openPanel();
  });

  // Close on blur (small delay to allow item mousedown to fire first)
  input.addEventListener('blur', function(){
    setTimeout(function(){
      closePanel();
      if(strict){
        var typed = input.value.trim();
        var matched = false;
        for(var i = 0; i < options.length; i++){
          var val = typeof options[i] === 'string' ? options[i] : options[i].value;
          if(val === typed){ matched = true; break; }
        }
        if(!matched){ input.value = ''; hiddenInput.value = ''; }
      }
    }, 150);
  });

  // Keyboard: Enter to close, Escape to close, Arrow navigation
  input.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      closePanel();
      return;
    }
    if(e.key === 'Enter'){
      e.preventDefault();
      // If panel is open, select first highlighted or just close
      var activeItem = panel.querySelector('.erp-dd-item.active') || panel.querySelector('.erp-dd-item');
      if(activeItem && panel.classList.contains('open')){
        input.value = activeItem.dataset.value;
        hiddenInput.value = activeItem.dataset.value;
        hiddenInput.dispatchEvent(new Event('change', {bubbles:true}));
      }
      closePanel();
      return;
    }
    if(e.key === 'ArrowDown' || e.key === 'ArrowUp'){
      e.preventDefault();
      if(!panel.classList.contains('open')){ openPanel(); return; }
      var items = panel.querySelectorAll('.erp-dd-item');
      if(!items.length) return;
      var idx = -1;
      items.forEach(function(it, i){ if(it.classList.contains('active')) idx = i; });
      items.forEach(function(it){ it.classList.remove('active'); });
      if(e.key === 'ArrowDown') idx = (idx + 1) % items.length;
      else idx = idx <= 0 ? items.length - 1 : idx - 1;
      items[idx].classList.add('active');
      items[idx].scrollIntoView({block:'nearest'});
    }
  });
};

})();
</script>
