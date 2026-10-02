<?php
# Doctis — AI Assistant Meeting tab panel
#
# Included by ai_assist_page.php.  Renders the Meeting tab <div> only.
# Expects these variables from the including scope:
#   $t_api_configured  bool    True when $g_anthropic_api_key is set
#   $t_user_name       string  Display name of the current user (may be blank)
#
# Opened as ai_assist_page.php?meeting_id=N#tab-meeting (Write Minutes) to
# write the minutes of meeting N, or ?series_of=N (Plan Next Meeting) to plan
# the meeting that follows N.

require_once( 'ai_assist_meeting_api.php' );

$t_meeting_focus = ai_assist_meeting_focus(
	auth_get_current_user_id(), gpc_get_int( 'meeting_id', 0 )
);
$t_meeting_series = $t_meeting_focus === null
	? ai_assist_meeting_series_base( auth_get_current_user_id(), gpc_get_int( 'series_of', 0 ) )
	: null;
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
				Your meetings are listed under <a href="my_view_meeting_page.php"><?php echo lang_get( 'my_view_meeting_link' ) ?></a>.
				<?php if( meeting_project_id() === 0 ): ?>
				<span style="color:#888;">
					<?php print_icon( 'fa-exclamation-circle', 'ace-icon' ); ?>
					No meeting project configured — meetings are recorded but no document is stored.
					Set <code>$g_meeting_project_id</code> in <code>config/config_inc.php</code> to enable document storage.
				</span>
				<?php endif; ?>
			</div>
<?php endif; ?>

			<!-- Message thread -->
			<div id="ai-meeting-messages" role="log" aria-live="polite" aria-label="Meeting assistant conversation"
				data-meeting-id="<?php echo $t_meeting_focus === null ? 0 : (int)$t_meeting_focus['id'] ?>"
				data-series-of="<?php echo $t_meeting_series === null ? 0 : (int)$t_meeting_series['id'] ?>"
				style="height:440px;overflow-y:auto;padding:14px 10px;background:#f8f9fb;border:1px solid #dde3ea;border-radius:4px;display:flex;flex-direction:column;gap:10px;">
				<!-- Welcome message -->
				<div class="ai-msg-row assistant" id="ai-meeting-welcome-msg">
					<div class="ai-msg-avatar">
						<?php print_icon( 'fa-calendar-o', 'ace-icon' ); ?>
					</div>
					<div class="ai-msg-bubble">
						Hello<?php if( !is_blank( $t_user_name ) ) echo ', ' . string_display_line( $t_user_name ); ?>!
<?php if( $t_meeting_focus !== null ): ?>
						Let&rsquo;s write the minutes for
						<strong><?php echo string_display_line( $t_meeting_focus['doc_ref'] ) ?></strong>
						&mdash; <?php echo string_display_line( $t_meeting_focus['title'] ) ?>.
						<br><br>
						Start with who attended and who sent apologies, then give me your notes on each agenda item &mdash; rough notes are fine.
<?php elseif( $t_meeting_series !== null ): ?>
						Let&rsquo;s plan the meeting after
						<strong><?php echo string_display_line( $t_meeting_series['doc_ref'] ) ?></strong>
						&mdash; <?php echo string_display_line( $t_meeting_series['title'] ) ?>.
						<br><br>
						I&rsquo;ll keep the same people, place and format and carry forward the open actions.
						Tell me the date and time (or say <em>same time next week</em>) and anything that changes.
<?php else: ?>
						I&rsquo;m the Doctis Meeting Assistant. Tell me about the meeting in one sentence and I&rsquo;ll draft the agenda.
						<br><br>
						<span style="color:#666;font-size:12px;">
							<strong>Example:</strong> <em>&ldquo;Agenda for Phil, Sanjay and Sudheer on 20 June at 11am to discuss Doctis development progress, less than one hour.&rdquo;</em>
						</span>
						<br><br>
						Or type <em>minutes</em> to complete the record after a meeting, or choose <em>Write minutes</em> under
						<a href="my_view_meeting_page.php"><?php echo lang_get( 'my_view_meeting_link' ) ?></a>.
<?php endif; ?>
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
