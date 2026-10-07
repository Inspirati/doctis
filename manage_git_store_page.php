<?php
# Doctis — Manage → Git Store: browse the git document store (read-only).
#
# Left: every repository in the database or on disk.  Right: the selected
# repository's files (folders, search, per-file detail with history, preview
# and download), registered documents, commits, refs and server worktree.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'git_store_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_repos = git_store_repositories();
$t_summaries = array();
foreach( $t_repos as $t_key => $t_entry ) {
	$t_summaries[$t_key] = git_store_summary( $t_entry );
}

# Selected repository: as requested if known, else the first one on disk.
$f_repo = gpc_get_string( 'repo', '' );
if( !isset( $t_repos[$f_repo] ) ) {
	$f_repo = '';
	foreach( $t_repos as $t_key => $t_entry ) {
		if( $f_repo === '' || ( $t_entry['bare_exists'] && !$t_repos[$f_repo]['bare_exists'] ) ) {
			$f_repo = $t_key;
		}
	}
}
$t_repo = $f_repo !== '' ? $t_repos[$f_repo] : null;

$f_view = gpc_get_string( 'view', 'files' );
if( !in_array( $f_view, array( 'files', 'documents', 'commits', 'refs', 'worktree' ), true ) ) {
	$f_view = 'files';
}
$f_dir = trim( gpc_get_string( 'dir', '' ), '/' );
$f_file = gpc_get_string( 'file', '' );
$f_q = trim( mb_substr( gpc_get_string( 'q', '' ), 0, 200 ) );
$f_show = gpc_get_string( 'show', '' );

$t_tree = $t_repo ? git_store_tree( $t_repo ) : array();
$t_docs = $t_repo ? git_store_documents( $t_repo, $t_tree ) : array();
$t_refs = $t_repo ? git_store_refs( $t_repo ) : array();
if( !isset( $t_tree[$f_file] ) ) {
	$f_file = '';
}
if( $f_dir !== '' ) {
	$t_dir_known = false;
	foreach( array_keys( $t_tree ) as $t_path ) {
		if( strpos( $t_path, $f_dir . '/' ) === 0 ) {
			$t_dir_known = true;
			break;
		}
	}
	if( !$t_dir_known ) {
		$f_dir = '';
	}
}

/**
 * Link to this page with the given parameters (current repository by default).
 *
 * @param array $p_params
 * @return string HTML-escaped URL.
 */
function git_store_page_url( array $p_params ) {
	global $f_repo;
	$p_params += array( 'repo' => $f_repo );
	return htmlspecialchars( 'manage_git_store_page.php?' . http_build_query( array_filter( $p_params, 'strlen' ) ) );
}

/**
 * Link to download a blob.
 *
 * @param string $p_rev  'HEAD' or a commit sha.
 * @param string $p_path
 * @return string HTML-escaped URL.
 */
function git_store_download_url( $p_rev, $p_path ) {
	global $f_repo;
	return htmlspecialchars( 'manage_git_store_download.php?'
		. http_build_query( array( 'repo' => $f_repo, 'rev' => $p_rev, 'path' => $p_path ) ) );
}

/**
 * Short date in the configured format, or '' for 0.
 *
 * @param integer $p_time
 * @return string
 */
function git_store_date( $p_time ) {
	return $p_time ? date( config_get( 'normal_date_format' ), $p_time ) : '';
}

/**
 * Print a commit compactly: date, short sha, author and subject.
 *
 * @param array|null $p_commit
 * @return void
 */
function print_git_store_commit( $p_commit ) {
	if( !$p_commit ) {
		echo '<span class="grey">—</span>';
		return;
	}
	echo '<span class="nowrap">' . htmlspecialchars( git_store_date( $p_commit['time'] ) ) . '</span>'
		. ' <code>' . htmlspecialchars( substr( $p_commit['sha'], 0, 9 ) ) . '</code><br />'
		. '<small>' . htmlspecialchars( $p_commit['author'] ) . ': '
		. htmlspecialchars( mb_strimwidth( $p_commit['subject'], 0, 80, '…' ) ) . '</small>';
}

