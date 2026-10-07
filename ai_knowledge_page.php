<?php
# Doctis — AI Assistant knowledge base.
#
# Everyone with access to the AI Assistant can browse the entries the
# assistant answers from, and add one. Reviewers ($g_ai_knowledge_review_threshold,
# MANAGER) publish, edit, retire and delete entries; authors can delete their
# own unverified entries. Changes are posted to ai_knowledge_update.php.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'ai_knowledge_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'layout_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );
html_robots_noindex();

$t_user_id = auth_get_current_user_id();
$t_reviewer = ai_knowledge_user_can_review( $t_user_id );
$f_status = gpc_get_int( 'status', 0 );
$f_edit = gpc_get_int( 'edit', 0 );

$t_entries = ai_knowledge_list( $t_user_id, $t_reviewer );
if( $f_status > 0 ) {
	$t_entries = array_values( array_filter( $t_entries, function( $p_e ) use ( $f_status ) {
		return (int)$p_e['status'] === $f_status;
	} ) );
}
$t_counts = array( AI_KNOWLEDGE_UNVERIFIED => 0, AI_KNOWLEDGE_PUBLISHED => 0, AI_KNOWLEDGE_RETIRED => 0 );
foreach( ai_knowledge_list( $t_user_id, $t_reviewer ) as $t_e ) {
	$t_counts[(int)$t_e['status']]++;
}
$t_edit = $f_edit > 0 && $t_reviewer ? ai_knowledge_get( $f_edit ) : null;

# Projects the user may tie an entry to.
$t_projects = array( 0 => lang_get( 'ai_knowledge_scope_all' ) );
foreach( user_get_accessible_projects( $t_user_id ) as $t_pid ) {
	$t_projects[(int)$t_pid] = project_get_name( (int)$t_pid );
}

/**
 * Print the add/edit form.
 *
 * @param array|null $p_entry    Entry being edited, or null to add.
 * @param array      $p_projects project_id => name.
 * @return void
 */
function ai_knowledge_print_form( ?array $p_entry, array $p_projects ) {
	$t_val = function( $p_field ) use ( $p_entry ) { return $p_entry === null ? '' : (string)$p_entry[$p_field]; };
?>
	<form method="post" action="ai_knowledge_update.php">
		<?php echo form_security_field( 'ai_knowledge_update' ) ?>
		<input type="hidden" name="action" value="<?php echo $p_entry === null ? 'add' : 'edit' ?>" />
		<input type="hidden" name="id" value="<?php echo $p_entry === null ? 0 : (int)$p_entry['id'] ?>" />
		<table class="table table-bordered table-condensed">
			<tr>
				<th class="category width-20"><label for="kb_question"><?php echo lang_get( 'ai_knowledge_question' ) ?></label></th>
				<td><input type="text" id="kb_question" name="question" class="input-sm" size="80" maxlength="255" required
					value="<?php echo string_attribute( $t_val( 'question' ) ) ?>" /></td>
			</tr>
			<tr>
				<th class="category"><label for="kb_answer"><?php echo lang_get( 'ai_knowledge_answer' ) ?></label></th>
				<td><textarea id="kb_answer" name="answer" class="form-control" rows="4" maxlength="4000" required><?php
					echo string_html_specialchars( $t_val( 'answer' ) ) ?></textarea></td>
			</tr>
			<tr>
				<th class="category"><label for="kb_keywords"><?php echo lang_get( 'ai_knowledge_keywords' ) ?></label></th>
				<td><input type="text" id="kb_keywords" name="keywords" class="input-sm" size="80" maxlength="255"
					value="<?php echo string_attribute( $t_val( 'keywords' ) ) ?>" />
					<span class="text-muted small"><?php echo lang_get( 'ai_knowledge_keywords_hint' ) ?></span></td>
			</tr>
			<tr>
				<th class="category"><label for="kb_page"><?php echo lang_get( 'ai_knowledge_page_field' ) ?></label></th>
				<td><input type="text" id="kb_page" name="page" class="input-sm" size="40" maxlength="255"
					value="<?php echo string_attribute( $t_val( 'page' ) ) ?>" placeholder="my_view_org_page.php" />
					<span class="text-muted small"><?php echo lang_get( 'ai_knowledge_page_hint' ) ?></span></td>
			</tr>
			<tr>
				<th class="category"><label for="kb_project"><?php echo lang_get( 'ai_knowledge_scope' ) ?></label></th>
				<td><select id="kb_project" name="project_id" class="input-sm">
<?php foreach( $p_projects as $t_pid => $t_name ): ?>
					<option value="<?php echo (int)$t_pid ?>"<?php echo (int)$t_val( 'project_id' ) === (int)$t_pid ? ' selected="selected"' : '' ?>><?php
						echo string_display_line( $t_name ) ?></option>
<?php endforeach; ?>
				</select></td>
			</tr>
		</table>
		<div class="padding-8">
			<input type="submit" class="btn btn-primary btn-sm btn-white btn-round"
				value="<?php echo lang_get( $p_entry === null ? 'ai_knowledge_add_button' : 'ai_knowledge_save_button' ) ?>" />
<?php if( $p_entry !== null ): ?>
			<a class="btn btn-sm btn-default btn-white btn-round" href="ai_knowledge_page.php#kb-<?php echo (int)$p_entry['id'] ?>"><?php echo lang_get( 'meeting_back' ) ?></a>
<?php endif; ?>
		</div>
	</form>
<?php
}

