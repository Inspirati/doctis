<?php
# Doctis: document code that was copied from the issue modules must use the
# document constants and data, not the issue ones.  See also
# tests/soap/DwgApiTest.php for the SOAP paths.

namespace Mantis\tests\Mantis;

require_api( 'dwg_api.php' );
require_api( 'columns_dwg_api.php' );
require_api( 'history_dwg_api.php' );

class DwgIssueLeftoversTest extends MantisCoreBase {

	/**
	 * The API names document history events by the document event numbers.
	 * Until 2026-11-01 it matched BUG_MONITOR etc., so these came out as
	 * unknown events.
	 */
	public function testHistoryTypeNamesUseTheDocumentEvents() {
		$t_expected = array(
			DWG_MONITOR              => 'monitor-added',
			DWG_UNMONITOR            => 'monitor-deleted',
			DWG_DELETED              => 'issue-deleted',
			DWG_ADD_RELATIONSHIP     => 'relationship-added',
			DWG_REPLACE_RELATIONSHIP => 'relationship-updated',
			DWG_DEL_RELATIONSHIP     => 'relationship-deleted',
			DWG_CLONED_TO            => 'issue-cloned-to',
			DWG_CREATED_FROM         => 'issue-cloned-from',
			DWG_REVISION_DROPPED     => 'revision-deleted',
		);
		foreach( $t_expected as $t_type => $t_name ) {
			$this->assertSame( $t_name, history_dwg_get_type_name( $t_type ), 'type ' . $t_type );
		}
	}

	/**
	 * Sponsorship is an issue feature; documents do not have it (removed
	 * 2026-11-01).
	 */
	public function testDocumentsHaveNoSponsorship() {
		$this->assertArrayNotHasKey( 'sponsorship_total',
			( new \ReflectionClass( 'DwgData' ) )->getDefaultProperties() );
		$this->assertNotContains( 'sponsorship_total', columns_dwg_get_standard( false ) );
		$this->assertFalse( defined( 'DWG_ADD_SPONSORSHIP' ) );
		$this->assertFalse( function_exists( 'email_dwg_sponsorship_added' ) );
	}
}
