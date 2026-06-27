<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Overview Page
 *
 * @package MantisBT
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses event_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'event_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_site_threshold' ) );

layout_page_header( lang_get( 'manage_link' ) );

layout_page_begin( __FILE__ );

print_manage_menu( 'manage_overview_page.php' );

function trim_check( $p_string ) {
	if( $p_string ) {
		return trim( $p_string );  // 'trim' does not like being passed a nullstring
	} else {
		return "";
	}
}

function doctis_get_git_version_info() {
	$info = array(
		'origin' => null,
		'branch' => null,
		'commit' => null,
		'tag'    => null,
		'dirty'  => false,
		'repo'   => null,
	);

	$git_dir = __DIR__ . '/.git';
	if( is_dir( $git_dir ) ) {
		# requires:
		# sudo git config --system --add safe.directory /var/www/html/doctis
		$info['repo'] = realpath( dirname( $git_dir ) );
		$info['origin'] = trim_check( @shell_exec( 'git config --get remote.origin.url 2>/dev/null' ) );
		$info['branch'] = trim_check( @shell_exec( 'git rev-parse --abbrev-ref HEAD 2>/dev/null' ) );
		$info['commit'] = trim_check( @shell_exec( 'git rev-parse --short=10 HEAD 2>/dev/null' ) );
		$info['tag']    = trim_check( @shell_exec( 'git describe --tags --always --dirty 2>/dev/null' ) );
		$dirty_output   = trim_check( @shell_exec( 'git status --porcelain 2>/dev/null' ) );
		$info['dirty']  = ( $dirty_output !== '' );
	}
	if( ! $info['branch'] ) {
		$ver_file = __DIR__ . '/version.json';
		if( file_exists( $ver_file ) ) {
			$json = json_decode( file_get_contents( $ver_file ), true );
			if( is_array( $json ) ) {
				$info = array_merge( $info, $json );
			}
		}
	}

	return $info;
}
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-info', 'ace-icon' ); ?>
			<?php echo lang_get('site_information') ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table id="manage-overview-table" class="table table-hover table-bordered table-condensed">
		<tr>
			<th class="category"><?php echo lang_get( 'mantis_version' ) ?></th>
			<td><?php echo MANTIS_VERSION . config_get_global( 'version_suffix' ) ?></td>
		</tr>
		<tr>
			<th class="category"><?php echo lang_get( 'schema_version' ) ?></th>
			<td><?php echo config_get( 'database_version', 0, ALL_USERS, ALL_PROJECTS ) ?></td>
		</tr>
	<?php
	print_table_spacer( 2 );
	$t_is_admin = current_user_is_administrator();
	if( $t_is_admin ) {
	?>
		<tr>
			<th class="category"><?php echo lang_get( 'php_version' ) ?></th>
			<td><?php echo phpversion() ?></td>
		</tr>
		<tr>
 			<th class="category"><?php echo lang_get( 'os_information' ) ?></th>
			<td><?php echo php_uname() ?></td>
		</tr>
		<tr>
			<th class="category"><?php echo lang_get( 'database_driver' ) ?></th>
			<td><?php echo config_get_global( 'db_type' ) ?></td>
		</tr>
		<tr>
			<th class="category"><?php echo lang_get( 'database_version_description' ) ?></th>
			<td><?php
					$t_database_server_info = $g_db->ServerInfo();
					echo $t_database_server_info['version'] . ', ' . $t_database_server_info['description']
				?>
			</td>
		</tr>
		<?php print_table_spacer( 2 ) ?>
		<tr>
			<th class="category"><?php echo lang_get( 'site_path' ) ?></th>
			<td><?php echo config_get_global( 'absolute_path' ) ?></td>
		</tr>
		<tr>
			<th class="category"><?php echo lang_get( 'core_path' ) ?></th>
			<td><?php echo config_get_global( 'core_path' ) ?></td>
		</tr>
		<tr>
			<th class="category"><?php echo lang_get( 'plugin_path' ) ?></th>
			<td><?php echo config_get_global( 'plugin_path' ) ?></td>
		</tr>
	<?php
		print_table_spacer( 2 );
		$t_git_info = doctis_get_git_version_info();
		if( $t_git_info['commit'] ) {
			if( $t_git_info['repo'] ) {
				echo '<tr><td class="category">Repository</td><td>' . htmlspecialchars($t_git_info['repo']) . '</td></tr>';
			}
			if( $t_git_info['origin'] ) {
				echo '<tr><td class="category">Origin</td><td>' . htmlspecialchars($t_git_info['origin']) . '</td></tr>';
			}
			echo '<tr><td class="category">Branch</td><td>' . htmlspecialchars($t_git_info['branch']) . '</td></tr>';
			echo '<tr><td class="category">Commit</td><td>' . htmlspecialchars($t_git_info['commit']) . '</td></tr>';
			echo '<tr><td class="category">Tag</td><td>' . htmlspecialchars($t_git_info['tag']) . '</td></tr>';
			echo '<tr><td class="category">Dirty</td><td>' . ( $t_git_info['dirty'] ? 'Yes' : 'No' ) . '</td></tr>';
			print_table_spacer( 2 );
		}
	}

	event_signal( 'EVENT_MANAGE_OVERVIEW_INFO', array( $t_is_admin ) )
	?>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>
<?php if( current_user_is_administrator() ) { ?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-orange">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-wrench', 'ace-icon' ); ?>
			System Operations
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<a href="manage_config_file_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-file-text', 'ace-icon' ); ?> Config File
		</a>
		&nbsp;
		<a href="manage_db_backup_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-database', 'ace-icon' ); ?> Backup Database
		</a>
		&nbsp;
		<a href="manage_git_backup_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-archive', 'ace-icon' ); ?> Backup Git Store
		</a>
		&nbsp;
		<a href="manage_git_pull_page.php" class="btn btn-sm btn-primary">
			<?php print_icon( 'fa-download', 'ace-icon' ); ?> Git Pull (Update)
		</a>
		&nbsp;
		<a href="manage_db_load_sample_page.php" class="btn btn-sm btn-warning">
			<?php print_icon( 'fa-refresh', 'ace-icon' ); ?> Load Sample Data
		</a>
		&nbsp;
		<a href="manage_db_rebuild_page.php" class="btn btn-sm btn-danger">
			<?php print_icon( 'fa-trash', 'ace-icon' ); ?> Rebuild Database
		</a>
	</div>
	</div>
	</div>
</div>
<?php } ?>
<?php
layout_page_end();

