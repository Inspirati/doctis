<?php
# dwg_primary_file_tag_page.php
# Form page — apply a git lightweight tag to the Approved SHA of a primary
# document.  GET renders the form; POST validates and applies the tag, then
# redirects back to the document view page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();

$f_dwg_id = gpc_get_int( 'id' );

access_ensure_dwg_level( MANAGER, $f_dwg_id );

$t_row = file_dwg_primary_get( $f_dwg_id );
if( $t_row === null ) {
	error_parameters( $f_dwg_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

# ── Handle POST (apply tag) ────────────────────────────────────────────────

$t_error = '';

if( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	form_security_validate( 'dwg_primary_file_tag' );

	$f_tag_name = trim( gpc_get_string( 'tag_name', '' ) );

	if( !file_dwg_git_tag_name_valid( $f_tag_name ) ) {
		$t_error = lang_get( 'primary_document_tag_invalid' );
	} else {
		$t_result = file_dwg_git_tag( $f_dwg_id, $f_tag_name );
		if( $t_result === 'ok' ) {
			form_security_purge( 'dwg_primary_file_tag' );
			print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
		} elseif( $t_result === 'exists' ) {
			$t_error = lang_get( 'primary_document_tag_exists' );
		} else {
			$t_error = 'Git tag operation failed. Check the server error log.';
		}
	}
}

# ── Compute display values ─────────────────────────────────────────────────

$t_project_id   = dwg_get_field( $f_dwg_id, 'project_id' );
$t_project_name = project_get_field( $t_project_id, 'name' );
$t_bare         = dwg_project_bare_repo_path( $t_project_id );
$t_dwg_title    = dwg_get_field( $f_dwg_id, 'summary' );

$t_sha_full  = htmlspecialchars( $t_row['git_sha'] );
$t_sha_abbr  = htmlspecialchars( substr( $t_row['git_sha'], 0, 8 ) );
$t_filename  = htmlspecialchars( $t_row['filename'] );

# List existing tags on this SHA for reference.
$t_existing_tags_raw = trim( (string)shell_exec(
	'git --git-dir=' . escapeshellarg( $t_bare ) .
	' tag --points-at ' . escapeshellarg( $t_row['git_sha'] ) . ' 2>/dev/null'
) );
$t_existing_tags = $t_existing_tags_raw !== '' ? explode( "\n", $t_existing_tags_raw ) : [];

# ── Render page ───────────────────────────────────────────────────────────

layout_page_header( lang_get( 'primary_document_tag_page_title' ) );
layout_page_begin();
?>
<div class="col-md-8 col-md-offset-2 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title">
				<?php echo lang_get( 'primary_document_tag_page_title' ) ?>
				&mdash; Document <?php echo (int)$f_dwg_id ?>:
				<?php echo string_display_line( $t_dwg_title ) ?>
			</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main padding-16">

				<?php if( $t_error !== '' ): ?>
				<div class="alert alert-danger"><?php echo htmlspecialchars( $t_error ) ?></div>
				<?php endif; ?>

				<p>
					A git lightweight tag will be applied to the <strong>Approved</strong>
					commit SHA — the version currently recorded in the Doctis database.
					Tags are permanent labels in the repository and are visible to anyone
					with access to the bare repository.
				</p>

				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-30">Repository</th>
						<td><code><?php echo htmlspecialchars( $t_bare ) ?></code></td>
					</tr>
					<tr>
						<th class="category">Approved SHA</th>
						<td>
							<code title="<?php echo $t_sha_full ?>"><?php echo $t_sha_abbr ?></code>
							&nbsp;<span class="small">(full: <code><?php echo $t_sha_full ?></code>)</span>
						</td>
					</tr>
					<tr>
						<th class="category">File</th>
						<td><code><?php echo (int)$f_dwg_id ?>/<?php echo $t_filename ?></code></td>
					</tr>
<?php if( $t_existing_tags ): ?>
					<tr>
						<th class="category">Existing tags on this SHA</th>
						<td>
<?php foreach( $t_existing_tags as $t_tag ): ?>
							<span class="label label-info"><?php echo htmlspecialchars( trim( $t_tag ) ) ?></span>&nbsp;
<?php endforeach; ?>
						</td>
					</tr>
<?php endif; ?>
				</table>

				<form method="post" action="dwg_primary_file_tag_page.php" class="form-inline">
					<?php echo form_security_field( 'dwg_primary_file_tag' ) ?>
					<input type="hidden" name="id" value="<?php echo (int)$f_dwg_id ?>" />
					<div class="form-group">
						<label for="tag_name" class="control-label">
							<?php echo lang_get( 'primary_document_tag_name_label' ) ?>
						</label>
						&nbsp;
						<input id="tag_name" type="text" name="tag_name"
							class="input-sm width-30"
							maxlength="100"
							placeholder="<?php echo lang_get( 'primary_document_tag_name_hint' ) ?>"
							value="<?php echo isset( $f_tag_name ) ? htmlspecialchars( $f_tag_name ) : '' ?>"
							autofocus />
					</div>
					&nbsp;
					<input type="submit"
						class="btn btn-primary btn-sm btn-white btn-round"
						value="<?php echo lang_get( 'primary_document_tag_apply' ) ?>" />
					&nbsp;
					<a href="dwg_view.php?id=<?php echo (int)$f_dwg_id ?>"
						class="btn btn-default btn-sm btn-white btn-round">
						<?php echo lang_get( 'primary_document_tag_cancel' ) ?>
					</a>
				</form>

			</div>
		</div>
	</div>
</div>
<?php
layout_page_end();
