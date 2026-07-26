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

  function appendMessage(text, sender, toolUsed, suggestions) {
    if (!chatBody) return;

    var msgDiv = document.createElement('div');
    msgDiv.className = 'ai-msg ' + sender;

    var avatarDiv = document.createElement('div');
    avatarDiv.className = 'ai-msg-avatar';
    avatarDiv.innerHTML = sender === 'user' ? '<i class="bi bi-person"></i>' : '<i class="bi bi-robot"></i>';

    var contentDiv = document.createElement('div');
    contentDiv.className = 'ai-msg-content';

    var html = '';
    if (toolUsed && sender === 'assistant') {
      html += '<div class="ai-tool-call-tag"><i class="bi bi-lightning-charge-fill"></i> Executed ERP Tool: ' + escapeHtml(toolUsed) + '</div>';
    }

    // Basic markdown formatter for text responses
    html += formatMarkdown(text);

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

  function escapeHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  var currentLang = localStorage.getItem('ai_manual_lang') || 'English';
  var langPills = document.querySelectorAll('.ai-lang-pill');

  function updateLangPillsUI(selected) {
    currentLang = selected;
    localStorage.setItem('ai_manual_lang', selected);
    langPills.forEach(function(pill) {
      if (pill.getAttribute('data-lang') === selected) {
        pill.classList.add('active');
      } else {
        pill.classList.remove('active');
      }
    });
    if (typeof recognition !== 'undefined' && recognition) {
      if (selected === 'Bengali') recognition.lang = 'bn-IN';
      else if (selected === 'Hindi') recognition.lang = 'hi-IN';
      else recognition.lang = 'en-US';
    }
  }

  if (langPills.length > 0) {
    updateLangPillsUI(currentLang);
    langPills.forEach(function(pill) {
      pill.addEventListener('click', function() {
        var lang = pill.getAttribute('data-lang');
        if (lang) updateLangPillsUI(lang);
      });
    });
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
    appendMessage('<i class="bi bi-three-dots ai-pulse"></i> <em>ERP AI Brain is thinking...</em>', 'assistant');

    var body = new FormData();
    body.set('action', 'query');
    body.set('prompt', query);
    if (currentLang && currentLang !== 'Auto') {
      body.set('user_lang', currentLang);
    }

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
          if (lastMsg.innerHTML.indexOf('ai-pulse') !== -1) {
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

        appendMessage(res.answer || 'Query completed.', 'assistant', res.tool_used, res.suggestions);

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
          if (lastMsg.innerHTML.indexOf('ai-pulse') !== -1) {
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

  // Web Speech API Voice Recognition — Press & Hold (Push-to-Talk)
  var micBtns = document.querySelectorAll('#aiMicBtn, #aiMicBtnFloat, #micBtn');
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

  if (SpeechRecognition) {
    var recognition = new SpeechRecognition();
    recognition.interimResults = true;
    recognition.continuous = true;
    recognition.maxAlternatives = 1;

    var isListening = false;
    var isUserHoldingMic = false;
    var currentUtterance = '';

    function startPushToTalk() {
      if (isUserHoldingMic || state.isProcessing) return;
      isUserHoldingMic = true;
      isListening = true;
      currentUtterance = '';
      if (chatInput) chatInput.value = '';

      if (currentLang === 'Bengali' || currentLang === 'bn-IN') recognition.lang = 'bn-IN';
      else if (currentLang === 'Hindi' || currentLang === 'hi-IN') recognition.lang = 'hi-IN';
      else if (currentLang === 'English' || currentLang === 'en-US') recognition.lang = 'en-US';
      else recognition.lang = 'hi-IN';

      try {
        recognition.start();
      } catch (e) {
        isListening = false;
        isUserHoldingMic = false;
        console.warn('Speech recognition start error:', e);
        return;
      }
      micBtns.forEach(function(btn) {
        if (btn) {
          btn.classList.add('listening');
          btn.setAttribute('title', '🎙️ Speaking... Release to send');
        }
      });
      if (chatInput) {
        chatInput.placeholder = '🎙️ Listening... Release mic to send';
      }
    }

    function stopPushToTalk() {
      if (!isUserHoldingMic) return;
      isUserHoldingMic = false;
      try {
        recognition.stop();
      } catch(e) {}
    }

    micBtns.forEach(function(btn) {
      if (!btn) return;
      var touchFired = false;

      btn.addEventListener('touchstart', function(e) {
        touchFired = true;
        startPushToTalk();
      });

      btn.addEventListener('touchend', function(e) {
        touchFired = false;
        stopPushToTalk();
      });

      btn.addEventListener('touchcancel', function() {
        touchFired = false;
        isUserHoldingMic = false;
        isListening = false;
        try { recognition.stop(); } catch(e) {}
        stopListeningUI();
      });

      btn.addEventListener('mousedown', function(e) {
        if (touchFired) return;
        startPushToTalk();
      });

      btn.addEventListener('mouseup', function(e) {
        if (touchFired) return;
        stopPushToTalk();
      });

      btn.addEventListener('mouseleave', function() {
        if (touchFired) return;
        stopPushToTalk();
      });
    });

    recognition.onresult = function(event) {
      var fullTranscript = '';
      for (var i = 0; i < event.results.length; i++) {
        fullTranscript += event.results[i][0].transcript + ' ';
      }
      currentUtterance = fullTranscript.trim();
      if (chatInput) chatInput.value = currentUtterance;
    };

    recognition.onerror = function(event) {
      console.warn('Speech recognition error:', event.error);
      if (event.error === 'no-speech' || event.error === 'network' || event.error === 'aborted') {
        if (isUserHoldingMic) {
          try { recognition.start(); } catch(err) {}
          return;
        }
      }
      isListening = false;
      isUserHoldingMic = false;
      stopListeningUI();
    };

    recognition.onend = function() {
      if (isUserHoldingMic) {
        // User is STILL holding the mic button! Restart across silence pauses
        try {
          recognition.start();
        } catch(e) {
          isUserHoldingMic = false;
          isListening = false;
          stopListeningUI();
        }
        return;
      }

      // User HAS RELEASED the mic button! Send prompt
      var text = currentUtterance || (chatInput ? chatInput.value : '');
      isListening = false;
      stopListeningUI();
      if (text.trim()) {
        handleSend(text.trim());
      }
    };

    function stopListeningUI() {
      micBtns.forEach(function(btn) {
        if (btn) {
          btn.classList.remove('listening');
          btn.setAttribute('title', 'Voice Input — Press & Hold to Speak');
        }
      });
      if (chatInput) {
        chatInput.placeholder = 'Press & hold mic to speak...';
      }
    }
  } else {
    micBtns.forEach(function(btn) {
      if (btn) {
        btn.addEventListener('click', function() {
          alert('Voice Speech Recognition is not supported by your current browser. Please use Google Chrome, Edge, or Safari.');
        });
      }
    });
  }

})();