/**
 * Print the document cell for a path: link plus On Record state and Draft labels.
 *
 * @param array|null $p_doc Entry from git_store_documents().
 * @return void
 */
function print_git_store_document( $p_doc ) {
	if( !$p_doc ) {
		echo '<span class="grey small">not registered</span>';
		return;
	}
	$t_row = $p_doc['record'] ?: $p_doc['draft'];
	# Imported documents carry their commit SHA as the reference; it repeats the On Record column.
	$t_reference = preg_match( '/^[0-9a-f]{40}$/', (string)$t_row['reference'] ) ? '' : (string)$t_row['reference'];
	$t_label = trim( $t_reference . ' ' . ( $t_row['title'] ?: $t_row['summary'] ) );
	echo '<a href="dwg_view.php?id=' . (int)$t_row['dwg_id'] . '">#' . (int)$t_row['dwg_id'] . '</a> '
		. htmlspecialchars( mb_strimwidth( $t_label, 0, 60, '…' ) ) . '<br />';
	if( $p_doc['record'] ) {
		$t_states = array(
			'current' => array( 'success', 'On Record = HEAD' ),
			'changed' => array( 'warning', 'HEAD differs from On Record' ),
			'missing' => array( 'danger', 'Not at HEAD' ),
			'unknown' => array( 'danger', 'On Record commit not found' ),
		);
		list( $t_class, $t_text ) = $t_states[$p_doc['record']['state']];
		echo '<span class="label label-' . $t_class . '">' . $t_text . '</span> ';
	}
	if( $p_doc['draft'] ) {
		echo '<span class="label label-info">Draft</span>';
	}
}

$t_storage_root = config_get_global( 'git_storage_root' );
$t_worktree_root = config_get_global( 'git_worktree_root' );
$t_total_size = 0;
$t_total_files = 0;
$t_issues = array();
foreach( $t_repos as $t_key => $t_entry ) {
	$t_total_size += $t_summaries[$t_key]['size'] ?? 0;
	$t_total_files += $t_summaries[$t_key]['files'] ?? 0;
	$t_issue = array(
		'missing' => 'is registered but has no bare repository on disk',
		'orphan' => 'is on disk but not registered in the database',
		'worktree-only' => 'has a worktree but no bare repository',
	)[$t_entry['status']] ?? null;
	if( $t_issue ) {
		$t_issues[] = $t_key . ' ' . $t_issue;
	}
}

layout_page_header( 'Git Store — Manage' );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_git_store_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-database', 'ace-icon' ); ?>
			Git Store
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<div class="row">
			<div class="col-md-6">
				<table class="table table-condensed table-bordered" style="margin-bottom:0;">
					<tr><th class="category">Repositories</th><td><code><?php echo htmlspecialchars( $t_storage_root ); ?></code></td></tr>
					<tr><th class="category">Worktrees</th><td><code><?php echo htmlspecialchars( $t_worktree_root ); ?></code></td></tr>
				</table>
			</div>
			<div class="col-md-6">
				<table class="table table-condensed table-bordered" style="margin-bottom:0;">
					<tr><th class="category">Contents</th><td>
						<?php echo count( $t_repos ); ?> repositories,
						<?php echo $t_total_files; ?> files at HEAD,
						<?php echo git_store_format_size( $t_total_size ); ?> on disk
					</td></tr>
					<tr><th class="category">Consistency</th><td>
						<?php if( empty( $t_issues ) ) { ?>
						<span class="label label-success">Database and disk agree</span>
						<?php } else { foreach( $t_issues as $t_issue ) { ?>
						<span class="label label-warning"><?php echo htmlspecialchars( $t_issue ); ?></span><br />
						<?php } } ?>
					</td></tr>
				</table>
			</div>
		</div>
	</div>
	</div>
	</div>
</div>

