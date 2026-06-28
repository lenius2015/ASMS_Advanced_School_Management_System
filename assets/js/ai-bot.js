/**
 * assets/js/ai-bot.js
 * ASMS AI Assistant — Floating chat widget with page analysis,
 * quick help, and Q&A capabilities. Rule-based, no external API.
 *
 * Dependencies: Font Awesome (for icons), Bootstrap (optional styling)
 * Works with api/ai_bot.php backend.
 */

(function () {
  'use strict';

  // ====== Configuration ======
  var API_URL = (window.ASMS_PAGE_CONTEXT && window.ASMS_PAGE_CONTEXT.apiBaseUrl) ? window.ASMS_PAGE_CONTEXT.apiBaseUrl : 'api/ai_bot.php';
  var BOT_NAME = 'ASMS Assistant';
  var WELCOME_MSG = 'Hello! I\'m your ASMS assistant. I can help you analyze this page, answer questions, or guide you through tasks. What would you like help with?';
  var AVATAR_ICON = 'fa-robot';

  // ====== State ======
  var isOpen = false;
  var isLoading = false;

  // ====== DOM References ======
  var btn, panel, messagesEl, inputEl, sendBtn;

  // ====== Initialize ======
  function init() {
    renderWidget();
    bindEvents();
    addWelcomeMessage();
  }

  // ====== Render HTML ======
  function renderWidget() {
    var body = document.body;

    // Floating Button
    btn = document.createElement('button');
    btn.id = 'asmsBotBtn';
    btn.setAttribute('aria-label', 'Open AI Assistant');
    btn.title = 'AI Assistant';
    btn.innerHTML = '<span class="bot-icon"><i class="fa fa-robot"></i></span>';
    body.appendChild(btn);

    // Chat Panel
    panel = document.createElement('div');
    panel.id = 'asmsBotPanel';
    panel.innerHTML =
      '<div class="bot-header">' +
        '<div class="bot-avatar"><i class="fa ' + AVATAR_ICON + '"></i></div>' +
        '<div class="bot-title">' +
          '<h6>' + BOT_NAME + '</h6>' +
          '<div class="bot-status"><span class="status-dot"></span> Online</div>' +
        '</div>' +
        '<button class="bot-close" id="asmsBotClose" aria-label="Close"><i class="fa fa-times"></i></button>' +
      '</div>' +
      '<div class="bot-quick-actions">' +
        '<button class="qa-btn" data-action="analyze"><i class="fa fa-chart-bar"></i> Analyze</button>' +
        '<button class="qa-btn" data-action="tasks"><i class="fa fa-tasks"></i> My Tasks</button>' +
        '<button class="qa-btn" data-action="contacts"><i class="fa fa-address-book"></i> Contacts</button>' +
        '<button class="qa-btn" data-action="help"><i class="fa fa-life-ring"></i> Help</button>' +
        '<button class="qa-btn" data-action="tips"><i class="fa fa-lightbulb"></i> Tips</button>' +
      '</div>' +
      '<div class="bot-messages" id="asmsBotMessages"></div>' +
      '<div class="bot-input-area">' +
        '<input type="text" id="asmsBotInput" placeholder="Ask me anything..." autocomplete="off">' +
        '<button class="bot-send" id="asmsBotSend" aria-label="Send"><i class="fa fa-paper-plane"></i></button>' +
      '</div>';
    body.appendChild(panel);
  }


  // ====== Event Binding ======
  function bindEvents() {
    // Toggle button
    btn.addEventListener('click', togglePanel);

    // Close button
    var closeBtn = document.getElementById('asmsBotClose');
    if (closeBtn) {
      closeBtn.addEventListener('click', closePanel);
    }

    // Quick action buttons
    panel.querySelectorAll('.qa-btn').forEach(function (el) {
      el.addEventListener('click', function () {
        var action = el.getAttribute('data-action');
        handleQuickAction(action);
      });
    });

    // Send button & input
    sendBtn = document.getElementById('asmsBotSend');
    inputEl = document.getElementById('asmsBotInput');
    messagesEl = document.getElementById('asmsBotMessages');

    if (sendBtn && inputEl) {
      sendBtn.addEventListener('click', sendMessage);
      inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          sendMessage();
        }
      });
    }
  }

  // ====== Panel Toggle ======
  function togglePanel() {
    if (isOpen) {
      closePanel();
    } else {
      openPanel();
    }
  }

  function openPanel() {
    isOpen = true;
    btn.classList.add('active');
    panel.classList.add('open');
    if (inputEl) {
      setTimeout(function () { inputEl.focus(); }, 350);
    }
  }

  function closePanel() {
    isOpen = false;
    btn.classList.remove('active');
    panel.classList.remove('open');
  }

  // ====== Welcome Message ======
  function addWelcomeMessage() {
    addBotMessage(WELCOME_MSG);
  }


  // ====== Quick Actions ======
  function handleQuickAction(action) {
    if (isLoading) return;

    switch (action) {
      case 'analyze':
        addUserMessage('Analyze this page');
        requestBot('analyze');
        break;
      case 'help':
        addUserMessage('Show me quick help');
        requestBot('help');
        break;
      case 'tips':
        addUserMessage('Give me tips');
        requestBot('tips');
        break;
      case 'tasks':
        addUserMessage('Show my incomplete tasks');
        requestBot('tasks');
        break;
      case 'contacts':
        addUserMessage('Show important contacts');
        requestBot('contacts');
        break;
      default:
        break;
    }
  }

  // ====== Send Message ======
  function sendMessage() {
    var text = inputEl.value.trim();
    if (!text || isLoading) return;

    addUserMessage(text);
    inputEl.value = '';
    requestBot('ask', text);
  }

  // ====== Add Messages ======
  function addBotMessage(html) {
    if (!messagesEl) return;
    var div = document.createElement('div');
    div.className = 'bot-msg';
    div.innerHTML =
      '<div class="bot-msg-avatar"><i class="fa ' + AVATAR_ICON + '"></i></div>' +
      '<div class="bot-msg-content">' + html + '</div>';
    messagesEl.appendChild(div);
    scrollToBottom();
  }

  function addUserMessage(text) {
    if (!messagesEl) return;
    var div = document.createElement('div');
    div.className = 'user-msg';
    div.innerHTML = '<div class="user-msg-content">' + escapeHtml(text) + '</div>';
    messagesEl.appendChild(div);
    scrollToBottom();
  }

  function showTyping() {
    if (!messagesEl) return;
    var div = document.createElement('div');
    div.className = 'bot-msg typing';
    div.id = 'asmsBotTyping';
    div.innerHTML =
      '<div class="bot-msg-avatar"><i class="fa ' + AVATAR_ICON + '"></i></div>' +
      '<div class="bot-msg-content">' +
        '<span class="typing-dot"></span>' +
        '<span class="typing-dot"></span>' +
        '<span class="typing-dot"></span>' +
      '</div>';
    messagesEl.appendChild(div);
    scrollToBottom();
  }


  // ====== Request Bot ======
  function requestBot(action, message) {
    isLoading = true;
    if (sendBtn) sendBtn.disabled = true;
    showTyping();

    // Gather page context
    var pageContext = null;
    if (window.ASMS_PAGE_CONTEXT) {
      pageContext = window.ASMS_PAGE_CONTEXT;
    }

    var data = new URLSearchParams();
    data.append('action', action);
    data.append('message', message || '');
    data.append('page_url', window.location.pathname);
    data.append('page_title', document.title);

    if (pageContext) {
      data.append('context', JSON.stringify(pageContext));
    }

    fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: data.toString()
    })
    .then(function (response) {
      if (!response.ok) {
        return response.text().then(function (text) {
          throw new Error('Server error: ' + response.status + ' - ' + text.substring(0, 200));
        });
      }
      return response.json();
    })
    .then(function (result) {
      removeTyping();
      if (result.success && result.response) {
        addBotMessage(result.response);
      } else {
        addBotMessage('I\'m sorry, I couldn\'t process that. ' + (result.error || 'Please try again.'));
      }
    })
    .catch(function (err) {
      removeTyping();
      console.error('AI Bot error:', err);
      addBotMessage('<div class="error-msg">Sorry, I encountered an error. Please make sure the server is available.</div>');
    })
    .finally(function () {
      isLoading = false;
      if (sendBtn) sendBtn.disabled = false;
      if (inputEl) inputEl.focus();
    });
  }

  // ====== Utility ======
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function removeTyping() {
    var typing = document.getElementById('asmsBotTyping');
    if (typing) {
      typing.remove();
    }
  }

  function scrollToBottom() {
    if (messagesEl) {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }
  }

  // ====== Start ======
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
