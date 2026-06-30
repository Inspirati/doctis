<?php
# dwg_primary_head_warn.php
# Intermediate page for downloading the current git HEAD version of a primary
# document.  Two sections are rendered:
#
#   1. Warning — the HEAD file may differ from the officially approved version.
#      Confirm button re-POSTs with _confirmed=1, which redirects to the
#      actual file download.
#
#   2. Advanced git access — precise, per-document instructions for accessing
#      the bare repository directly via git CLI.
#
# GET  dwg_primary_head_warn.php?id=<N>       → renders both sections
# POST dwg_primary_head_warn.php (confirmed)  → redirects to file_download.php

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );

auth_ensure_user_authenticated();

$f_dwg_id = gpc_get_int( 'id' );

access_ensure_dwg_level( config_get( 'view_dwg_threshold' ), $f_dwg_id );

# Validate that a primary file and HEAD both exist.
$t_row = file_dwg_primary_get( $f_dwg_id );
if( $t_row === null ) {
	error_parameters( $f_dwg_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

$t_head = file_dwg_git_head_info( $f_dwg_id );
if( $t_head === null || $t_head['filename'] === null ) {
	error_parameters( $f_dwg_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

# If the user has already confirmed, redirect straight to the download.
if( true == gpc_get_bool( '_confirmed' ) ) {
	print_header_redirect( 'file_download.php?type=dwg_primary_head&id=' . $f_dwg_id );
}

# ── Compute values for the git info section ────────────────────────────────

$t_project_id   = dwg_get_field( $f_dwg_id, 'project_id' );
$t_project_name = project_get_field( $t_project_id, 'name' );
$t_slug         = preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( trim( $t_project_name ) ) );
$t_bare         = config_get( 'git_storage_root' ) . '/' . $t_slug . '.git';
$t_rel_path     = $f_dwg_id . '/' . $t_head['filename'];

# Shell-safe versions for display in <code> blocks.
$t_bare_shell    = escapeshellarg( $t_bare );
$t_rel_shell     = escapeshellarg( $t_rel_path );
$t_head_sha_full = htmlspecialchars( $t_head['sha'] );
$t_head_sha_abbr = htmlspecialchars( substr( $t_head['sha'], 0, 8 ) );

# Pre-compose the server-side command strings (already shell-safe).
$t_cmd_show_head = 'git --git-dir=' . $t_bare_shell . ' show HEAD:' . $t_rel_shell;
$t_cmd_log       = 'git --git-dir=' . $t_bare_shell . ' log --follow -- ' . $t_rel_shell;
$t_cmd_show_sha  = 'git --git-dir=' . $t_bare_shell . ' show ' . $t_head_sha_abbr . ':' . $t_rel_shell;
$t_cmd_clone     = 'git clone ' . $t_bare_shell . ' /tmp/' . htmlspecialchars( $t_slug );

# Remote (workstation) clone over Smart HTTP — the recommended method.
$t_git_http_on   = ( ON == config_get_global( 'git_http_enabled' ) );
$t_scheme        = ( !empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
$t_host_raw      = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'vaio';
$t_current_user  = user_get_username( auth_get_current_user_id() );
$t_clone_url     = $t_scheme . '://' . $t_host_raw . '/git/' . $t_slug . '.git';
$t_cmd_clone_remote = 'git clone ' . $t_scheme . '://' . $t_current_user . '@' . $t_host_raw . '/git/' . $t_slug . '.git';

# ── Render the page ────────────────────────────────────────────────────────

layout_page_header( lang_get( 'primary_document_section' ) );
layout_page_begin();
?>
<div class="col-md-12 col-xs-12">

	<!-- ── Section 1: Warning and confirmation ─────────────────────────── -->
	<div class="space-10"></div>
	<div class="alert alert-warning center">
		<p class="bigger-110">
			<?php echo lang_get( 'primary_document_head_download_warn' ) ?>
		</p>
		<div class="space-10"></div>
		<form method="post" class="center" action="">
			<?php print_hidden_inputs( $_GET ); ?>
			<?php print_hidden_inputs( $_POST ); ?>
			<input type="hidden" name="_confirmed" value="1" />
			<input type="submit"
				class="btn btn-primary btn-white btn-round"
				value="<?php echo lang_get( 'primary_document_head_download_button' ) ?>" />
		</form>
		<div class="space-10"></div>
	</div>

	<!-- ── Section 2: Advanced git access information ──────────────────── -->
	<div class="space-20"></div>
	<div class="widget-box">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title">Advanced: Direct Git Repository Access</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main padding-16">

				<!-- ── Recommended: clone to your own workstation ──────────── -->
<?php if( $t_git_http_on ) { ?>
				<h4 class="blue">Clone this project repository to your workstation</h4>
				<p>
					You can clone the entire <strong><?php echo htmlspecialchars( $t_project_name ) ?></strong>
					project document repository to your own computer using ordinary git
					over the network, authenticated with your Doctis account &mdash;
					<strong>no server login or Linux account is required</strong>.
				</p>
				<ol>
					<li>
						Create a personal API token under
						<a href="api_tokens_page.php">My&nbsp;Account &rarr; API&nbsp;Tokens</a>.
						Copy it immediately &mdash; it is shown only once.
					</li>
					<li>
						Run the clone command below.  When git prompts for a
						<em>password</em>, paste your <strong>API token</strong>
						(your account password will not work):
						<pre class="bigger-110"><code><?php echo htmlspecialchars( $t_cmd_clone_remote ) ?></code></pre>
					</li>
				</ol>

				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-25">Clone URL</th>
						<td><code><?php echo htmlspecialchars( $t_clone_url ) ?></code></td>
					</tr>
					<tr>
						<th class="category">Username</th>
						<td>your Doctis username (<code><?php echo htmlspecialchars( $t_current_user ) ?></code>)</td>
					</tr>
					<tr>
						<th class="category">Password</th>
						<td>a personal <strong>API token</strong> &mdash; <em>not</em> your account password</td>
					</tr>
				</table>

				<p class="small">
					<strong>What you get:</strong> every <em>registered</em> document in this
					project &mdash; one directory per document id (this document is
					<code><?php echo htmlspecialchars( $t_rel_path ) ?></code>) &mdash; with full
					revision history.  Attachments are <em>not</em> included.
					<br>
					<strong>Access:</strong> requires <strong>Developer</strong> access (or higher)
					to the project, and is currently <strong>read-only</strong> &mdash; pushing
					changes back is not yet enabled.  The Approved version shown in Doctis is the
					commit pinned by the SHA recorded in the database, which may differ from the
					latest <code>HEAD</code> in your clone.
				</p>
<?php } else { ?>
				<p class="alert alert-info">
					Remote git access is not enabled on this server
					(<code>$g_git_http_enabled</code> is off).  Contact your administrator to
					enable cloning project repositories to your workstation.
				</p>
<?php } ?>

				<!-- ── Repository reference ─────────────────────────────────── -->
				<h5>Repository reference</h5>
				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-25">Project</th>
						<td><?php echo htmlspecialchars( $t_project_name ) ?></td>
					</tr>
					<tr>
						<th class="category">Document path in repo</th>
						<td><code><?php echo htmlspecialchars( $t_rel_path ) ?></code></td>
					</tr>
					<tr>
						<th class="category">Current HEAD SHA</th>
						<td>
							<code title="<?php echo $t_head_sha_full ?>"><?php echo $t_head_sha_abbr ?></code>
							&nbsp;<span class="small">(full: <code><?php echo $t_head_sha_full ?></code>)</span>
						</td>
					</tr>
				</table>

				<!-- ── Deprecated: server-side shell access ─────────────────── -->
				<h5 class="grey">
					<i class="fa fa-exclamation-triangle"></i>
					Deprecated: direct server-side access (administrators only)
				</h5>
				<p class="small">
					<strong>The commands below are deprecated for Doctis users.</strong>
					They operate on the bare repository <em>on the server itself</em>
					(<code><?php echo htmlspecialchars( $t_bare ) ?></code>) and require shell
					access as <code>www-data</code>.  A server-local clone is of no use to a
					Doctis user working from their own machine &mdash; use the
					<strong>workstation clone</strong> above instead.  These are retained for
					administrator diagnostics only.
				</p>
				<table class="table table-bordered table-condensed grey">
					<tr>
						<th class="category width-35">Read current HEAD version</th>
						<td><code><?php echo htmlspecialchars( $t_cmd_show_head ) ?></code></td>
					</tr>
					<tr>
						<th class="category">Read a specific commit</th>
						<td>
							<code><?php echo htmlspecialchars( $t_cmd_show_sha ) ?></code>
							<span class="small">&nbsp;(replace <em><?php echo $t_head_sha_abbr ?></em> with any valid SHA)</span>
						</td>
					</tr>
					<tr>
						<th class="category">View full commit history for this file</th>
						<td><code><?php echo htmlspecialchars( $t_cmd_log ) ?></code></td>
					</tr>
					<tr>
						<th class="category text-muted"><del>Clone repository locally</del> &mdash; deprecated</th>
						<td><code class="text-muted"><?php echo htmlspecialchars( $t_cmd_clone ) ?></code></td>
					</tr>
				</table>

				<p class="small">
					<strong>Note:</strong> git history is permanent &mdash; all previous file
					content is retrievable even after a Doctis &ldquo;delete&rdquo;.
				</p>
			</div>
		</div>
	</div>

</div>
<?php
layout_page_end();
