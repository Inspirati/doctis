<?php
# doctis_overrides.php
# Load this *before* MantisBT defines the original functions.

if( !function_exists('bug_get_field') ) {
	/**
	 * doctis version of bug_get_field()
	 * Intercepts requests for bug fields and adds custom logic.
	 */
	function bug_get_field( $p_bug_id, $p_field_name ) {
		// --- your new doctis-specific logic ---
		if( $p_field_name === 'enabled' ) {
			$t_enabled = db_result( db_query(
				'SELECT enabled FROM mantis_bug_table WHERE id=' . db_param(),
				array( $p_bug_id )
			));
			return (bool)$t_enabled;
		}

		// Fall back to normal behavior
		return doctis_bug_get_field_original( $p_bug_id, $p_field_name );
	}

	/**
	 * Backup: the original mantis function, renamed.
	 * We'll import this later once MantisBT core has loaded.
	 */
	function doctis_bug_get_field_original( $p_bug_id, $p_field_name ) {
		static $loaded = false;

		if( !$loaded ) {
			require_once( config_get_global( 'core_path' ) . 'bug_api.php' );
			$loaded = true;
		}

		return \bug_get_field( $p_bug_id, $p_field_name ); // call original version
	}
}

