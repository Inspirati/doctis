/* ── AI Assistant — Help tab ─────────────────────────────────────────────── *
 * External script loaded by ai_assist_page.php.                              *
 * Kept external so MantisBT's script-src 'self' CSP is satisfied.           *
 * ─────────────────────────────────────────────────────────────────────────── */
(function() {
	'use strict';

	/* ── State ─────────────────────────────────────────────────────────── */
	var chatHistory = [];       // [{role:'user'|'assistant', content:'...'}]
	var currentXhr  = null;     // active XMLHttpRequest, or null

	var SYSTEM_PROMPT =
		'You are the Doctis AI Assistant, running inside the Doctis document ' +
		'issue-tracking system. Doctis is a PHP/MariaDB web application built ' +
		'on MantisBT that tracks controlled documents and the review issues ' +
		'raised against them during formal document review cycles.\n\n' +
		'Your role in this session is to help users with:\n' +
		'- Using Doctis features: creating documents, raising issues, filtering, ' +
		'  the review workflow, uploading primary document files, managing revisions\n' +
		'- Understanding document statuses (pending \u2192 received \u2192 triage \u2192 JoS \u2192 ' +
		'  assigned to \u2192 review \u2192 rework \u2192 independent review \u2192 accepted \u2192 ' +
		'  incorporated \u2192 archived)\n' +
		'- QMS document control concepts: what a controlled document is, why ' +
		'  revision tracking matters, ISO 9001 Clause 7.5 requirements\n' +
		'- Finding the right Doctis page or function for a given task\n' +
		'- Understanding the difference between a Doctis Document and an Issue\n\n' +
		'Be concise and practical. Refer to Doctis page names where helpful ' +
		'(e.g. dwg_create_page.php, dwg_view.php, view_dwg_page.php). ' +
		'Do not invent features that do not exist. If you are unsure of a ' +
		'specific Doctis implementation detail, say so.';

	/* ── DOM references ─────────────────────────────────────────────────── */
	var $messages  = document.getElementById('ai-chat-messages');
	var $input     = document.getElementById('ai-chat-input');
	var $sendBtn   = document.getElementById('ai-send-btn');
	var $stopBtn   = document.getElementById('ai-stop-btn');
	var $clearBtn  = document.getElementById('ai-clear-btn');
	var $statusTxt = document.getElementById('ai-status-text');
	var $charCount = document.getElementById('ai-char-count');

	if( !$messages || !$input || !$sendBtn ) return;   // guard: page not ready

	/* ── Helpers ─────────────────────────────────────────────────────────── */
	function scrollToBottom() {
		$messages.scrollTop = $messages.scrollHeight;
	}

	function setStatus(msg) {
		$statusTxt.textContent = msg;
	}

	function updateCharCount() {
		var len = $input.value.length;
		$charCount.textContent = len > 3500 ? len + ' / 4000' : '';
		$charCount.style.color = len > 3800 ? '#c00' : '#aaa';
	}

	/* Simple markdown-lite: fenced code, inline code, bold, newlines */
	function renderMarkdown(text) {
		text = text.replace(/```[\w]*\n?([\s\S]*?)```/g,
			'<pre style="margin:6px 0;padding:8px;background:#f4f4f4;border-radius:4px;' +
			'font-size:11px;overflow-x:auto"><code>$1</code></pre>');
		text = text.replace(/`([^`]+)`/g,
			'<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:11px">$1</code>');
		text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		text = text.replace(/\n/g, '<br>');
		return text;
	}

	function appendMessage(role, content) {
		var row = document.createElement('div');
		row.className = 'ai-msg-row ' + role;

		var avatar = document.createElement('div');
		avatar.className = 'ai-msg-avatar';
		avatar.innerHTML = role === 'user'
			? '<i class="ace-icon fa fa-user"></i>'
			: '<i class="ace-icon fa fa-comments-o"></i>';

		var bubble = document.createElement('div');
		bubble.className = 'ai-msg-bubble';

		if( role === 'assistant' ) {
			bubble.innerHTML = renderMarkdown(content);
		} else {
			bubble.textContent = content;
		}

		row.appendChild(avatar);
		row.appendChild(bubble);
		$messages.appendChild(row);
		scrollToBottom();
	}

	function showTypingIndicator() {
		var row = document.createElement('div');
		row.className = 'ai-msg-row assistant';
		row.id = 'ai-typing-row';

		var avatar = document.createElement('div');
		avatar.className = 'ai-msg-avatar';
		avatar.innerHTML = '<i class="ace-icon fa fa-comments-o"></i>';

		var bubble = document.createElement('div');
		bubble.className = 'ai-msg-bubble ai-typing-indicator';
		bubble.innerHTML = '<span></span><span></span><span></span>';

		row.appendChild(avatar);
		row.appendChild(bubble);
		$messages.appendChild(row);
		scrollToBottom();
	}

	function removeTypingIndicator() {
		var row = document.getElementById('ai-typing-row');
		if( row ) row.parentNode.removeChild(row);
	}

	function setBusy(busy) {
		$sendBtn.disabled = busy;
		$stopBtn.disabled = !busy;
		$input.disabled   = busy;
		if( busy ) {
			$sendBtn.innerHTML = '<i class="ace-icon fa fa-spinner fa-spin"></i> Sending';
		} else {
			$sendBtn.innerHTML = '<i class="ace-icon fa fa-paper-plane"></i> Send';
			$input.focus();
		}
	}

	/* ── Send a message ──────────────────────────────────────────────────── */
	function sendMessage() {
		var text = $input.value.trim();
		if( !text || currentXhr ) return;

		// Remove welcome message on first send
		if( chatHistory.length === 0 ) {
			var welcome = document.getElementById('ai-welcome-msg');
			if( welcome ) welcome.parentNode.removeChild( welcome );
		}

		appendMessage('user', text);
		chatHistory.push({ role: 'user', content: text });
		$input.value = '';
		updateCharCount();

		showTypingIndicator();
		setBusy(true);
		setStatus('Thinking\u2026');

		var payload = JSON.stringify({
			mode   : 'help',
			system : SYSTEM_PROMPT,
			history: chatHistory
		});

		currentXhr = new XMLHttpRequest();
		currentXhr.open('POST', 'ai_assist_api.php', true);
		currentXhr.setRequestHeader('Content-Type', 'application/json');
		currentXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

		currentXhr.onload = function() {
			var responseText = this.responseText;   // capture before nulling
			removeTypingIndicator();
			setBusy(false);
			currentXhr = null;

			var data;
			try {
				data = JSON.parse(responseText);
			} catch(e) {
				data = { error: 'Invalid response from server.' };
			}

			if( data.error ) {
				setStatus('Error: ' + data.error);
				appendMessage('assistant', '\u26a0\ufe0f ' + data.error);
				return;
			}

			setStatus('');
			appendMessage('assistant', data.reply);
			chatHistory.push({ role: 'assistant', content: data.reply });
		};

		currentXhr.onerror = function() {
			removeTypingIndicator();
			setBusy(false);
			currentXhr = null;
			setStatus('Network error \u2014 check your connection.');
			appendMessage('assistant', '\u26a0\ufe0f Could not reach the server. Please try again.');
		};

		currentXhr.send(payload);
	}

	/* ── Stop in-flight request ─────────────────────────────────────────── */
	function stopRequest() {
		if( currentXhr ) {
			currentXhr.abort();
			currentXhr = null;
			removeTypingIndicator();
			setBusy(false);
			setStatus('Stopped.');
			chatHistory.pop();   // discard the unanswered user turn
		}
	}

	/* ── Clear conversation ─────────────────────────────────────────────── */
	function clearConversation() {
		chatHistory = [];
		$messages.innerHTML = '';

		// Restore welcome message
		var welcome = document.createElement('div');
		welcome.className = 'ai-msg-row assistant';
		welcome.id = 'ai-welcome-msg';
		welcome.innerHTML =
			'<div class="ai-msg-avatar"><i class="ace-icon fa fa-comments-o"></i></div>' +
			'<div class="ai-msg-bubble">Hello! How can I help you with Doctis today?</div>';
		$messages.appendChild(welcome);

		setStatus('');
		$input.value = '';
		updateCharCount();
		$input.focus();
	}

	/* ── Event listeners ────────────────────────────────────────────────── */
	$sendBtn.addEventListener('click', sendMessage);
	$stopBtn.addEventListener('click', stopRequest);
	$clearBtn.addEventListener('click', clearConversation);

	$input.addEventListener('keydown', function(e) {
		if( e.keyCode === 13 && (e.ctrlKey || e.shiftKey) ) {
			e.preventDefault();
			sendMessage();
		}
	});

	$input.addEventListener('input', updateCharCount);

	// Restore active tab from URL hash on load
	(function() {
		var hash = window.location.hash;
		if( hash ) {
			var link = document.querySelector('#ai-tab-nav a[href="' + hash + '"]');
			if( link && window.jQuery ) { jQuery(link).tab('show'); }
		}
	})();

	// Update URL hash when tab changes
	document.querySelectorAll('#ai-tab-nav a[data-toggle="tab"]').forEach(function(el) {
		el.addEventListener('click', function() {
			var href = this.getAttribute('href');
			if( href ) history.replaceState(null, null, href);
		});
	});

	$input.focus();
	scrollToBottom();

})();
