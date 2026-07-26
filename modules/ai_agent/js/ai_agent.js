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

  function appendMessage(text, sender, toolUsed) {
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
    contentDiv.innerHTML = html;

    msgDiv.appendChild(avatarDiv);
    msgDiv.appendChild(contentDiv);
    chatBody.appendChild(msgDiv);

    chatBody.scrollTop = chatBody.scrollHeight;
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

        appendMessage(res.answer || 'Query completed.', 'assistant', res.tool_used);

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

  // Web Speech API Voice Recognition (Continuous Mode)
  var micBtns = document.querySelectorAll('#aiMicBtn, #aiMicBtnFloat');
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

  if (SpeechRecognition) {
    var recognition = new SpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;
    if (currentLang === 'Bengali') recognition.lang = 'bn-IN';
    else if (currentLang === 'Hindi') recognition.lang = 'hi-IN';
    else recognition.lang = 'en-US';

    var isListening = false;
    var finalTranscript = '';

    micBtns.forEach(function(btn) {
      if (!btn) return;

      btn.addEventListener('click', function() {
        if (isListening) {
          isListening = false;
          try { recognition.stop(); } catch(e) {}
          stopListeningUI();
          return;
        }

        isListening = true;
        finalTranscript = chatInput ? chatInput.value : '';
        try {
          recognition.start();
        } catch (e) {
          console.warn('Speech recognition start error:', e);
        }
      });
    });


    recognition.onstart = function() {
      micBtns.forEach(function(btn) {
        if (btn) {
          btn.classList.add('listening');
          btn.setAttribute('title', '🎙️ Continuous Listening Active (Click mic to stop)');
        }
      });
      if (chatInput) {
        chatInput.placeholder = '🎙️ Continuous Voice Input Active... Speak freely in English, Bengali, or Hindi...';
      }
    };

    recognition.onresult = function(event) {
      var interimTranscript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var transcriptChunk = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          finalTranscript += (finalTranscript ? ' ' : '') + transcriptChunk;
        } else {
          interimTranscript += transcriptChunk;
        }
      }
      if (chatInput) {
        chatInput.value = finalTranscript + (interimTranscript ? (finalTranscript ? ' ' : '') + interimTranscript : '');
      }
    };

    recognition.onerror = function(event) {
      console.warn('Speech recognition error:', event.error);
      if (event.error === 'no-speech' || event.error === 'network') {
        return;
      }
      isListening = false;
      stopListeningUI();
    };

    recognition.onend = function() {
      if (isListening) {
        try {
          recognition.start();
        } catch (e) {
          isListening = false;
          stopListeningUI();
        }
      } else {
        stopListeningUI();
      }
    };

    function stopListeningUI() {
      micBtns.forEach(function(btn) {
        if (btn) {
          btn.classList.remove('listening');
          btn.setAttribute('title', 'Voice Input — Click to Speak Continuously');
        }
      });
      if (chatInput) {
        chatInput.placeholder = 'Type query or click mic to speak continuously...';
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
