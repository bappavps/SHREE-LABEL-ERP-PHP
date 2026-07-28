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

    var html = '';
    if (toolUsed && sender === 'assistant') {
      html += '<div class="ai-tool-call-tag"><i class="bi bi-lightning-charge-fill"></i> Executed ERP Tool: ' + escapeHtml(toolUsed) + '</div>';
    }

    if (text === '<div class="ai-thinking-indicator"><i class="bi bi-three-dots ai-pulse"></i> <em>AI is thinking...</em></div>') {
      html += text;
    } else {
      html += formatMarkdown(text);
    }

    // Suggestions chips rendering
    if (sender === 'assistant' && suggestions && suggestions.length > 0) {
      html += '<div class="ai-suggestion-box">';
      html += '<div class="ai-suggestion-title"><i class="bi bi-lightbulb-fill" style="color:#f59e0b"></i> Suggested Questions:</div>';
      html += '<div class="ai-suggestion-chips">';
      for (var s = 0; s < suggestions.length; s++) {
        var sug = suggestions[s];
        html += '<button type="button" class="ai-suggestion-chip" data-prompt="' + escapeHtml(sug) + '"><i class="bi bi-chat-left-text"></i> ' + escapeHtml(sug) + '</button>';
      }
      html += '</div></div>';
    }

    contentDiv.innerHTML = html;

    msgDiv.appendChild(avatarDiv);
    msgDiv.appendChild(contentDiv);
    chatBody.appendChild(msgDiv);
    
    chatBody.scrollTop = chatBody.scrollHeight;
  }

  // Event delegation on chatBody for suggestion chips
  if (chatBody) {
    chatBody.addEventListener('click', function(e) {
      var chip = e.target.closest('.ai-suggestion-chip');
      if (chip) {
        var p = chip.getAttribute('data-prompt');
        if (p && !state.isProcessing) {
          handleSend(p);
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
    var query = promptText || (chatInput ? chatInput.value.trim() : '');
    if (!query || state.isProcessing) return;

    if (chatInput) chatInput.value = '';
    state.isProcessing = true;
    state.totalQueries++;
    if (totalQueriesEl) totalQueriesEl.innerText = state.totalQueries;

    appendMessage(query, 'user');

    // Add Typing Indicator
    appendMessage('<div class="ai-thinking-indicator"><i class="bi bi-three-dots ai-pulse"></i> <em>AI is thinking...</em></div>', 'assistant');

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
  if (chatInput) {
    chatInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') handleSend();
    });
  }

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

    recognition.onstart = function() {
      isListening = true;
      if (micBtn) {
        micBtn.style.color = '#ef4444';
        micBtn.style.background = 'rgba(239, 68, 68, 0.2)';
        micBtn.style.borderColor = '#ef4444';
      }
      if (micIcon) micIcon.className = 'bi bi-mic-fill ai-pulse';
      if (chatInput) chatInput.placeholder = '🎙️ Listening... Speak now';
    };

    recognition.onresult = function(event) {
      var transcript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        transcript += event.results[i][0].transcript;
      }
      if (chatInput) chatInput.value = transcript;
    };

    recognition.onerror = function(event) {
      console.warn('Speech recognition error:', event.error);
      stopListening();
      if (chatInput) chatInput.placeholder = 'Voice error: ' + event.error + '. Try typing.';
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
      if (chatInput) chatInput.placeholder = 'Type query or click mic to speak...';
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