<div class="col-md-3 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-code-fork', 'ace-icon' ); ?>
			Repositories
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
		<?php if( empty( $t_repos ) ) { ?>
		<div class="padding-8">The git store is empty. Repositories are created on the first document upload.</div>
		<?php } ?>
		<div class="list-group" style="margin-bottom:0;">
		<?php foreach( $t_repos as $t_key => $t_entry ) {
			$t_summary = $t_summaries[$t_key];
		?>
			<a href="<?php echo git_store_page_url( array( 'repo' => $t_key ) ); ?>"
				class="list-group-item<?php echo $t_key === $f_repo ? ' active' : ''; ?>">
				<?php if( !empty( $t_summary ) ) { ?>
				<span class="badge" title="files at HEAD"><?php echo (int)$t_summary['files']; ?></span>
				<?php } ?>
				<strong><?php echo htmlspecialchars( $t_entry['name'] ); ?></strong><br />
				<small><?php echo htmlspecialchars( $t_key ); ?>
				<?php if( !empty( $t_summary['branch'] ) ) { ?>
				· <?php echo htmlspecialchars( $t_summary['branch'] ); ?> · <?php echo (int)$t_summary['commits']; ?> commits
				<?php } ?></small><br />
				<small>
				<?php if( $t_entry['status'] !== 'ok' ) { ?>
				<span class="label label-warning"><?php echo htmlspecialchars( $t_entry['status'] ); ?></span>
				<?php } ?>
				<?php echo $t_entry['worktree_exists'] ? 'worktree present' : 'no worktree'; ?>
				</small>
			</a>
		<?php } ?>
		</div>
	</div>
	</div>
	</div>
</div>

<div class="col-md-9 col-xs-12">
	<div class="space-10"></div>
