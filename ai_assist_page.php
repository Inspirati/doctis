<?php
# Doctis — AI Assistant page
#
# Hosts the built-in Claude-powered assistant.  Four tab panels:
#   Help     — Doctis / QMS usage questions (proof-of-concept)
#   Meeting  — Meeting agenda and minutes assistant (planned)
#   SOP      — Standard Operating Procedure interview tool (planned)
#   Other    — General / uncategorised queries (planned)
#
# The active tab is preserved across page loads via the URL fragment (#tab-help
# etc.).  The conversation history is held in browser memory (JS array) for the
# duration of the session and is not persisted server-side in this initial
# implementation.
#
# Backend: ai_assist_api.php (AJAX endpoint; calls Anthropic Messages API).
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );

$t_api_configured = !is_blank( config_get_global( 'anthropic_api_key' ) );

$t_user_id   = auth_get_current_user_id();
$t_user_name = user_get_field( $t_user_id, 'realname' );
if( is_blank( $t_user_name ) ) {
	$t_user_name = user_get_field( $t_user_id, 'username' );
}

layout_page_header( lang_get( 'ai_assist_title' ) );
layout_page_begin( 'ai_assist_page.php' );
?>

<style>
/* ── AI Assistant chat styles ────────────────────────────────────────────── */
#ai-chat-messages {
	height: 440px;
	overflow-y: auto;
	padding: 14px 10px;
	background: #f8f9fb;
	border: 1px solid #dde3ea;
	border-radius: 4px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.ai-msg-row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
.ai-msg-row.user     { flex-direction: row-reverse; }
.ai-msg-row.assistant { flex-direction: row; }

.ai-msg-avatar {
	width: 30px;
	height: 30px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	font-size: 14px;
}
.ai-msg-row.user      .ai-msg-avatar { background: #5b9bd5; color: #fff; }
.ai-msg-row.assistant .ai-msg-avatar { background: #e2e8f0; color: #5b9bd5; }

.ai-msg-bubble {
	max-width: 78%;
	padding: 8px 13px;
	border-radius: 14px;
	font-size: 13px;
	line-height: 1.5;
	word-wrap: break-word;
	white-space: pre-wrap;
}
.ai-msg-row.user      .ai-msg-bubble {
	background: #5b9bd5;
	color: #fff;
	border-bottom-right-radius: 3px;
}
.ai-msg-row.assistant .ai-msg-bubble {
	background: #fff;
	color: #333;
	border: 1px solid #dde3ea;
	border-bottom-left-radius: 3px;
}

.ai-typing-indicator span {
	display: inline-block;
	width: 7px; height: 7px;
	border-radius: 50%;
	background: #aaa;
	margin-right: 3px;
	animation: ai-bounce 1.2s infinite;
}
.ai-typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.ai-typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
@keyframes ai-bounce {
	0%, 60%, 100% { transform: translateY(0); }
	30%           { transform: translateY(-5px); }
}

#ai-input-area {
	margin-top: 10px;
}
#ai-chat-input {
	resize: vertical;
	min-height: 70px;
	font-size: 13px;
	border-radius: 4px;
}
#ai-status-bar {
	font-size: 11px;
	color: #999;
	min-height: 18px;
	margin-top: 4px;
}
#ai-char-count {
	float: right;
}

/* Tab panel: remove top-border radius that clashes with nav-tabs */
.tab-content > .tab-pane > .widget-box {
	border-top-left-radius: 0;
}

