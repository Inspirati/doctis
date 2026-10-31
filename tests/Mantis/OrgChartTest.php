<?php
# Doctis organisational chart tests.

namespace Mantis\tests\Mantis;

require_api( 'org_chart_api.php' );

class OrgChartTest extends MantisCoreBase {
	public function testBuildsRegisteredAndExternalRelationships() {
		$t_chart = org_chart_build( array(
			array( 'id' => 1, 'username' => 'chief', 'realname' => 'Chief', 'reports_to' => 0, 'alternative' => '' ),
			array( 'id' => 2, 'username' => 'worker', 'realname' => 'Worker', 'reports_to' => 1, 'alternative' => '' ),
			array( 'id' => 3, 'username' => 'contractor', 'realname' => 'Contractor', 'reports_to' => 0, 'alternative' => 'External Manager' ),
		) );

		$this->assertSame( array( 1 ), $t_chart['roots'] );
		$this->assertSame( array( 2 ), $t_chart['children'][1] );
		$this->assertSame( array( 3 ), $t_chart['external']['External Manager'] );
	}

	public function testDanglingManagerFallsBackToAlternative() {
		$t_chart = org_chart_build( array(
			array( 'id' => 4, 'username' => 'user', 'realname' => '', 'reports_to' => 99, 'alternative' => 'Fallback Manager' ),
		) );

		$this->assertSame( array( 4 ), $t_chart['external']['Fallback Manager'] );
	}

	public function testExportEscapesStaffTextIncludingMarkupAndUnicode() {
		$t_label = org_chart_graph_label( array( 'Renée <Admin> & "Staff"', '<IMG SRC="/etc/passwd"/>' ) );

		$this->assertStringContainsString( 'Renée &lt;Admin&gt; &amp; &quot;Staff&quot;', $t_label );
		$this->assertStringContainsString( '&lt;IMG SRC=&quot;/etc/passwd&quot;/&gt;', $t_label );
		$this->assertStringNotContainsString( '<IMG', $t_label );
	}

	public function testExportRetainsDisconnectedUsersExternalManagersAndCycles() {
		$t_chart = org_chart_build( array(
			array( 'id' => 1, 'username' => 'one', 'realname' => '', 'reports_to' => 2 ),
			array( 'id' => 2, 'username' => 'two', 'realname' => '', 'reports_to' => 1 ),
			array( 'id' => 3, 'username' => 'contractor', 'realname' => '', 'alternative' => 'Outside & Co' ),
			array( 'id' => 4, 'username' => 'standalone', 'realname' => '' ),
		) );
		$t_graph = org_chart_create_graph( $t_chart );
		ob_start();
		$t_graph->generate();
		$t_source = ob_get_clean();

		$this->assertTrue( $t_graph->is_edge_present( 'user_1', 'user_2' ) );
		$this->assertTrue( $t_graph->is_edge_present( 'user_2', 'user_1' ) );
		$this->assertTrue( $t_graph->is_edge_present( 'external_0', 'user_3' ) );
		$this->assertStringContainsString( '<B>standalone</B>', $t_source );
		$this->assertStringContainsString( '<B>Outside &amp; Co</B>', $t_source );
	}
}
