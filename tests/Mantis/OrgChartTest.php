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
}
