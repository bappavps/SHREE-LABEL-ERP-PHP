/* ============================================================
   Standalone AI Agent Add-On Module — JS Controller
   ERP Master System — Interactive Chat & Tool Telemetry
   LOCAL USE ONLY
   ============================================================ */

(function () {
  'use strict';

  var chatBody = document.getElementById('aiChatBody');
  var chatInput = document.getElementById('aiChatInput');
  var sendBtn = document.getElementById('aiSendBtn');
  var chipButtons = document.querySelectorAll('.ai-chip-btn');
  var apiStatusEl = document.getElementById('aiTelemetryApiStatus');
  var lastToolEl = document.getElementById('aiTelemetryLastTool');
  var totalQueriesEl = document.getElementById('aiTelemetryTotalQueries');
  var clearBtn = document.getElementById('aiClearChatBtn');

  var state = {
    totalQueries: 0,
    isProcessing: false
  };

  function appendMessage(text, sender, toolUsed, suggestions, commandType) {
    if (!chatBody) return;

    var msgDiv = document.createElement('div');
    msgDiv.className = 'ai-msg ' + sender;
    if (commandType) {
      msgDiv.className += ' ai-cmd-' + commandType;
    }

    var avatarDiv = document.createElement('div');
    avatarDiv.className = 'ai-msg-avatar';
    avatarDiv.innerHTML = sender === 'user' ? '<i class="bi bi-person"></i>' : '<i class="bi bi-robot"></i>';

    var contentDiv = document.createElement('div');
    contentDiv.className = 'ai-msg-content';

    var bubbleHtml = '';
    if (toolUsed && sender === 'assistant') {
      bubbleHtml += '<div class="ai-tool-call-tag"><i class="bi bi-lightning-charge-fill"></i> Executed ERP Tool: ' + escapeHtml(toolUsed) + '</div>';
    }

    // Render thinking indicator HTML directly (don't escape)
    if (text.indexOf('ai-thinking-indicator') !== -1) {
      bubbleHtml += text;
    } else {
      var formatted = formatMarkdown(text);
      bubbleHtml += formatted;
    }

    // Suggestions chips rendering
    if (sender === 'assistant' && suggestions && suggestions.length > 0) {
      bubbleHtml += '<div class="ai-suggestion-box">';
      bubbleHtml += '<div class="ai-suggestion-title"><i class="bi bi-lightbulb-fill" style="color:#f59e0b"></i> Suggested Questions:</div>';
      bubbleHtml += '<div class="ai-suggestion-chips">';
      for (var s = 0; s < suggestions.length; s++) {
        var sug = suggestions[s];
        bubbleHtml += '<button type="button" class="ai-suggestion-chip" data-prompt="' + escapeHtml(sug) + '"><i class="bi bi-chat-left-text"></i> ' + escapeHtml(sug) + '</button>';
      }
      bubbleHtml += '</div></div>';
    }

    var allHtml = '';

    // Add footer with meta + copy (skip thinking indicator)
    if (text.indexOf('ai-thinking-indicator') !== -1) {
      allHtml = bubbleHtml;
    } else {
      allHtml = '<div class="ai-msg-bubble">' + bubbleHtml + '</div>';

      var now = new Date();
      var timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      var label = sender === 'user' ? 'You' : 'AI Copilot';

      allHtml += '<div class="ai-msg-footer">';
      allHtml += '<span class="msg-meta">' + label + ' \u00B7 ' + timeStr + '</span>';
      allHtml += '<button class="ai-btn-copy-msg" title="Copy"><i class="bi bi-clipboard"></i></button>';
      if (sender === 'user') {
        allHtml += '<button class="ai-btn-copy-msg ai-btn-edit-msg" title="Edit Prompt"><i class="bi bi-pencil-square"></i></button>';
      }
      allHtml += '<button class="ai-btn-copy-msg ai-btn-regen-msg" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>';
      allHtml += '</div>';
    }

    contentDiv.innerHTML = allHtml;

    msgDiv.appendChild(avatarDiv);
    msgDiv.appendChild(contentDiv);
    chatBody.appendChild(msgDiv);
    
    chatBody.scrollTop = chatBody.scrollHeight;
  }

  // Event delegation on chatBody for suggestion chips, copy, edit & regenerate buttons
  if (chatBody) {
    chatBody.addEventListener('click', function(e) {
      var chip = e.target.closest('.ai-suggestion-chip');
      if (chip) {
        var p = chip.getAttribute('data-prompt');
        if (p && !state.isProcessing) {
          handleSend(p);
          return;
        }
      }
      var editBtn = e.target.closest('.ai-btn-edit-msg');
      if (editBtn) {
        e.stopPropagation();
        var msgDiv = editBtn.closest('.ai-msg');
        var bubble = msgDiv.querySelector('.ai-msg-bubble');
        if (!bubble || msgDiv.querySelector('.msg-edit-box')) return;

        var originalText = (bubble.innerText || bubble.textContent).trim();
        var originalHtml = bubble.innerHTML;

        bubble.innerHTML = '<div class="msg-edit-box" style="display:flex;flex-direction:column;gap:8px;width:100%;margin-top:4px;">'
          + '<textarea class="msg-edit-input" style="width:100%;min-height:55px;background:rgba(15,23,42,0.85);border:1px solid #3b82f6;color:#fff;border-radius:8px;padding:6px 8px;font-size:13px;resize:vertical;outline:none;font-family:inherit;">' + escapeHtml(originalText) + '</textarea>'
          + '<div style="display:flex;gap:6px;justify-content:flex-end;">'
          + '<button type="button" class="btn-cancel-edit" style="background:rgba(148,163,184,0.2);border:none;color:#cbd5e1;padding:3px 8px;border-radius:6px;font-size:12px;cursor:pointer;">Cancel</button>'
          + '<button type="button" class="btn-save-edit" style="background:#3b82f6;border:none;color:#fff;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Save & Regenerate</button>'
          + '</div></div>';

        var textarea = bubble.querySelector('.msg-edit-input');
        if (textarea) {
          textarea.focus();
          textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        }

        var cancelBtn = bubble.querySelector('.btn-cancel-edit');
        if (cancelBtn) { cancelBtn.onclick = function() { bubble.innerHTML = originalHtml; }; }

        var saveBtn = bubble.querySelector('.btn-save-edit');
        if (saveBtn) {
          saveBtn.onclick = function() {
            var newText = textarea.value.trim();
            if (newText && !state.isProcessing) {
              bubble.innerHTML = escapeHtml(newText);
              handleSend(newText);
            } else {
              bubble.innerHTML = originalHtml;
            }
          };
        }
        return;
      }
      var copyBtn = e.target.closest('.ai-btn-copy-msg');
      if (copyBtn && !copyBtn.classList.contains('ai-btn-regen-msg') && !copyBtn.classList.contains('ai-btn-edit-msg')) {
        e.stopPropagation();
        var bubbleEl = copyBtn.closest('.ai-msg-content').querySelector('.ai-msg-bubble');
        var text = bubbleEl.innerText || bubbleEl.textContent;
        navigator.clipboard.writeText(text).then(function() {
          copyBtn.innerHTML = '<i class="bi bi-check-lg"></i>';
          copyBtn.classList.add('copied');
          setTimeout(function() {
            copyBtn.innerHTML = '<i class="bi bi-clipboard"></i>';
            copyBtn.classList.remove('copied');
          }, 1500);
        });
        return;
      }
      var regenBtn = e.target.closest('.ai-btn-regen-msg');
      if (regenBtn) {
        e.stopPropagation();
        var msgDiv = regenBtn.closest('.ai-msg');
        var promptText = '';
        if (msgDiv.classList.contains('user')) {
          var bubbleEl = msgDiv.querySelector('.ai-msg-bubble');
          promptText = (bubbleEl ? (bubbleEl.innerText || bubbleEl.textContent) : '').trim();
        } else {
          var prev = msgDiv.previousElementSibling;
          while (prev) {
            if (prev.classList.contains('ai-msg') && prev.classList.contains('user')) {
              var bubbleEl = prev.querySelector('.ai-msg-bubble');
              promptText = (bubbleEl ? (bubbleEl.innerText || bubbleEl.textContent) : '').trim();
              break;
            }
            prev = prev.previousElementSibling;
          }
        }
        if (promptText && !state.isProcessing) {
          handleSend(promptText);
        }
      }
    });
  }

  function formatMarkdown(str) {
    if (!str) return '';
    var escaped = escapeHtml(str);

    // Markdown Table → HTML Table
    var lines = escaped.split('\n');
    var tableLines = [];
    var inTable = false;
    var result = [];
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i].trim();
      if (line.indexOf('|') === 0 && line.lastIndexOf('|') > 0) {
        var cells = line.split('|').slice(1, -1).map(function(c) { return c.trim(); });
        if (cells.length > 0 && /^[\-\|:\s]+$/.test(line.replace(/[^\-\|:\s]/g, ''))) {
          inTable = true;
          continue;
        }
        if (inTable || (cells.length > 0 && line.charAt(0) === '|')) {
          tableLines.push(cells);
          inTable = true;
          continue;
        }
      }
      if (inTable && tableLines.length > 0) {
        result.push(buildTable(tableLines));
        tableLines = [];
        inTable = false;
      }
      result.push(lines[i]);
    }
    if (inTable && tableLines.length > 0) {
      result.push(buildTable(tableLines));
    }
    escaped = result.join('\n');

    // Bold
    escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Italic
    escaped = escaped.replace(/\*(.*?)\*/g, '<em>$1</em>');
    // Linebreaks
    escaped = escaped.replace(/\n/g, '<br>');
    // Bullet points
    escaped = escaped.replace(/• (.*?)(<br>|$)/g, '• $1<br>');
    // Links
    escaped = escaped.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" style="color:#2563eb;font-weight:bold" target="_self">$1</a>');

    return escaped;
  }

  function buildTable(rows) {
    if (!rows || rows.length === 0) return '';
    var html = '<table class="ai-md-table"><thead><tr>';
    var headers = rows[0];
    for (var h = 0; h < headers.length; h++) {
      html += '<th>' + headers[h] + '</th>';
    }
    html += '</tr></thead><tbody>';
    for (var r = 1; r < rows.length; r++) {
      html += '<tr>';
      var cols = rows[r];
      for (var c = 0; c < cols.length; c++) {
        html += '<td>' + cols[c] + '</td>';
      }
      html += '</tr>';
    }
    html += '</tbody></table>';
    return html;
  }

  function escapeHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function handleSend(promptText) {
    var query = promptText || (chatInput ? (chatInput.value || chatInput.innerText || chatInput.textContent || '').trim() : '');
    if (!query || state.isProcessing) return;

    if (chatInput) chatInput.value = '';
    state.isProcessing = true;
    state.totalQueries++;
    if (totalQueriesEl) totalQueriesEl.innerText = state.totalQueries;

    appendMessage(query, 'user');

    // Add Typing Indicator with animated cycling status
    appendMessage('<div class="ai-thinking-indicator" id="aiThinkingIndicator"><i class="bi bi-three-dots ai-pulse"></i> <em class="ai-thinking-text">Thinking</em></div>', 'assistant');
    // Start cycling status messages
    const statuses = ['Thinking', 'Processing', 'Searching', 'Analyzing', 'Fetching', 'Targeting', 'Computing'];
    let sIdx = 0, dCount = 0;
    if (window._floatTypingInterval) clearInterval(window._floatTypingInterval);
    window._floatTypingInterval = setInterval(() => {
      const el = document.querySelector('.ai-thinking-text');
      if (el) {
        dCount = (dCount + 1) % 4;
        el.textContent = statuses[sIdx] + '.'.repeat(dCount > 0 ? dCount : 3);
        if (dCount === 0) sIdx = (sIdx + 1) % statuses.length;
      }
    }, 400);

    var body = new FormData();
    body.set('action', 'query');
    body.set('prompt', query);


    var targetApiUrl = window.AI_AGENT_API_URL || 'api.php';
    fetch(targetApiUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        // Remove Typing Indicator
        if (window._floatTypingInterval) { clearInterval(window._floatTypingInterval); window._floatTypingInterval = null; }
        var msgs = chatBody ? chatBody.querySelectorAll('.ai-msg.assistant') : [];
        if (msgs.length > 0) {
          var lastMsg = msgs[msgs.length - 1];
          if (lastMsg.innerHTML.indexOf('ai-thinking-indicator') !== -1) {
            lastMsg.remove();
          }
        }

        state.isProcessing = false;

        if (!res || !res.ok) {
          appendMessage(res ? (res.error || 'Unable to process query.') : 'Network error.', 'assistant');
          return;
        }

        if (apiStatusEl) apiStatusEl.innerText = res.provider || 'Active';
        if (lastToolEl) lastToolEl.innerText = res.tool_used || 'General RAG';

        appendMessage(res.answer || 'Query completed.', 'assistant', res.tool_used, res.suggestions, res.command_type);

        if (chatBody) {
          try { sessionStorage.setItem('ai_chat_history', chatBody.innerHTML); } catch (e) {}
        }

        if (res.nav_url) {
          if (chatBody) {
            try {
              sessionStorage.setItem('ai_chat_history', chatBody.innerHTML);
              sessionStorage.setItem('ai_auto_open_chat', 'true');
            } catch (e) {}
          }
          setTimeout(function() {
            window.location.href = res.nav_url;
          }, 1500);
        }

      })
      .catch(function (err) {
        if (window._floatTypingInterval) { clearInterval(window._floatTypingInterval); window._floatTypingInterval = null; }
        var msgs = chatBody ? chatBody.querySelectorAll('.ai-msg.assistant') : [];
        if (msgs.length > 0) {
          var lastMsg = msgs[msgs.length - 1];
          if (lastMsg.innerHTML.indexOf('ai-thinking-indicator') !== -1) {
            lastMsg.remove();
          }
        }
        state.isProcessing = false;
        appendMessage('Error processing request: ' + (err.message || 'Server connection failed.'), 'assistant');
      });
  }

  // Event Listeners
  if (sendBtn) {
    sendBtn.addEventListener('click', function () { handleSend(); });
  }
    var autocompleteActive = false;
    var currentFocus = -1;

    chatInput.addEventListener('keydown', function (e) {
      var sugBox = document.getElementById('aiCmdSuggestions');
      if (sugBox && sugBox.style.display === 'block') {
        var items = sugBox.querySelectorAll('.ai-cmd-item:not([style*="display: none"])');
        if (e.key === 'ArrowDown') {
          currentFocus++;
          addActive(items);
          e.preventDefault();
          return;
        } else if (e.key === 'ArrowUp') {
          currentFocus--;
          addActive(items);
          e.preventDefault();
          return;
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (currentFocus > -1 && items[currentFocus]) {
            items[currentFocus].click();
          } else {
            handleSend();
          }
          return;
        } else if (e.key === 'Escape') {
          e.preventDefault();
          sugBox.style.display = 'none';
          currentFocus = -1;
          return;
        }
      }
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    });

    function addActive(items) {
      if (!items || !items.length) return false;
      removeActive(items);
      if (currentFocus >= items.length) currentFocus = 0;
      if (currentFocus < 0) currentFocus = items.length - 1;
      items[currentFocus].classList.add('active');
      items[currentFocus].style.background = 'rgba(59,130,246,0.15)';
    }

    function removeActive(items) {
      for (var i = 0; i < items.length; i++) {
        items[i].classList.remove('active');
        items[i].style.background = 'transparent';
      }
    }

    var fetchTimer = null;

    // ── 3-Level AI Suggestion System (UI only — does NOT change ERP logic) ──
    var AI_COMMANDS = [
      { cmd: '/job', desc: 'Job / Planning Priority Mode' },
      { cmd: '/plate', desc: 'Plate Priority Mode' },
      { cmd: '/planning', desc: 'Job Planning Board' },
      { cmd: '/paper', desc: 'Paper Stock Priority Mode' },
      { cmd: '/product', desc: 'Product / Item lookup' },
      { cmd: '/client', desc: 'Client / Party lookup' },
      { cmd: '/dispatch', desc: 'Dispatch / Packing Priority Mode' },
      { cmd: '/order', desc: 'Order lookup' },
      { cmd: '/stock', desc: 'Stock lookup' }
    ];
    var AI_QUERY_EXAMPLES = {
      '/job': ['/job "Blue 500ml"', '/job status of Job ID 100', '/job show latest printing jobs'],
      '/plate': ['/plate "Blue 500ml"', '/plate show active plates', '/plate search plate for Navkar'],
      '/planning': ['/planning open job planning board', '/planning show pending jobs', '/planning today\'s production plan'],
      '/paper': ['/paper "Chromo"', '/paper show Chromo paper stock', '/paper rolls with width 405mm'],
      '/product': ['/product "Blue 500ml"', '/product search product code', '/product latest finished goods'],
      '/client': ['/client search party', '/client top clients', '/client client pending jobs'],
      '/dispatch': ['/dispatch show today\'s dispatch', '/dispatch pending dispatches', '/dispatch packing status'],
      '/order': ['/order latest orders', '/order order status', '/order pending orders'],
      '/stock': ['/stock total paper stock', '/stock low stock items', '/stock current inventory']
    };

    function setChatInputValue(v) {
      if (!chatInput) return;
      chatInput.value = v;
      try { chatInput.setSelectionRange(v.length, v.length); } catch (e) {}
      chatInput.focus();
    }

    function makeSuggestionItem(dataAttr, dataVal, innerHtml) {
      var div = document.createElement('div');
      div.className = 'ai-cmd-item';
      div.style = 'display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0';
      div.setAttribute(dataAttr, dataVal);
      div.innerHTML = innerHtml;
      return div;
    }

    function showSuggestBox(sugBox, items) {
      sugBox.innerHTML = '';
      for (var i = 0; i < items.length; i++) sugBox.appendChild(items[i]);
      currentFocus = -1;
      sugBox.style.display = items.length ? 'block' : 'none';
    }

    // Slash command (Level 1) / query examples (Level 2) / entity autocomplete (Level 3)
    chatInput.addEventListener('input', function () {
      var val = chatInput.value || chatInput.innerText || chatInput.textContent || '';
      var sugBox = document.getElementById('aiCmdSuggestions');
      if (!sugBox) return;

      // LEVEL 3 — Entity Search Mode: /job|/plate|/paper|/product ... "term
      // Triggers on an UNCLOSED opening quote (odd " count) even when extra words are
      // typed between the command and the quote (e.g. `/job how many label if "blue 500`).
      var quoteCount = (val.match(/"/g) || []).length;
      var lastQuote = val.lastIndexOf('"');
      var isEntityCmd = /^\/(job|plate|paper|product)\b/i.test(val);
      if (isEntityCmd && lastQuote !== -1 && (quoteCount % 2) === 1) {
        var searchTerm = val.substring(lastQuote + 1); // text after the opening quote (may be empty = browse all)
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(function() {
          fetch(aiAgentParams.baseUrl + '/modules/ai_agent/api.php?action=autocomplete&prompt=' + encodeURIComponent(searchTerm))
            .then(function(res) { return res.json(); })
            .then(function(data) {
              var items = [];
              if (data.ok && data.suggestions && data.suggestions.length > 0) {
                // Show all matching jobs (empty or typed) so the list scrolls
                data.suggestions.forEach(function(sug) {
                  items.push(makeSuggestionItem('data-autocomplete', sug.name,
                    '<span style="font-weight:700;color:#3b82f6;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(sug.name) + '</span> <span style="font-size:11px;color:#94a3b8;margin-left:auto">' + escapeHtml(sug.size || '') + '</span>'));
                });
              }
              showSuggestBox(sugBox, items);
            })
            .catch(function () { sugBox.style.display = 'none'; });
        }, 200);
        return;
      }

      // LEVEL 2 — Query Suggestions: command + SPACE (not yet inside a quote)
      var queryMatch = val.match(/^\/(job|plate|planning|paper|product|client|dispatch|order|stock)\s+(.*)$/i);
      if (queryMatch && queryMatch[2].indexOf('"') === -1) {
        var qcmd = '/' + queryMatch[1].toLowerCase();
        var examples = (AI_QUERY_EXAMPLES[qcmd] || []).slice(0, 3);
        var qItems = [];
        examples.forEach(function(text) {
          qItems.push(makeSuggestionItem('data-query', text,
            '<span style="font-weight:700;color:#10b981;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(text) + '</span>'));
        });
        showSuggestBox(sugBox, qItems);
        return;
      }

      // LEVEL 1 — Command Suggestions while typing the command (no space yet)
      if (val.startsWith('/') && val.indexOf(' ') === -1) {
        var partial = val.toLowerCase();
        var cItems = [];
        AI_COMMANDS.forEach(function(c) {
          if (c.cmd.toLowerCase().indexOf(partial) === 0) {
            cItems.push(makeSuggestionItem('data-cmd', c.cmd,
              '<span style="font-weight:700;color:#ef4444;font-size:13px;min-width:70px">' + c.cmd + '</span><span style="font-size:11px;color:#94a3b8;margin-left:auto">' + escapeHtml(c.desc) + '</span>'));
          }
        });
        showSuggestBox(sugBox, cItems);
        return;
      }

      sugBox.style.display = 'none';
    });

    // Click on suggestion (also closes dropdown when clicking outside)
    document.addEventListener('click', function (e) {
      var item = e.target.closest('.ai-cmd-item');
      var sugBox = document.getElementById('aiCmdSuggestions');
      if (!item) {
        // Clicking outside the dropdown (and outside the chat input) closes it
        if (sugBox && sugBox.style.display === 'block' && !sugBox.contains(e.target) && chatInput && !chatInput.contains(e.target)) {
          sugBox.style.display = 'none';
          currentFocus = -1;
        }
        return;
      }

      if (item.hasAttribute('data-autocomplete')) {
        // Level 3 — insert entity name at the opening quote, auto-close quote, cursor after it
        var plateName = item.getAttribute('data-autocomplete');
        var val = chatInput.value || chatInput.innerText || chatInput.textContent || '';
        var qCount = (val.match(/"/g) || []).length;
        var qPos = val.lastIndexOf('"');
        if (qPos !== -1 && (qCount % 2) === 1) {
          setChatInputValue(val.substring(0, qPos + 1) + plateName + '" ');
        }
        if (sugBox) sugBox.style.display = 'none';
      } else if (item.hasAttribute('data-query')) {
        // Level 2 — insert the example query
        setChatInputValue(item.getAttribute('data-query') + ' ');
        if (sugBox) sugBox.style.display = 'none';
      } else if (item.hasAttribute('data-cmd')) {
        // Level 1 — insert the command + space
        setChatInputValue(item.getAttribute('data-cmd') + ' ');
        if (sugBox) sugBox.style.display = 'none';
      }
    });

  // Quick Chips click handler
  if (chipButtons) {
    chipButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var prompt = btn.getAttribute('data-prompt');
        if (prompt) handleSend(prompt);
      });
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      if (chatBody) {
        chatBody.innerHTML = '<div class="ai-msg assistant"><div class="ai-msg-avatar"><i class="bi bi-robot"></i></div><div class="ai-msg-content">👋 <strong>Welcome back!</strong> Chat stream cleared. How can I assist you now?</div></div>';
      }
    });
  }

  // Speech Recognition (Voice / Mic Input)
  var micBtn = document.getElementById('aiMicBtn');
  var micIcon = document.getElementById('aiMicIcon');
  var recognition = null;
  var isListening = false;

  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

  if (SpeechRecognition && micBtn) {
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    var initialVoiceText = '';

    recognition.onstart = function() {
      isListening = true;
      if (chatInput) {
          initialVoiceText = chatInput.innerText.trim();
          if (initialVoiceText && !initialVoiceText.endsWith(' ')) initialVoiceText += ' ';
      }
      if (micBtn) {
        micBtn.style.color = '#ef4444';
        micBtn.style.background = 'rgba(239, 68, 68, 0.2)';
        micBtn.style.borderColor = '#ef4444';
      }
      if (micIcon) micIcon.className = 'bi bi-mic-fill ai-pulse';
      if (chatInput) chatInput.dataset.placeholder = '🎙️ Listening... Speak now';
    };

    recognition.onresult = function(event) {
      var transcript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        transcript += event.results[i][0].transcript;
      }
      if (chatInput) {
        chatInput.innerHTML = '';
        chatInput.appendChild(document.createTextNode(initialVoiceText + transcript));
      }
    };

    recognition.onerror = function(event) {
      console.warn('Speech recognition error:', event.error);
      stopListening();
      if (chatInput) chatInput.dataset.placeholder = 'Voice error: ' + event.error + '. Try typing.';
    };

    recognition.onend = function() {
      stopListening();
    };

    function stopListening() {
      isListening = false;
      if (micBtn) {
        micBtn.style.color = '#94a3b8';
        micBtn.style.background = 'rgba(255,255,255,0.08)';
        micBtn.style.borderColor = 'rgba(255,255,255,0.15)';
      }
      if (micIcon) micIcon.className = 'bi bi-mic-fill';
      if (chatInput) chatInput.dataset.placeholder = 'Type query or click mic to speak...';
    }

    micBtn.addEventListener('click', function() {
      if (isListening) {
        recognition.stop();
      } else {
        try {
          recognition.start();
        } catch (e) {
          console.error(e);
        }
      }
    });
  } else if (micBtn) {
    micBtn.addEventListener('click', function() {
      alert('Speech recognition is not supported in this browser. Please use Chrome, Edge, or Safari.');
    });
  }

})();
