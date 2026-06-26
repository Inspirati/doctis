<?php
# Doctis — AI Assistant Help tab panel
#
# Included by ai_assist_page.php.  Renders the Help tab <div> only.
# Expects these variables from the including scope:
#   $t_api_configured  bool    True when $g_anthropic_api_key is set
#   $t_user_name       string  Display name of the current user (may be blank)
?>

	<!-- ═══ HELP TAB ════════════════════════════════════════════════════════ -->
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
