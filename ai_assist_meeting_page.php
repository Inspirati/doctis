<?php
# Doctis — AI Assistant Meeting tab panel
#
# Included by ai_assist_page.php.  Renders the Meeting tab <div> only.
# Expects these variables from the including scope:
#   $t_api_configured  bool    True when $g_anthropic_api_key is set
#   $t_user_name       string  Display name of the current user (may be blank)
?>

	<!-- ═══ MEETING TAB ══════════════════════════════════════════════════════ -->
	<div class="tab-pane" id="tab-meeting">

		<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-calendar', 'ace-icon' ); ?>
				<?php echo lang_get( 'ai_assist_title' ) ?> &mdash; <?php echo lang_get( 'ai_assist_tab_meeting' ) ?>
			</h4>
			<div class="widget-toolbar">
				<button class="btn btn-minier btn-default" id="ai-meeting-clear-btn" title="Clear conversation">
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
			</div>
<?php else: ?>
			<div class="alert alert-info" style="padding: 6px 12px; margin-bottom: 10px; font-size: 12px;">
				<?php print_icon( 'fa-info-circle', 'ace-icon' ); ?>
				<strong>Meeting Assistant.</strong>
				Guided agenda and minutes capture following the HC-Robotics meeting template (TMPL-SYS-001).
				<?php if( is_blank( config_get_global( 'hcrqms_repo_path' ) ) ): ?>
				<span style="color:#888;">
					<?php print_icon( 'fa-exclamation-circle', 'ace-icon' ); ?>
					HCRQMS repository not configured — documents will not be saved automatically.
					Set <code>$g_hcrqms_repo_path</code> in <code>config/config_inc.php</code> to enable file save.
				</span>
				<?php endif; ?>
			</div>
<?php endif; ?>

			<!-- Message thread -->
			<div id="ai-meeting-messages" role="log" aria-live="polite" aria-label="Meeting assistant conversation"
				style="height:440px;overflow-y:auto;padding:14px 10px;background:#f8f9fb;border:1px solid #dde3ea;border-radius:4px;display:flex;flex-direction:column;gap:10px;">
				<!-- Welcome message -->
				<div class="ai-msg-row assistant" id="ai-meeting-welcome-msg">
					<div class="ai-msg-avatar">
						<?php print_icon( 'fa-calendar-o', 'ace-icon' ); ?>
					</div>
					<div class="ai-msg-bubble">
						Hello<?php if( !is_blank( $t_user_name ) ) echo ', ' . string_display_line( $t_user_name ); ?>!
						I&rsquo;m the Doctis Meeting Assistant. I can help you produce a QMS-compliant meeting record:
						<ul style="margin: 6px 0 0 16px; padding: 0;">
							<li>Set up an <strong>agenda</strong> before a meeting</li>
							<li>Complete the <strong>minutes</strong> after a meeting</li>
						</ul>
						Type <em>agenda</em> or <em>minutes</em> to get started.
					</div>
				</div>
			</div><!-- /#ai-meeting-messages -->

			<!-- Status bar -->
			<div id="ai-meeting-status-bar" style="font-size:11px;color:#999;min-height:18px;margin-top:4px;">
				<span id="ai-meeting-status-text"></span>
				<span id="ai-meeting-token-count" style="float:right;margin-left:10px;color:#bbb;"></span>
				<span id="ai-meeting-char-count" style="float:right;"></span>
			</div>

			<!-- Input area -->
			<div style="margin-top:10px;">
				<textarea
					id="ai-meeting-input"
					class="form-control"
					rows="3"
					placeholder="Type your message here… (Ctrl+Enter or Shift+Enter to send)"
					maxlength="4000"
					aria-label="Message input"
					style="resize:vertical;min-height:70px;font-size:13px;border-radius:4px;"
				></textarea>
				<div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
					<span style="font-size: 11px; color: #aaa;">
						<?php print_icon( 'fa-keyboard-o', 'ace-icon' ); ?>
						Ctrl+Enter to send &nbsp;&bull;&nbsp; Shift+Enter for new line
					</span>
					<div>
						<button class="btn btn-sm btn-white btn-default" id="ai-meeting-stop-btn" disabled style="margin-right:4px;">
							<?php print_icon( 'fa-stop-circle-o', 'ace-icon' ); ?> Stop
						</button>
						<button class="btn btn-sm btn-primary" id="ai-meeting-send-btn"<?php if( !$t_api_configured ) echo ' disabled title="API key not configured"' ?>>
							<?php print_icon( 'fa-paper-plane', 'ace-icon' ); ?> Send
						</button>
					</div>
				</div>
			</div><!-- /.input-area -->

		</div><!-- /.widget-main -->
		</div><!-- /.widget-body -->
		</div><!-- /.widget-box -->

	</div><!-- /#tab-meeting -->