<?php if( $t_repo ) {
	$t_summary = $t_summaries[$f_repo];
	$t_row = $t_repo['row'];
	$t_project_names = array();
	if( $t_row ) {
		foreach( repository_project_ids( $t_repo['id'] ) as $t_project_id ) {
			if( project_exists( $t_project_id ) ) {
				$t_project_names[$t_project_id] = project_get_name( $t_project_id );
			}
		}
	}
	$t_clone_url = '';
	if( ON == config_get_global( 'git_http_enabled', OFF ) ) {
		$t_url = parse_url( config_get_global( 'path' ) );
		if( isset( $t_url['scheme'], $t_url['host'] ) ) {
			$t_clone_url = $t_url['scheme'] . '://' . $t_url['host'] . ( isset( $t_url['port'] ) ? ':' . $t_url['port'] : '' )
				. '/git/' . $f_repo . '.git';
		}
	}
	$t_tabs = array(
		'files' => 'Files (' . count( $t_tree ) . ')',
		'documents' => 'Documents (' . count( $t_docs ) . ')',
		'commits' => 'Commits (' . ( $t_summary['commits'] ?? 0 ) . ')',
		'refs' => 'Refs (' . count( $t_refs ) . ')',
		'worktree' => 'Worktree',
	);
?>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-archive', 'ace-icon' ); ?>
			<?php echo htmlspecialchars( $t_repo['name'] ); ?>
			<small style="color:#fff;opacity:0.8;"><?php echo htmlspecialchars( $f_repo ); ?></small>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<table class="table table-condensed table-bordered">
			<tr>
				<th class="category" width="15%">Record</th>
				<td width="35%"><?php
					if( $t_row ) {
						echo 'Repository #' . (int)$t_row['id'] . ', created ' . htmlspecialchars( git_store_date( (int)$t_row['date_created'] ) );
						if( $t_row['adopted_from'] !== '' ) {
							echo '<br /><small>adopted from <code>' . htmlspecialchars( $t_row['adopted_from'] ) . '</code></small>';
						}
					} else {
						echo '<span class="label label-warning">not registered in the database</span>';
					}
				?></td>
				<th class="category" width="15%">Projects</th>
				<td><?php
					$t_links = array();
					foreach( $t_project_names as $t_project_id => $t_name ) {
						$t_owner = $t_row && (int)$t_row['owner_project_id'] === $t_project_id;
						$t_links[] = '<a href="manage_proj_edit_page.php?project_id=' . (int)$t_project_id . '">'
							. htmlspecialchars( $t_name ) . '</a>' . ( $t_owner ? ' <small>(owner)</small>' : '' );
					}
					echo $t_links ? implode( ', ', $t_links ) : '<span class="grey">none</span>';
				?></td>
			</tr>
			<tr>
				<th class="category">HEAD</th>
				<td><?php
					if( !empty( $t_summary['head'] ) ) {
						echo '<code>' . htmlspecialchars( $t_summary['branch'] ) . '</code> ';
						print_git_store_commit( $t_summary['head'] );
					} else {
						echo '<span class="grey">' . ( $t_repo['bare_exists'] ? 'no commits' : 'no bare repository' ) . '</span>';
					}
				?></td>
				<th class="category">Size</th>
				<td><?php
					if( !empty( $t_summary ) ) {
						echo git_store_format_size( $t_summary['size'] ) . ' · ' . (int)$t_summary['refs'] . ' refs, '
							. (int)$t_summary['pins'] . ' approved-version pins';
					}
				?></td>
			</tr>
			<tr>
				<th class="category">Paths</th>
				<td colspan="3"><small>
					bare <code><?php echo htmlspecialchars( $t_repo['bare'] ); ?></code>
					· worktree <code><?php echo htmlspecialchars( $t_repo['worktree'] ); ?></code>
					<?php if( $t_clone_url !== '' ) { ?>
					· clone <code><?php echo htmlspecialchars( $t_clone_url ); ?></code>
					<?php } ?>
				</small></td>
			</tr>
		</table>

		<ul class="nav nav-tabs padding-12">
		<?php foreach( $t_tabs as $t_view => $t_label ) { ?>
			<li<?php echo $t_view === $f_view ? ' class="active"' : ''; ?>>
				<a href="<?php echo git_store_page_url( array( 'view' => $t_view ) ); ?>"><?php echo htmlspecialchars( $t_label ); ?></a>
			</li>
		<?php } ?>
		</ul>
		<div class="space-10"></div>

<?php
	if( $f_view === 'files' ) {
		$t_last = git_store_last_changes( $t_repo );

		# Breadcrumb for the folder (or the file's folder).
		$t_crumb_dir = $f_file !== '' ? ( dirname( $f_file ) === '.' ? '' : dirname( $f_file ) ) : $f_dir;
		echo '<form method="get" action="manage_git_store_page.php" class="form-inline pull-right">'
			. '<input type="hidden" name="repo" value="' . htmlspecialchars( $f_repo ) . '" />'
			. '<input type="text" name="q" class="input-sm" placeholder="Search paths and documents" size="30" value="'
			. htmlspecialchars( $f_q ) . '" /> '
			. '<button type="submit" class="btn btn-xs btn-primary btn-white btn-round">Search</button></form>';
		echo '<div><a href="' . git_store_page_url( array() ) . '">' . htmlspecialchars( $f_repo ) . '</a>';
		$t_acc = '';
		foreach( $t_crumb_dir === '' ? array() : explode( '/', $t_crumb_dir ) as $t_part ) {
			$t_acc = ltrim( $t_acc . '/' . $t_part, '/' );
			echo ' / <a href="' . git_store_page_url( array( 'dir' => $t_acc ) ) . '">' . htmlspecialchars( $t_part ) . '</a>';
		}
		if( $f_file !== '' ) {
			echo ' / <strong>' . htmlspecialchars( basename( $f_file ) ) . '</strong>';
		}
		echo '</div><div class="space-10"></div>';

		if( $f_file !== '' ) {
			# ── File detail ──
			$t_info = $t_tree[$f_file];
			$t_doc = $t_docs[$f_file] ?? null;
			$t_history = git_store_file_history( $t_repo, $f_file );
?>
		<table class="table table-condensed table-bordered">
			<tr><th class="category" width="15%">Path</th><td><code><?php echo htmlspecialchars( $f_file ); ?></code></td></tr>
			<tr><th class="category">Size</th><td><?php echo git_store_format_size( $t_info['size'] ); ?>
				· blob <code><?php echo htmlspecialchars( substr( $t_info['sha'], 0, 12 ) ); ?></code>
				· mode <?php echo htmlspecialchars( $t_info['mode'] ); ?></td></tr>
			<tr><th class="category">Last change</th><td><?php print_git_store_commit( $t_last[$f_file] ?? null ); ?></td></tr>
			<tr><th class="category">Document</th><td><?php
				print_git_store_document( $t_doc );
				if( $t_doc && $t_doc['record'] ) {
					echo '<br /><small>On Record commit <code>' . htmlspecialchars( substr( $t_doc['record']['git_sha'], 0, 12 ) )
						. '</code>, registered ' . htmlspecialchars( git_store_date( (int)$t_doc['record']['date_added'] ) ) . '</small>';
					$t_pins = array();
					foreach( $t_refs as $t_ref ) {
						if( strpos( $t_ref['name'], 'refs/doctis/approved/' . (int)$t_doc['record']['dwg_id'] . '/' ) === 0 ) {
							$t_pins[] = 'approval ' . htmlspecialchars( basename( $t_ref['name'] ) ) . ' <code>'
								. htmlspecialchars( substr( $t_ref['sha'], 0, 9 ) ) . '</code>';
						}
					}
					if( $t_pins ) {
						echo '<br /><small>' . implode( ', ', $t_pins ) . '</small>';
					}
				}
				if( $t_doc && $t_doc['draft'] ) {
					echo '<br /><small>Draft commit <code>' . htmlspecialchars( substr( $t_doc['draft']['git_sha'], 0, 12 ) )
						. '</code>, staged ' . htmlspecialchars( git_store_date( (int)$t_doc['draft']['date_added'] ) ) . '</small>';
				}
			?></td></tr>
		</table>
		<p>
			<a class="btn btn-sm btn-primary btn-white btn-round" href="<?php echo git_store_download_url( 'HEAD', $f_file ); ?>">
				<?php print_icon( 'fa-download', 'ace-icon' ); ?> Download (HEAD)
			</a>
			<a class="btn btn-sm btn-default btn-white btn-round" href="<?php echo git_store_page_url( array( 'dir' => $t_crumb_dir ) ); ?>">
				<?php print_icon( 'fa-folder-open-o', 'ace-icon' ); ?> Back to folder
			</a>
		</p>

		<h5><?php print_icon( 'fa-history', 'ace-icon' ); ?> History</h5>
		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr><th>Commit</th><th>Path in that commit</th><th></th></tr></thead>
			<tbody>
			<?php foreach( $t_history as $t_commit ) {
				$t_path_then = $t_commit['files'][0] ?? $f_file;
			?>
				<tr>
					<td><?php print_git_store_commit( $t_commit ); ?></td>
					<td><small><?php echo htmlspecialchars( $t_path_then ); ?></small></td>
					<td><a href="<?php echo git_store_download_url( $t_commit['sha'], $t_path_then ); ?>" title="Download this version">
						<?php print_icon( 'fa-download', 'ace-icon' ); ?></a></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		</div>

		<h5><?php print_icon( 'fa-eye', 'ace-icon' ); ?> Preview</h5>
		<?php
			$t_limit = 262144;
			if( $t_info['size'] > $t_limit ) {
				echo '<p class="grey">Too large to preview (' . git_store_format_size( $t_info['size'] ) . '). Download it instead.</p>';
			} else {
				$t_content = git_store_blob( $t_repo, 'HEAD:' . $f_file, $t_limit );
				if( $t_content === null ) {
					echo '<p class="grey">Not available.</p>';
				} else if( !git_store_is_text( $t_content ) ) {
					echo '<p class="grey">Binary file. Download it to view.</p>';
				} else {
					echo '<pre style="max-height:600px;overflow:auto;white-space:pre-wrap;">'
						. htmlspecialchars( $t_content ) . '</pre>';
				}
			}
		?>
<?php
		} else {
			# ── Folder listing, or search results ──
			$t_rows = array();
			if( $f_q !== '' ) {
				foreach( $t_tree as $t_path => $t_info ) {
					$t_doc = $t_docs[$t_path] ?? null;
					$t_doc_row = $t_doc ? ( $t_doc['record'] ?: $t_doc['draft'] ) : null;
					$t_haystack = $t_path . ( $t_doc_row
						? ' #' . $t_doc_row['dwg_id'] . ' ' . $t_doc_row['reference'] . ' ' . $t_doc_row['title'] . ' ' . $t_doc_row['summary']
						: '' );
					if( mb_stripos( $t_haystack, $f_q ) !== false ) {
						$t_rows[$t_path] = array( 'type' => 'file', 'path' => $t_path, 'name' => $t_path, 'info' => $t_info );
					}
				}
			} else {
				$t_prefix = $f_dir === '' ? '' : $f_dir . '/';
				foreach( $t_tree as $t_path => $t_info ) {
					if( $t_prefix !== '' && strpos( $t_path, $t_prefix ) !== 0 ) {
						continue;
					}
					$t_rest = substr( $t_path, strlen( $t_prefix ) );
					$t_slash = strpos( $t_rest, '/' );
					if( $t_slash === false ) {
						$t_rows['f:' . $t_rest] = array( 'type' => 'file', 'path' => $t_path, 'name' => $t_rest, 'info' => $t_info );
						continue;
					}
					$t_name = substr( $t_rest, 0, $t_slash );
					$t_dir_row = &$t_rows['d:' . $t_name];
					if( !$t_dir_row ) {
						$t_dir_row = array( 'type' => 'dir', 'path' => $t_prefix . $t_name, 'name' => $t_name,
							'files' => 0, 'size' => 0, 'documents' => 0, 'last' => null );
					}
					$t_dir_row['files']++;
					$t_dir_row['size'] += $t_info['size'];
					$t_dir_row['documents'] += isset( $t_docs[$t_path] ) ? 1 : 0;
					$t_child = $t_last[$t_path] ?? null;
					if( $t_child && ( !$t_dir_row['last'] || $t_child['time'] > $t_dir_row['last']['time'] ) ) {
						$t_dir_row['last'] = $t_child;
					}
					unset( $t_dir_row );
				}
				uksort( $t_rows, 'strnatcasecmp' );
			}
			if( $f_q !== '' ) {
				echo '<p>' . count( $t_rows ) . ' file(s) matching <strong>' . htmlspecialchars( $f_q ) . '</strong>.</p>';
			}
?>
		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr><th>Name</th><th class="nowrap">Size</th><th>Last change</th><th>Document</th></tr></thead>
			<tbody>
			<?php if( empty( $t_rows ) ) { ?>
				<tr><td colspan="4" class="grey"><?php echo $t_repo['bare_exists'] ? 'No files.' : 'No bare repository on disk.'; ?></td></tr>
			<?php } ?>
			<?php foreach( $t_rows as $t_entry ) { ?>
				<tr>
				<?php if( $t_entry['type'] === 'dir' ) { ?>
					<td><?php print_icon( 'fa-folder', 'ace-icon orange' ); ?>
						<a href="<?php echo git_store_page_url( array( 'dir' => $t_entry['path'] ) ); ?>"><?php echo htmlspecialchars( $t_entry['name'] ); ?>/</a></td>
					<td class="nowrap"><?php echo git_store_format_size( $t_entry['size'] ); ?></td>
					<td><?php print_git_store_commit( $t_entry['last'] ); ?></td>
					<td class="small"><?php echo (int)$t_entry['files']; ?> files, <?php echo (int)$t_entry['documents']; ?> registered</td>
				<?php } else { ?>
					<td><?php print_icon( 'fa-file-o', 'ace-icon' ); ?>
						<a href="<?php echo git_store_page_url( array( 'file' => $t_entry['path'] ) ); ?>"><?php echo htmlspecialchars( $t_entry['name'] ); ?></a></td>
					<td class="nowrap"><?php echo git_store_format_size( $t_entry['info']['size'] ); ?></td>
					<td><?php print_git_store_commit( $t_last[$t_entry['path']] ?? null ); ?></td>
					<td class="small"><?php print_git_store_document( $t_docs[$t_entry['path']] ?? null ); ?></td>
				<?php } ?>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
<?php
		}
	} else if( $f_view === 'documents' ) {
		$t_counts = array( 'current' => 0, 'changed' => 0, 'missing' => 0, 'unknown' => 0, 'draft' => 0 );
		foreach( $t_docs as $t_doc ) {
			if( $t_doc['record'] ) {
				$t_counts[$t_doc['record']['state']]++;
			}
			if( $t_doc['draft'] ) {
				$t_counts['draft']++;
			}
		}
		$t_unregistered = array_diff( array_keys( $t_tree ), array_keys( $t_docs ) );
?>
		<p>
			<span class="label label-success"><?php echo $t_counts['current']; ?> On Record = HEAD</span>
			<span class="label label-warning"><?php echo $t_counts['changed']; ?> HEAD differs</span>
			<span class="label label-danger"><?php echo $t_counts['missing'] + $t_counts['unknown']; ?> not at HEAD or commit not found</span>
			<span class="label label-info"><?php echo $t_counts['draft']; ?> drafts</span>
			<a href="<?php echo git_store_page_url( array( 'view' => 'documents', 'show' => $f_show === 'unregistered' ? '' : 'unregistered' ) ); ?>">
				<span class="label label-default"><?php echo count( $t_unregistered ); ?> files at HEAD not registered</span></a>
		</p>
		<?php if( $f_show === 'unregistered' ) { ?>
		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr><th>Unregistered file</th><th class="nowrap">Size</th></tr></thead>
			<tbody>
			<?php foreach( $t_unregistered as $t_path ) { ?>
				<tr><td><a href="<?php echo git_store_page_url( array( 'file' => $t_path ) ); ?>"><?php echo htmlspecialchars( $t_path ); ?></a></td>
					<td class="nowrap"><?php echo git_store_format_size( $t_tree[$t_path]['size'] ); ?></td></tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
		<?php } else { ?>
		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr><th>Document</th><th>Path</th><th>On Record</th><th>Draft</th></tr></thead>
			<tbody>
			<?php if( empty( $t_docs ) ) { ?>
				<tr><td colspan="4" class="grey">No documents are registered in this repository.</td></tr>
			<?php } ?>
			<?php foreach( $t_docs as $t_path => $t_doc ) { ?>
				<tr>
					<td class="small"><?php print_git_store_document( $t_doc ); ?></td>
					<td class="small"><?php if( isset( $t_tree[$t_path] ) ) { ?>
						<a href="<?php echo git_store_page_url( array( 'file' => $t_path ) ); ?>"><?php echo htmlspecialchars( $t_path ); ?></a>
						<?php } else { echo htmlspecialchars( $t_path ); } ?></td>
					<td class="small"><?php if( $t_doc['record'] ) { ?>
						<code><?php echo htmlspecialchars( substr( $t_doc['record']['git_sha'], 0, 9 ) ); ?></code><br />
						<?php echo htmlspecialchars( git_store_date( (int)$t_doc['record']['date_added'] ) ); ?>
						<?php } ?></td>
					<td class="small"><?php if( $t_doc['draft'] ) { ?>
						<code><?php echo htmlspecialchars( substr( $t_doc['draft']['git_sha'], 0, 9 ) ); ?></code><br />
						<?php echo htmlspecialchars( git_store_date( (int)$t_doc['draft']['date_added'] ) ); ?>
						<?php } ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
		<?php } ?>
<?php
	} else if( $f_view === 'commits' ) {
?>
		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr><th>Commit</th><th>Changes</th></tr></thead>
			<tbody>
			<?php foreach( git_store_commits( $t_repo, 100, true ) as $t_commit ) { ?>
				<tr><td><?php print_git_store_commit( $t_commit ); ?></td>
					<td class="small"><?php echo htmlspecialchars( $t_commit['stat'] ); ?></td></tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
		<?php if( ( $t_summary['commits'] ?? 0 ) > 100 ) { ?>
		<p class="grey">Showing the latest 100 of <?php echo (int)$t_summary['commits']; ?> commits.</p>
		<?php } ?>
<?php
	} else if( $f_view === 'refs' ) {
?>
		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr><th>Ref</th><th>Points to</th></tr></thead>
			<tbody>
			<?php foreach( $t_refs as $t_ref ) { ?>
				<tr>
					<td><?php
						if( preg_match( '#^refs/doctis/approved/(\d+)/(\d+)$#', $t_ref['name'], $t_m ) ) {
							echo '<span class="label label-success">approved</span> <a href="dwg_view.php?id=' . (int)$t_m[1] . '">#'
								. (int)$t_m[1] . '</a>, approval ' . (int)$t_m[2];
						} else if( strpos( $t_ref['name'], 'refs/heads/' ) === 0 ) {
							echo '<span class="label label-info">branch</span> ' . htmlspecialchars( substr( $t_ref['name'], 11 ) );
						} else if( strpos( $t_ref['name'], 'refs/tags/' ) === 0 ) {
							echo '<span class="label label-default">tag</span> ' . htmlspecialchars( substr( $t_ref['name'], 10 ) );
						} else {
							echo htmlspecialchars( $t_ref['name'] );
						}
					?></td>
					<td><?php print_git_store_commit( array( 'sha' => $t_ref['sha'], 'time' => $t_ref['time'],
						'author' => '', 'subject' => $t_ref['subject'] ) ); ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
<?php
	} else if( $f_view === 'worktree' ) {
		$t_wt = git_store_worktree( $t_repo );
		if( empty( $t_wt ) ) {
			echo '<p class="grey">No server worktree. One is cloned from the bare repository on the next document upload.</p>';
		} else {
?>
		<table class="table table-condensed table-bordered">
			<tr><th class="category" width="20%">Path</th><td><code><?php echo htmlspecialchars( $t_repo['worktree'] ); ?></code></td></tr>
			<tr><th class="category">Branch and HEAD</th><td><code><?php echo htmlspecialchars( $t_wt['branch'] ); ?></code>
				<code><?php echo htmlspecialchars( substr( $t_wt['head'], 0, 12 ) ); ?></code>
				<?php if( $t_wt['in_sync'] ) { ?>
				<span class="label label-success">same as the bare repository</span>
				<?php } else { ?>
				<span class="label label-warning">bare repository is at <?php echo htmlspecialchars( substr( $t_wt['bare_head'], 0, 12 ) ); ?></span>
				<?php } ?></td></tr>
			<tr><th class="category">Contents</th><td><?php echo (int)$t_wt['tracked']; ?> tracked files,
				<?php echo git_store_format_size( $t_wt['size'] ); ?> on disk</td></tr>
			<tr><th class="category">Uncommitted changes</th><td><?php echo count( $t_wt['changes'] ) ?: '<span class="label label-success">none</span>'; ?></td></tr>
		</table>
		<?php if( !empty( $t_wt['changes'] ) ) { ?>
		<div class="table-responsive">
		<table class="table table-condensed table-striped">
			<thead><tr><th>Status</th><th>Path</th></tr></thead>
			<tbody>
			<?php foreach( $t_wt['changes'] as $t_change ) { ?>
				<tr><td><code><?php echo htmlspecialchars( $t_change['code'] ); ?></code></td>
					<td><?php echo htmlspecialchars( $t_change['path'] ); ?></td></tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
		<?php } ?>
<?php
		}
	}
?>
	</div>
	</div>
	</div>
<?php } ?>
</div>

<?php
layout_page_end();
