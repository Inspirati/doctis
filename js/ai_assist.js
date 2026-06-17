/* ── AI Assistant — Help and Meeting tabs ────────────────────────────────── *
 * External script loaded by ai_assist_page.php.                              *
 * Kept external so MantisBT's script-src 'self' CSP is satisfied.           *
 * ─────────────────────────────────────────────────────────────────────────── */
(function() {
	'use strict';

	/* ── Markdown renderer ─────────────────────────────────────────────────── *
	 * Handles: fenced code, inline code, bold+italic, unordered lists,        *
	 * ordered lists, horizontal rules, and line breaks.                        *
	 * ─────────────────────────────────────────────────────────────────────────*/
	function renderMarkdown(text) {
		/* Fenced code blocks — must come before inline-code pass */
		text = text.replace(/```[\w]*\n?([\s\S]*?)```/g,
			'<pre style="margin:6px 0;padding:8px;background:#f4f4f4;border-radius:4px;' +
			'font-size:11px;overflow-x:auto"><code>$1</code></pre>');

		/* Inline code */
		text = text.replace(/`([^`]+)`/g,
			'<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:11px">$1</code>');

		/* Bold and italic (order: bold-italic before bold before italic) */
		text = text.replace(/\*\*\*([^*]+)\*\*\*/g, '<strong><em>$1</em></strong>');
		text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

		/* Horizontal rules */
		text = text.replace(/^[ \t]*[-*_]{3,}[ \t]*$/gm,
			'<hr style="border:none;border-top:1px solid #dde3ea;margin:8px 0">');

		/* Unordered lists — convert runs of bullet lines into <ul> */
		text = text.replace(/((?:^[ \t]*[-*+][ \t]+.+\n?)+)/gm, function(block) {
			var items = block.replace(/^[ \t]*[-*+][ \t]+(.+)/gm, '<li>$1</li>');
			return '<ul style="margin:4px 0 4px 18px;padding:0">' + items + '</ul>';
		});

		/* Ordered lists — convert runs of numbered lines into <ol> */
		text = text.replace(/((?:^[ \t]*\d+\.[ \t]+.+\n?)+)/gm, function(block) {
			var items = block.replace(/^[ \t]*\d+\.[ \t]+(.+)/gm, '<li>$1</li>');
			return '<ol style="margin:4px 0 4px 18px;padding:0">' + items + '</ol>';
		});

		/* Line breaks */
		text = text.replace(/\n/g, '<br>');

		return text;
	}

	/* ── Chat session factory ──────────────────────────────────────────────── *
	 * Creates an independent chat session bound to a specific set of DOM       *
	 * elements.  Used for both the Help tab and the Meeting tab.               *
	 *                                                                           *
	 * cfg fields:                                                               *
	 *   mode            string   'help' | 'meeting' | 'sop' | 'other'          *
	 *   systemPrompt    string   client-side system prompt ('' = server builds) *
	 *   messages        Element  scroll container (#ai-*-messages)              *
	 *   input           Element  <textarea>                                     *
	 *   sendBtn         Element  Send <button>                                  *
	 *   stopBtn         Element  Stop <button>                                  *
	 *   clearBtn        Element  Clear <button>                                 *
	 *   statusTxt       Element  status text <span>                             *
	 *   charCount       Element  char count <span>                              *
	 *   tokenTxt        Element  token count <span>                             *
	 *   welcomeId       string   ID of the initial welcome element              *
	 *   compactWelcome  string   innerHTML for the compact post-clear welcome   *
	 *   onSavedDocument function|null  callback(data) when server saves a doc   *
	 * ─────────────────────────────────────────────────────────────────────────*/
	function createChatSession(cfg) {
		var mode           = cfg.mode;
		var $messages      = cfg.messages;
		var $input         = cfg.input;
		var $sendBtn       = cfg.sendBtn;
		var $stopBtn       = cfg.stopBtn;
		var $clearBtn      = cfg.clearBtn;
		var $statusTxt     = cfg.statusTxt;
		var $charCount     = cfg.charCount;
		var $tokenTxt      = cfg.tokenTxt;
		var systemPrompt   = cfg.systemPrompt   || '';
		var welcomeId      = cfg.welcomeId      || '';
		var compactWelcome = cfg.compactWelcome || '';

		var chatHistory    = [];
		var currentXhr     = null;
		var totalInputTok  = 0;
		var totalOutputTok = 0;

		if( !$messages || !$input || !$sendBtn ) return null;   // guard

		/* ── Helpers ──────────────────────────────────────────────────────── */
		function scrollToBottom() {
			$messages.scrollTop = $messages.scrollHeight;
		}

		function setStatus(msg) {
			if( $statusTxt ) $statusTxt.textContent = msg;
		}

		function updateCharCount() {
			if( !$charCount ) return;
			var len = $input.value.length;
			$charCount.textContent = len > 3500 ? len + ' / 4000' : '';
			$charCount.style.color = len > 3800 ? '#c00' : '#aaa';
		}

		function updateTokenDisplay() {
			if( !$tokenTxt ) return;
			if( totalInputTok > 0 || totalOutputTok > 0 ) {
				$tokenTxt.textContent =
					'\u25aa ' + totalInputTok.toLocaleString() + ' in / ' +
					totalOutputTok.toLocaleString() + ' out';
			} else {
				$tokenTxt.textContent = '';
			}
		}

		/* ── Append a chat bubble ─────────────────────────────────────────── */
		function appendMessage(role, content) {
			var row = document.createElement('div');
			row.className = 'ai-msg-row ' + role;

			var avatar = document.createElement('div');
			avatar.className = 'ai-msg-avatar';
			avatar.innerHTML = role === 'user'
				? '<i class="ace-icon fa fa-user"></i>'
				: ( mode === 'meeting'
					? '<i class="ace-icon fa fa-calendar-o"></i>'
					: '<i class="ace-icon fa fa-comments-o"></i>' );

			var bubble = document.createElement('div');
			bubble.className = 'ai-msg-bubble';

			if( role === 'assistant' ) {
				bubble.innerHTML = renderMarkdown(content);

				/* Copy-to-clipboard button */
				var copyBtn = document.createElement('button');
				copyBtn.className = 'ai-copy-btn';
				copyBtn.title = 'Copy to clipboard';
				copyBtn.innerHTML = '<i class="ace-icon fa fa-clipboard"></i>';
				copyBtn.addEventListener('click', function() {
					if( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText(content).then(function() {
							copyBtn.innerHTML = '<i class="ace-icon fa fa-check"></i>';
							setTimeout(function() {
								copyBtn.innerHTML = '<i class="ace-icon fa fa-clipboard"></i>';
							}, 1500);
						});
					} else {
						var ta = document.createElement('textarea');
						ta.value = content;
						ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
						document.body.appendChild(ta);
						ta.select();
						try { document.execCommand('copy'); } catch(e) {}
						document.body.removeChild(ta);
						copyBtn.innerHTML = '<i class="ace-icon fa fa-check"></i>';
						setTimeout(function() {
							copyBtn.innerHTML = '<i class="ace-icon fa fa-clipboard"></i>';
						}, 1500);
					}
				});
				bubble.appendChild(copyBtn);
			} else {
				bubble.textContent = content;
			}

			row.appendChild(avatar);
			row.appendChild(bubble);
			$messages.appendChild(row);
			scrollToBottom();
		}

		/* ── Saved-document notification card (meeting mode) ──────────────── */
		function appendSavedDocCard(doc) {
			var card = document.createElement('div');
			card.style.cssText =
				'margin:6px 0;padding:10px 14px;background:#f0f9f4;border:1px solid #b0dcc4;' +
				'border-radius:6px;font-size:12px;color:#2d6a4f;';

			var isError = !!doc.error;
			var icon = isError
				? '<i class="ace-icon fa fa-exclamation-triangle" style="color:#c66;margin-right:5px;"></i>'
				: ( doc.committed
					? '<i class="ace-icon fa fa-check-circle" style="color:#2d9948;margin-right:5px;"></i>'
					: '<i class="ace-icon fa fa-floppy-o" style="color:#5b9bd5;margin-right:5px;"></i>' );

			var title = isError
				? 'Partial save — ' + doc.error
				: ( doc.type === 'minutes'
					? ( doc.committed ? 'Minutes committed to HCRQMS' : 'Minutes saved to HCRQMS' )
					: 'Agenda saved to HCRQMS' );

			if( isError ) {
				card.style.background   = '#fff8f0';
				card.style.borderColor  = '#e8b88a';
				card.style.color        = '#7a4000';
			}

			var detail = '';
			if( doc.doc_id )     detail += '<strong>' + doc.doc_id + '</strong>';
			if( doc.file_path )  detail += ' &mdash; <code style="font-size:11px">' + doc.file_path + '</code>';
			if( doc.commit_sha ) detail += '<br><span style="color:#888;font-size:11px;">Commit: ' + doc.commit_sha.substring(0,8) + '</span>';
			if( doc.dwg_id )     detail += '<br><span style="color:#888;font-size:11px;">Registered in Doctis as document #' + doc.dwg_id + '</span>';

			card.innerHTML = icon + '<strong>' + title + '</strong>' + ( detail ? '<br>' + detail : '' );
			$messages.appendChild(card);
			scrollToBottom();
		}

		/* ── Restore history array into the DOM (session load) ────────────── */
		function restoreHistory(history) {
			chatHistory = [];
			$messages.innerHTML = '';
			for( var i = 0; i < history.length; i++ ) {
				var turn = history[i];
				if( turn.role && turn.content ) {
					appendMessage(turn.role, turn.content);
					chatHistory.push({ role: turn.role, content: turn.content });
				}
			}
			scrollToBottom();
		}

		/* ── Typing indicator ─────────────────────────────────────────────── */
		var typingRowId = 'ai-typing-' + mode;

		function showTypingIndicator() {
			var row = document.createElement('div');
			row.className = 'ai-msg-row assistant';
			row.id = typingRowId;

			var avatar = document.createElement('div');
			avatar.className = 'ai-msg-avatar';
			avatar.innerHTML = mode === 'meeting'
				? '<i class="ace-icon fa fa-calendar-o"></i>'
				: '<i class="ace-icon fa fa-comments-o"></i>';

			var bubble = document.createElement('div');
			bubble.className = 'ai-msg-bubble ai-typing-indicator';
			bubble.innerHTML = '<span></span><span></span><span></span>';

			row.appendChild(avatar);
			row.appendChild(bubble);
			$messages.appendChild(row);
			scrollToBottom();
		}

		function removeTypingIndicator() {
			var row = document.getElementById(typingRowId);
			if( row ) row.parentNode.removeChild(row);
		}

		/* ── Send / busy state ────────────────────────────────────────────── */
		function setBusy(busy) {
			$sendBtn.disabled = busy;
			if( $stopBtn ) $stopBtn.disabled = !busy;
			$input.disabled = busy;
			if( busy ) {
				$sendBtn.innerHTML = '<i class="ace-icon fa fa-spinner fa-spin"></i> Sending';
			} else {
				$sendBtn.innerHTML = '<i class="ace-icon fa fa-paper-plane"></i> Send';
				$input.focus();
			}
		}

		/* ── Send a message ───────────────────────────────────────────────── */
		function sendMessage() {
			var text = $input.value.trim();
			if( !text || currentXhr ) return;

			/* Remove welcome message on first send */
			if( chatHistory.length === 0 && welcomeId ) {
				var welcome = document.getElementById(welcomeId);
				if( welcome ) welcome.parentNode.removeChild(welcome);
			}

			appendMessage('user', text);
			chatHistory.push({ role: 'user', content: text });
			$input.value = '';
			updateCharCount();

			showTypingIndicator();
			setBusy(true);
			setStatus('Thinking\u2026');

			var payload = JSON.stringify({
				action : 'chat',
				mode   : mode,
				system : systemPrompt,   // empty for meeting — server builds it
				history: chatHistory
			});

			currentXhr = new XMLHttpRequest();
			currentXhr.open('POST', 'ai_assist_api.php', true);
			currentXhr.setRequestHeader('Content-Type', 'application/json');
			currentXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

			currentXhr.onload = function() {
				var responseText = this.responseText;
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

				/* Accumulate token usage */
				if( data.usage ) {
					totalInputTok  += data.usage.input_tokens  || 0;
					totalOutputTok += data.usage.output_tokens || 0;
					updateTokenDisplay();
				}

				setStatus('');
				appendMessage('assistant', data.reply);
				chatHistory.push({ role: 'assistant', content: data.reply });

				/* Show saved-document notification for meeting mode */
				if( data.saved_document && ( data.saved_document.saved || data.saved_document.error ) ) {
					appendSavedDocCard(data.saved_document);
					if( cfg.onSavedDocument ) {
						cfg.onSavedDocument(data.saved_document);
					}
				}
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

		/* ── Stop in-flight request ───────────────────────────────────────── */
		function stopRequest() {
			if( currentXhr ) {
				currentXhr.abort();
				currentXhr = null;
				removeTypingIndicator();
				setBusy(false);
				setStatus('Stopped.');
				chatHistory.pop();
			}
		}

		/* ── Clear conversation ───────────────────────────────────────────── */
		function clearConversation() {
			chatHistory    = [];
			totalInputTok  = 0;
			totalOutputTok = 0;
			$messages.innerHTML = '';
			updateTokenDisplay();

			/* Ask the server to delete the stored session */
			var xhr = new XMLHttpRequest();
			xhr.open('POST', 'ai_assist_api.php', true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			xhr.send(JSON.stringify({ action: 'clear', mode: mode }));

			/* Restore compact welcome */
			if( compactWelcome ) {
				var welcome = document.createElement('div');
				welcome.className = 'ai-msg-row assistant';
				if( welcomeId ) welcome.id = welcomeId;
				welcome.innerHTML = compactWelcome;
				$messages.appendChild(welcome);
			}

			setStatus('');
			$input.value = '';
			updateCharCount();
			$input.focus();
		}

		/* ── Load persisted session on page open ──────────────────────────── */
		function loadSession() {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', 'ai_assist_api.php', true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

			xhr.onload = function() {
				var data;
				try { data = JSON.parse(this.responseText); } catch(e) { return; }
				if( data.history && data.history.length > 0 ) {
					var welcome = document.getElementById(welcomeId);
					if( welcome ) welcome.parentNode.removeChild(welcome);
					restoreHistory(data.history);
					setStatus('Previous conversation restored.');
					setTimeout(function() { setStatus(''); }, 3000);
				}
			};

			xhr.send(JSON.stringify({ action: 'load', mode: mode }));
		}

		/* ── Event listeners ──────────────────────────────────────────────── */
		$sendBtn.addEventListener('click', sendMessage);
		if( $stopBtn )  $stopBtn.addEventListener('click',  stopRequest);
		if( $clearBtn ) $clearBtn.addEventListener('click', clearConversation);

		$input.addEventListener('keydown', function(e) {
			if( e.keyCode === 13 && (e.ctrlKey || e.shiftKey) ) {
				e.preventDefault();
				sendMessage();
			}
		});

		$input.addEventListener('input', updateCharCount);

		/* ── Initialise ───────────────────────────────────────────────────── */
		loadSession();
		scrollToBottom();

		return { send: sendMessage, clear: clearConversation };
	}


	/* ═══════════════════════════════════════════════════════════════════════ *
	 * Help tab instance                                                       *
	 * ═══════════════════════════════════════════════════════════════════════ */
	var $helpMessages = document.getElementById('ai-chat-messages');
	if( $helpMessages ) {
		/* Context injected by ai_assist_page.php via data attributes */
		var ctxProject  = $helpMessages.dataset.project  || '';
		var ctxDocCount = $helpMessages.dataset.docCount || '';

		var HELP_SYSTEM_PROMPT =
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
			'specific Doctis implementation detail, say so.' +
			( ctxProject
				? '\n\nThe user is currently working in the Doctis project \u201c' + ctxProject + '\u201d' +
				  ( ctxDocCount ? ' which contains ' + ctxDocCount + ' document(s)' : '' ) + '.'
				: '' );

		createChatSession({
			mode          : 'help',
			systemPrompt  : HELP_SYSTEM_PROMPT,
			messages      : $helpMessages,
			input         : document.getElementById('ai-chat-input'),
			sendBtn       : document.getElementById('ai-send-btn'),
			stopBtn       : document.getElementById('ai-stop-btn'),
			clearBtn      : document.getElementById('ai-clear-btn'),
			statusTxt     : document.getElementById('ai-status-text'),
			charCount     : document.getElementById('ai-char-count'),
			tokenTxt      : document.getElementById('ai-token-count'),
			welcomeId     : 'ai-welcome-msg',
			compactWelcome:
				'<div class="ai-msg-avatar"><i class="ace-icon fa fa-comments-o"></i></div>' +
				'<div class="ai-msg-bubble">Hello! How can I help you with Doctis today?</div>',
		});

		document.getElementById('ai-chat-input').focus();
	}


	/* ═══════════════════════════════════════════════════════════════════════ *
	 * Meeting tab instance                                                    *
	 * ═══════════════════════════════════════════════════════════════════════ */
	var $meetingMessages = document.getElementById('ai-meeting-messages');
	if( $meetingMessages ) {
		createChatSession({
			mode          : 'meeting',
			systemPrompt  : '',   // empty — server builds the meeting system prompt
			messages      : $meetingMessages,
			input         : document.getElementById('ai-meeting-input'),
			sendBtn       : document.getElementById('ai-meeting-send-btn'),
			stopBtn       : document.getElementById('ai-meeting-stop-btn'),
			clearBtn      : document.getElementById('ai-meeting-clear-btn'),
			statusTxt     : document.getElementById('ai-meeting-status-text'),
			charCount     : document.getElementById('ai-meeting-char-count'),
			tokenTxt      : document.getElementById('ai-meeting-token-count'),
			welcomeId     : 'ai-meeting-welcome-msg',
			compactWelcome:
				'<div class="ai-msg-avatar"><i class="ace-icon fa fa-calendar-o"></i></div>' +
				'<div class="ai-msg-bubble">Hello! Type <em>agenda</em> or <em>minutes</em> to start a new meeting record.</div>',
		});
	}


	/* ═══════════════════════════════════════════════════════════════════════ *
	 * Tab management                                                          *
	 * ═══════════════════════════════════════════════════════════════════════ */

	/* Restore active tab from URL hash on load */
	(function() {
		var hash = window.location.hash;
		if( hash ) {
			var link = document.querySelector('#ai-tab-nav a[href="' + hash + '"]');
			if( link && window.jQuery ) { jQuery(link).tab('show'); }
		}
	})();

	/* Update URL hash when tab changes */
	document.querySelectorAll('#ai-tab-nav a[data-toggle="tab"]').forEach(function(el) {
		el.addEventListener('click', function() {
			var href = this.getAttribute('href');
			if( href ) history.replaceState(null, null, href);
		});
	});

})();
