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
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'project_api.php' );

auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );

$t_api_configured = !is_blank( config_get_global( 'anthropic_api_key' ) );

$t_user_id   = auth_get_current_user_id();
$t_user_name = user_get_field( $t_user_id, 'realname' );
if( is_blank( $t_user_name ) ) {
	$t_user_name = user_get_field( $t_user_id, 'username' );
}

# ── Context injection — current project ───────────────────────────────────
$t_project_id   = helper_get_current_project();
$t_project_name = '';
$t_doc_count    = '';
if( $t_project_id > 0 && $t_project_id !== ALL_PROJECTS ) {
	$t_project_name = project_get_field( $t_project_id, 'name' );
	$t_result       = db_query(
		'SELECT COUNT(*) FROM {dwg} WHERE project_id = ' . db_param(),
		[ $t_project_id ]
	);
	$t_doc_count = (string) db_result( $t_result );
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
#ai-token-count {
	float: right;
	margin-left: 10px;
	color: #bbb;
}

/* Copy-to-clipboard button on assistant bubbles */
.ai-copy-btn {
	display: block;
	margin-top: 6px;
	padding: 2px 7px;
	font-size: 11px;
	color: #aaa;
	background: none;
	border: 1px solid #dde3ea;
	border-radius: 3px;
	cursor: pointer;
	line-height: 1.4;
}
.ai-copy-btn:hover { color: #5b9bd5; border-color: #5b9bd5; }

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
			<div id="ai-chat-messages" role="log" aria-live="polite" aria-label="Conversation"
				data-project="<?php echo string_attribute( $t_project_name ) ?>"
				data-doc-count="<?php echo (int) $t_doc_count ?>">
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
				<span id="ai-token-count"></span>
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

<script src="<?php echo helper_mantis_url( 'js/ai_assist.js' ) ?>"></script>

<?php
layout_page_end();
