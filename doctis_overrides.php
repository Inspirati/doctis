<?php
# doctis_overrides.php
# Load this *before* MantisBT defines the original functions.

/*
 * ANALYSIS — why this file was never integrated
 *
 * Intent: intercept MantisBT's bug_get_field() without modifying the original,
 * using PHP's "define it first" pattern — if a function is declared before
 * MantisBT loads bug_api.php, PHP skips the redeclaration and the custom
 * version wins.
 *
 * The specific case being explored was adding an 'enabled' field to bugs,
 * which does not exist in the MantisBT schema.
 *
 * The implementation is broken in a fundamental way: doctis_bug_get_field_original()
 * attempts to fall back to the original by calling \bug_get_field() (the global
 * namespace form), but at that point \bug_get_field IS the custom version — not
 * the MantisBT original. The require_once of bug_api.php on line 33 would be a
 * no-op because the file would already have been loaded. The fallback would
 * infinitely recurse.
 *
 * The correct approach for this pattern would have been to copy the original
 * function body into doctis_bug_get_field_original() rather than trying to call
 * back into the global namespace.
 *
 * There is no evidence the file is included anywhere — it exists as a standalone
 * experiment. It was written to explore whether Doctis-specific bug fields could
 * be bolt-on injected without touching MantisBT core, before the decision was
 * presumably made to use the parallel dwg_api.php approach instead.
 *
 * Safe to delete unless the override approach is revisited.
 */

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

