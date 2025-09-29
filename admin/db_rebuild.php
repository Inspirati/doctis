<?php
# MantisBT - A PHP based bugtracking system

# Mantis is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# Mantis is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

require_once( dirname( __DIR__ ) . '/core.php' );

access_ensure_global_level( config_get_global( 'admin_site_threshold' ) );

layout_page_header();

layout_admin_page_begin();

function rebuild_database() {
	
	access_ensure_global_level( config_get( 'admin_site_threshold' ) );

	foreach( db_get_table_list() as $t_table ) {
		if( db_table_exists( $t_table ) ) {
			db_query( "DROP TABLE $t_table" );
			echo "Dropped table: $t_table<br>";
		}
	}
	
}
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>

<?php
$f_rebuild_database = gpc_get_bool( 'rebuild_database' );

if( $f_rebuild_database ) {
	lang_push( 'english' );

	$t_result = rebuild_database();

	if( !$t_result ) {
		echo '<div class="alert alert-sm alert-danger">';
		print_icon( 'fa-times', 'ace-icon fa-lg' );
		echo '<strong>Rebuilding Database</strong> - ';
		echo ' PROBLEM REBUILDING DATABASE: ' . $g_database_name . ' TYPE ' . $g_db_type;
		echo '<br><br> Please check your database server settings.';
		echo '</div>';
	} else {
		echo '<div class="alert alert-sm alert-success">';
		print_icon( 'fa-check', 'ace-icon fa-lg' );
		echo '<strong>Testing DatabaseMail</strong> - ';
		echo ' database rebuild successful.';
		echo '</div>';
	}
}

?>
	<div class="widget-box widget-color-blue2">
	<div class="widget-body">
	<div class="widget-main">
		<form method="post" action="<?php echo $_SERVER['SCRIPT_NAME']?>">
			<fieldset>
				<h4>Rebuilding Database</h4>
				<p>You can delete and rebuild your MantisBT database to default configuration
					with this form. Just click "Rebuild Database". If the page takes a very
					long time to reappear or results in an error then you will need to
					investigate your database server settings (see database related
					settings in your config/config_inc.php, if they don't exist,
					copy from config_defaults_inc.php).</p>
				<p>Note that errors can also appear in the server error log.</p>
				<input type="submit" value="Rebuild Database" name="rebuild_database" class="btn btn-primary btn-white btn-round" />
			</fieldset>
		</form>
	</div>
	</div></div></div>
<?php
layout_admin_page_end();
