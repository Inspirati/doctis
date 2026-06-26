/* ── AI Assistant — Help tab instance ────────────────────────────────────── *
 * Loaded by ai_assist_page.php after ai_assist.js.                            *
 * Binds a chat session to the Help tab DOM elements.                           *
 *                                                                              *
 * The system prompt is built server-side in ai_assist_help_api.php            *
 * (injects current project context without exposing it to the browser).        *
 * ─────────────────────────────────────────────────────────────────────────── */
(function() {
	'use strict';

	var $helpMessages = document.getElementById('ai-chat-messages');
	if( !$helpMessages ) return;

	window.AiAssist.createChatSession({
		mode          : 'help',
		systemPrompt  : '',   // server builds the help system prompt (ai_assist_help_api.php)
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

})();
