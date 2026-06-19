<?php
# Doctis — AI Assistant page
#
# Page shell: authentication, shared CSS, tab navigation, and script loading.
# The two active tab panels are rendered by separate include files:
#
#   ai_assist_help_page.php    — Help tab panel
#   ai_assist_meeting_page.php — Meeting tab panel
#
# JavaScript is split across three external files (CSP: script-src 'self'):
#
#   js/ai_assist.js         — shared: renderMarkdown, createChatSession factory, tab management
#   js/ai_assist_help.js    — Help tab instance
#   js/ai_assist_meeting.js — Meeting tab instance
#
# AJAX backend: ai_assist_api.php
#   ai_assist_help_api.php    — Help mode system prompt (server-side)
#   ai_assist_meeting_api.php — Meeting mode system prompt and document processing
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
require_api( 'string_api.php' );
require_api( 'user_api.php' );

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
/* ── AI Assistant chat styles (shared across all tabs) ───────────────────── */
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
.ai-msg-row.user      { flex-direction: row-reverse; }
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

<!-- ── Tab navigation ──────────────────────────────────────────────────────── -->
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

<!-- ── Tab content ────────────────────────────────────────────────────────── -->
<div class="tab-content">

<?php include 'ai_assist_help_page.php'; ?>

<?php include 'ai_assist_meeting_page.php'; ?>


	<!-- ═══ SOP TAB ══════════════════════════════════════════════════════════ -->
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


	<!-- ═══ OTHER TAB ════════════════════════════════════════════════════════ -->
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
<script src="<?php echo helper_mantis_url( 'js/ai_assist_help.js' ) ?>"></script>
<script src="<?php echo helper_mantis_url( 'js/ai_assist_meeting.js' ) ?>"></script>

<?php
layout_page_end();