/* Coming-soon panels */
.ai-coming-soon {
	text-align: center;
	padding: 60px 20px;
	color: #aaa;
}
.ai-coming-soon .fa { font-size: 48px; margin-bottom: 16px; }
.ai-coming-soon h4  { color: #bbb; font-weight: 300; }
</style>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<!-- ── Tab navigation ──────────────────────────────────────────────────── -->
<ul class="nav nav-tabs padding-18" id="ai-tab-nav">
	<li class="active">
		<a href="#tab-help"    data-toggle="tab"><?php echo lang_get( 'ai_assist_tab_help' ) ?></a>
	</li>
	<li>
		<a href="#tab-meeting" data-toggle="tab"><?php echo lang_get( 'ai_assist_tab_meeting' ) ?></a>
	</li>
	<li>
		<a href="#tab-sop"     data-toggle="tab"><?php echo lang_get( 'ai_assist_tab_sop' ) ?></a>
	</li>
	<li>
		<a href="#tab-other"   data-toggle="tab"><?php echo lang_get( 'ai_assist_tab_other' ) ?></a>
	</li>
</ul>

<!-- ── Tab content ────────────────────────────────────────────────────── -->
<div class="tab-content">

	<!-- ═══ HELP TAB ═══════════════════════════════════════════════════ -->
	<div class="tab-pane active" id="tab-help">

		<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-comments', 'ace-icon' ); ?>
				<?php echo lang_get( 'ai_assist_title' ) ?> &mdash; <?php echo lang_get( 'ai_assist_tab_help' ) ?>
			</h4>
			<div class="widget-toolbar">
				<button class="btn btn-minier btn-default" id="ai-clear-btn" title="Clear conversation">
					<?php print_icon( 'fa-trash-o', 'ace-icon' ); ?> Clear
				</button>
			</div>
		</div><!-- /.widget-header -->

		<div class="widget-body">
		<div class="widget-main">

			<!-- Context strip / configuration notice -->
<?php if( !$t_api_configured ): ?>
			<div class="alert alert-warning" style="padding: 8px 14px; margin-bottom: 10px; font-size: 12px;">
				<?php print_icon( 'fa-exclamation-triangle', 'ace-icon' ); ?>
				<strong>AI Assistant not configured.</strong>
				Set <code>$g_anthropic_api_key</code> in <code>config/config_inc.php</code> to enable this feature.
				The chat interface is shown below for layout preview only — messages cannot be sent until the key is set.
			</div>
<?php else: ?>
			<div class="alert alert-info" style="padding: 6px 12px; margin-bottom: 10px; font-size: 12px;">
				<?php print_icon( 'fa-info-circle', 'ace-icon' ); ?>
				<strong>Help mode.</strong>
				Ask questions about using Doctis, the document review workflow, or the QMS system.
				Conversations are not saved when you leave this page.
			</div>
<?php endif; ?>

			<!-- Message thread -->
			<div id="ai-chat-messages" role="log" aria-live="polite" aria-label="Conversation">
				<!-- Welcome message (assistant) -->
				<div class="ai-msg-row assistant" id="ai-welcome-msg">
					<div class="ai-msg-avatar">
						<?php print_icon( 'fa-comments-o', 'ace-icon' ); ?>
					</div>
					<div class="ai-msg-bubble">
						Hello<?php if( !is_blank( $t_user_name ) ) echo ', ' . string_display_line( $t_user_name ); ?>! I&rsquo;m the Doctis AI Assistant. I can help you with:
						<ul style="margin: 6px 0 0 16px; padding: 0;">
							<li>Using Doctis features and document workflows</li>
							<li>Understanding document statuses and review cycles</li>
							<li>QMS document control questions</li>
							<li>Finding the right page or function</li>
						</ul>
						What would you like to know?
					</div>
				</div>
			</div><!-- /#ai-chat-messages -->

			<!-- Status bar -->
			<div id="ai-status-bar">
				<span id="ai-status-text"></span>
				<span id="ai-char-count"></span>
			</div>

			<!-- Input area -->
			<div id="ai-input-area">
				<textarea
					id="ai-chat-input"
					class="form-control"
					rows="3"
					placeholder="Type your question here… (Ctrl+Enter or Shift+Enter to send)"
					maxlength="4000"
					aria-label="Message input"
				></textarea>
				<div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
					<span style="font-size: 11px; color: #aaa;">
						<?php print_icon( 'fa-keyboard-o', 'ace-icon' ); ?>
						Ctrl+Enter to send &nbsp;&bull;&nbsp; Shift+Enter for new line
					</span>
					<div>
						<button class="btn btn-sm btn-white btn-default" id="ai-stop-btn" disabled style="margin-right:4px;">
							<?php print_icon( 'fa-stop-circle-o', 'ace-icon' ); ?> Stop
						</button>
						<button class="btn btn-sm btn-primary" id="ai-send-btn"<?php if( !$t_api_configured ) echo ' disabled title="API key not configured"' ?>>
							<?php print_icon( 'fa-paper-plane', 'ace-icon' ); ?> Send
						</button>
					</div>
				</div>
			</div><!-- /#ai-input-area -->

		</div><!-- /.widget-main -->
		</div><!-- /.widget-body -->
		</div><!-- /.widget-box -->

	</div><!-- /#tab-help -->


	<!-- ═══ MEETING TAB ═════════════════════════════════════════════════ -->
	<div class="tab-pane" id="tab-meeting">
		<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-calendar', 'ace-icon' ); ?>
				<?php echo lang_get( 'ai_assist_title' ) ?> &mdash; <?php echo lang_get( 'ai_assist_tab_meeting' ) ?>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main">
			<div class="ai-coming-soon">
				<?php print_icon( 'fa-calendar-o', 'ace-icon' ); ?>
				<h4>Meeting Assistant</h4>
				<p>Guided agenda and minutes capture (ENG-TASK-002). Coming soon.</p>
			</div>
		</div>
		</div>
		</div>
	</div><!-- /#tab-meeting -->


	<!-- ═══ SOP TAB ═════════════════════════════════════════════════════ -->
	<div class="tab-pane" id="tab-sop">
		<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-file-text-o', 'ace-icon' ); ?>
				<?php echo lang_get( 'ai_assist_title' ) ?> &mdash; <?php echo lang_get( 'ai_assist_tab_sop' ) ?>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main">
			<div class="ai-coming-soon">
				<?php print_icon( 'fa-file-text-o', 'ace-icon' ); ?>
				<h4>SOP Interview Tool</h4>
				<p>AI-assisted Standard Operating Procedure knowledge capture (GUID-SYS-007). Coming soon.</p>
			</div>
		</div>
		</div>
		</div>
	</div><!-- /#tab-sop -->


	<!-- ═══ OTHER TAB ════════════════════════════════════════════════════ -->
	<div class="tab-pane" id="tab-other">
		<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-ellipsis-h', 'ace-icon' ); ?>
				<?php echo lang_get( 'ai_assist_title' ) ?> &mdash; <?php echo lang_get( 'ai_assist_tab_other' ) ?>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main">
			<div class="ai-coming-soon">
				<?php print_icon( 'fa-ellipsis-h', 'ace-icon' ); ?>
				<h4>General Assistant</h4>
				<p>Uncategorised queries and general assistance. Coming soon.</p>
			</div>
		</div>
		</div>
		</div>
	</div><!-- /#tab-other -->


</div><!-- /.tab-content -->
</div><!-- /.col-md-12 -->

<script>
/* ── AI Assistant — Help tab JavaScript ─────────────────────────────────── */
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
		'- Understanding document statuses (pending → received → triage → JoS → ' +
		'  assigned to → review → rework → independent review → accepted → ' +
		'  incorporated → archived)\n' +
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

	function escapeHtml(s) {
		return s.replace(/&/g,'&amp;').replace(/</g,'&lt;')
		        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	/* Simple markdown-lite: bold, code spans, fenced code, newlines */
	function renderMarkdown(text) {
		// Fenced code blocks
		text = text.replace(/```[\w]*\n?([\s\S]*?)```/g,
			'<pre style="margin:6px 0;padding:8px;background:#f4f4f4;border-radius:4px;font-size:11px;overflow-x:auto"><code>$1</code></pre>');
		// Inline code
		text = text.replace(/`([^`]+)`/g,
			'<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:11px">$1</code>');
		// Bold
		text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		// Newlines → <br> (outside pre tags)
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
		return bubble;   // returned so typing indicator can update it in place
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

		// Append to UI and history
		appendMessage('user', text);
		chatHistory.push({ role: 'user', content: text });
		$input.value = '';
		updateCharCount();

		showTypingIndicator();
		setBusy(true);
		setStatus('Thinking\u2026');

		// POST to backend
		var payload = JSON.stringify({
			mode    : 'help',
			system  : SYSTEM_PROMPT,
			history : chatHistory,
		});

		currentXhr = new XMLHttpRequest();
		currentXhr.open('POST', 'ai_assist_api.php', true);
		currentXhr.setRequestHeader('Content-Type', 'application/json');
		currentXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

		currentXhr.onload = function() {
			removeTypingIndicator();
			setBusy(false);
			currentXhr = null;

			var data;
			try { data = JSON.parse(currentXhr.responseText); }
			catch(e) { data = { error: 'Invalid response from server.' }; }

			if( data.error ) {
				setStatus('Error: ' + data.error);
				appendMessage('assistant',
					'\u26a0\ufe0f Sorry, something went wrong: ' + data.error);
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
			setStatus('Network error — check your connection.');
			appendMessage('assistant',
				'\u26a0\ufe0f Could not reach the server. Please try again.');
		};

		currentXhr.onreadystatechange = function() {
			if( currentXhr && currentXhr.readyState === 4 ) {
				// handled by onload / onerror
			}
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
			chatHistory.pop();   // remove the unanswered user turn
		}
	}

	/* ── Clear conversation ─────────────────────────────────────────────── */
	function clearConversation() {
		chatHistory = [];
		// Remove all messages except the welcome message
		var welcome = document.getElementById('ai-welcome-msg');
		while( $messages.lastChild && $messages.lastChild !== welcome ) {
			$messages.removeChild($messages.lastChild);
		}
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
		// Ctrl+Enter or Shift+Enter → send
		if( e.keyCode === 13 && (e.ctrlKey || e.shiftKey) ) {
			e.preventDefault();
			sendMessage();
		}
	});

	$input.addEventListener('input', updateCharCount);

	// Restore active tab from URL hash
	(function() {
		var hash = window.location.hash;
		if( hash ) {
			var link = document.querySelector('#ai-tab-nav a[href="' + hash + '"]');
			if( link ) { $(link).tab('show'); }
		}
	})();

	// Update URL hash when tab changes (for bookmarkability)
	document.querySelectorAll('#ai-tab-nav a[data-toggle="tab"]').forEach(function(el) {
		el.addEventListener('shown.bs.tab', function(e) {
			history.replaceState(null, null, e.target.getAttribute('href'));
		});
	});

	// Focus input on page load
	$input.focus();
	scrollToBottom();

})();
</script>

<?php
layout_page_end();