/**
 * A small POST button for an entry action.
 *
 * @param int    $p_id
 * @param string $p_action
 * @param string $p_label   lang key.
 * @param string $p_class   Button colour class.
 * @return void
 */
function ai_knowledge_action_button( int $p_id, string $p_action, string $p_label, string $p_class ) {
?>
	<form method="post" action="ai_knowledge_update.php" style="display:inline">
		<?php echo form_security_field( 'ai_knowledge_update' ) ?>
		<input type="hidden" name="action" value="<?php echo $p_action ?>" />
		<input type="hidden" name="id" value="<?php echo $p_id ?>" />
		<button type="submit" class="btn btn-minier <?php echo $p_class ?> btn-white btn-round" style="margin:1px 0"><?php echo lang_get( $p_label ) ?></button>
	</form>
<?php
}

layout_page_header( lang_get( 'ai_knowledge_title' ) );
layout_page_begin( 'ai_assist_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
<?php if( $t_edit !== null ): ?>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-edit', 'ace-icon' ); ?> <?php echo lang_get( 'ai_knowledge_edit_title' ) ?> KB-<?php echo (int)$t_edit['id'] ?></h4>
		</div>
		<div class="widget-body"><div class="widget-main no-padding"><?php ai_knowledge_print_form( $t_edit, $t_projects ); ?></div></div>
	</div>
	<div class="space-10"></div>
<?php endif; ?>

	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-graduation-cap', 'ace-icon' ); ?> <?php echo lang_get( 'ai_knowledge_title' ) ?></h4>
			<div class="widget-toolbar">
				<div class="btn-group">
<?php foreach( array( 0 => 'ai_knowledge_filter_all', AI_KNOWLEDGE_UNVERIFIED => 'ai_knowledge_unverified', AI_KNOWLEDGE_PUBLISHED => 'ai_knowledge_published' )
		+ ( $t_reviewer ? array( AI_KNOWLEDGE_RETIRED => 'ai_knowledge_retired' ) : array() ) as $t_s => $t_label ): ?>
					<a class="btn btn-minier btn-white btn-round<?php echo $f_status === $t_s ? ' active' : '' ?>"
						href="ai_knowledge_page.php<?php echo $t_s > 0 ? '?status=' . $t_s : '' ?>"><?php
						echo lang_get( $t_label ) . ( $t_s > 0 ? ' (' . $t_counts[$t_s] . ')' : '' ) ?></a>
<?php endforeach; ?>
				</div>
				<a class="btn btn-minier btn-default btn-white btn-round" href="<?php echo helper_mantis_url( 'ai_assist_page.php' ) ?>">
					<?php print_icon( 'fa-comments', 'ace-icon' ); ?> <?php echo lang_get( 'ai_assist_title' ) ?></a>
			</div>
		</div>
		<div class="widget-body">
			<div class="widget-main no-padding">
				<p class="text-muted" style="padding:8px 8px 0"><?php echo lang_get( $t_reviewer ? 'ai_knowledge_hint_reviewer' : 'ai_knowledge_hint' ) ?></p>
<?php if( empty( $t_entries ) ): ?>
				<p class="text-muted" style="padding:8px"><?php echo lang_get( 'ai_knowledge_none' ) ?></p>
<?php else: ?>
				<div class="table-responsive">
				<table class="table table-bordered table-condensed table-striped">
					<tr>
						<th>#</th>
						<th class="width-25"><?php echo lang_get( 'ai_knowledge_question' ) ?></th>
						<th><?php echo lang_get( 'ai_knowledge_answer' ) ?></th>
						<th><?php echo lang_get( 'ai_knowledge_page_field' ) ?></th>
						<th><?php echo lang_get( 'ai_knowledge_scope' ) ?></th>
						<th><?php echo lang_get( 'status' ) ?></th>
						<th><?php echo lang_get( 'ai_knowledge_contributed' ) ?></th>
						<th></th>
					</tr>
<?php	foreach( $t_entries as $t_e ):
			$t_id = (int)$t_e['id'];
			$t_status = (int)$t_e['status']; ?>
					<tr id="kb-<?php echo $t_id ?>"<?php echo $t_status === AI_KNOWLEDGE_RETIRED ? ' class="text-muted"' : '' ?>>
						<td class="nowrap">KB-<?php echo $t_id ?></td>
						<td><strong><?php echo string_display_line( $t_e['question'] ) ?></strong>
<?php		if( $t_e['keywords'] !== '' ): ?>
							<br><span class="text-muted small"><?php echo string_display_line( $t_e['keywords'] ) ?></span>
<?php		endif; ?>
<?php		if( (int)$t_e['supersedes'] > 0 ): ?>
							<br><span class="small"><?php echo sprintf( lang_get( 'ai_knowledge_corrects' ), 'KB-' . (int)$t_e['supersedes'] ) ?></span>
<?php		endif; ?>
						</td>
						<td class="small"><?php echo nl2br( string_html_specialchars( $t_e['answer'] ) ) ?></td>
						<td class="nowrap"><?php echo $t_e['page'] === '' ? '—'
							: '<a href="' . string_attribute( helper_mantis_url( $t_e['page'] ) ) . '">' . string_display_line( $t_e['page'] ) . '</a>' ?></td>
						<td><?php echo (int)$t_e['project_id'] === 0 ? lang_get( 'ai_knowledge_scope_all' )
							: string_display_line( project_get_name( (int)$t_e['project_id'] ) ) ?></td>
						<td class="nowrap"><span class="label <?php echo $t_status === AI_KNOWLEDGE_PUBLISHED ? 'label-success'
							: ( $t_status === AI_KNOWLEDGE_UNVERIFIED ? 'label-warning' : 'label-default' ) ?>"><?php echo ai_knowledge_status_label( $t_status ) ?></span>
<?php		if( (int)$t_e['reviewed_by'] > 0 ): ?>
							<br><span class="text-muted small"><?php echo string_display_line( user_get_name( (int)$t_e['reviewed_by'] ) )
								. ', ' . date( config_get( 'short_date_format' ), (int)$t_e['date_reviewed'] ) ?></span>
<?php		endif; ?>
						</td>
						<td class="small nowrap"><?php echo string_display_line( user_exists( (int)$t_e['created_by'] ) ? user_get_name( (int)$t_e['created_by'] ) : '—' ) ?>
							<br><span class="text-muted"><?php echo date( config_get( 'short_date_format' ), (int)$t_e['date_created'] )
								. ( $t_e['source'] === 'assistant' ? ' · ' . lang_get( 'ai_knowledge_via_assistant' ) : '' ) ?></span></td>
						<td class="nowrap">
<?php		if( $t_reviewer ): ?>
<?php			if( $t_status !== AI_KNOWLEDGE_PUBLISHED ) ai_knowledge_action_button( $t_id, 'publish', 'ai_knowledge_publish_button', 'btn-success' ); ?>
							<a class="btn btn-minier btn-default btn-white btn-round" style="margin:1px 0" href="ai_knowledge_page.php?edit=<?php echo $t_id ?>"><?php echo lang_get( 'ai_knowledge_edit_button' ) ?></a>
<?php			if( $t_status !== AI_KNOWLEDGE_RETIRED ) ai_knowledge_action_button( $t_id, 'retire', 'ai_knowledge_retire_button', 'btn-warning' ); ?>
<?php			ai_knowledge_action_button( $t_id, 'delete', 'ai_knowledge_delete_button', 'btn-danger' ); ?>
<?php		elseif( (int)$t_e['created_by'] === $t_user_id && $t_status === AI_KNOWLEDGE_UNVERIFIED ): ?>
<?php			ai_knowledge_action_button( $t_id, 'delete', 'ai_knowledge_delete_button', 'btn-danger' ); ?>
<?php		endif; ?>
						</td>
					</tr>
<?php	endforeach; ?>
				</table>
				</div>
<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2 collapsed">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-plus', 'ace-icon' ); ?> <?php echo lang_get( 'ai_knowledge_add_title' ) ?></h4>
			<div class="widget-toolbar"><a data-action="collapse" href="#"><?php print_icon( 'fa-chevron-down', '1 ace-icon bigger-125' ); ?></a></div>
		</div>
		<div class="widget-body"><div class="widget-main no-padding">
			<p class="text-muted" style="padding:8px 8px 0"><?php echo lang_get( $t_reviewer ? 'ai_knowledge_add_hint_reviewer' : 'ai_knowledge_add_hint' ) ?></p>
			<?php ai_knowledge_print_form( null, $t_projects ); ?>
		</div></div>
	</div>
</div>
<?php layout_page_end(); ?>
