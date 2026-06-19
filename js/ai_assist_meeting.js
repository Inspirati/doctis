/* ── AI Assistant — Meeting tab instance ─────────────────────────────────── *
 * Loaded by ai_assist_page.php after ai_assist.js.                            *
 * Binds a chat session to the Meeting tab DOM elements.                        *
 *                                                                              *
 * The system prompt is built server-side in ai_assist_meeting_api.php         *
 * (includes department config and HCRQMS template; never sent to browser).    *
 * ─────────────────────────────────────────────────────────────────────────── */
(function() {
	'use strict';

	var $meetingMessages = document.getElementById('ai-meeting-messages');
	if( !$meetingMessages ) return;

	window.AiAssist.createChatSession({
		mode          : 'meeting',
		systemPrompt  : '',   // server builds the meeting system prompt (ai_assist_meeting_api.php)
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

})();
