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
			array( 'id' => 4, 'username' => 'user', 'realname' => 'Named User', 'reports_to' => 99, 'alternative' => 'Fallback Manager' ),
		) );

		$this->assertSame( array( 4 ), $t_chart['external']['Fallback Manager'] );
	}

	public function testOmitsBlankRealNamesWithoutLosingNamedReports() {
		$t_chart = org_chart_build( array(
			array( 'id' => 1, 'username' => 'hidden', 'realname' => '' ),
			array( 'id' => 2, 'username' => 'spaces', 'realname' => " \t\r\n" ),
			array( 'id' => 3, 'username' => 'null', 'realname' => null ),
			array( 'id' => 4, 'username' => 'missing' ),
			array( 'id' => 5, 'realname' => ' Named User ', 'reports_to' => 1 ),
			array( 'id' => 6, 'realname' => 'Named Report', 'reports_to' => 5 ),
			array( 'id' => 7, 'realname' => 'Contractor', 'reports_to' => 2, 'alternative' => 'Outside Manager' ),
			array( 'id' => 8, 'realname' => '0' ),
		) );

		$this->assertSame( array( 5, 6, 7, 8 ), array_keys( $t_chart['nodes'] ) );
		$this->assertSame( 'Named User', $t_chart['nodes'][5]['realname'] );
		$this->assertSame( array( 8, 5 ), $t_chart['roots'] );
		$this->assertSame( array( 5 => array( 6 ) ), $t_chart['children'] );
		$this->assertSame( array( 'Outside Manager' => array( 7 ) ), $t_chart['external'] );
	}

	public function testExportEscapesStaffTextIncludingMarkupAndUnicode() {
		$t_label = org_chart_graph_label( array( 'Renée <Admin> & "Staff"', '<IMG SRC="/etc/passwd"/>' ) );

		$this->assertStringContainsString( 'Renée &lt;Admin&gt; &amp; &quot;Staff&quot;', $t_label );
		$this->assertStringContainsString( '&lt;IMG SRC=&quot;/etc/passwd&quot;/&gt;', $t_label );
		$this->assertStringNotContainsString( '<IMG', $t_label );
	}

	public function testExportRetainsDisconnectedUsersExternalManagersAndCycles() {
		$t_chart = org_chart_build( array(
			array( 'id' => 1, 'username' => 'one', 'realname' => 'One', 'reports_to' => 2 ),
			array( 'id' => 2, 'username' => 'two', 'realname' => 'Two', 'reports_to' => 1 ),
			array( 'id' => 3, 'username' => 'contractor', 'realname' => 'Contractor', 'alternative' => 'Outside & Co' ),
			array( 'id' => 4, 'username' => 'standalone', 'realname' => 'Standalone' ),
			array( 'id' => 5, 'username' => 'hidden', 'realname' => '' ),
		) );
		$t_graph = org_chart_create_graph( $t_chart );
		ob_start();
		$t_graph->generate();
		$t_source = ob_get_clean();

		$this->assertTrue( $t_graph->is_edge_present( 'user_1', 'user_2' ) );
		$this->assertTrue( $t_graph->is_edge_present( 'user_2', 'user_1' ) );
		$this->assertTrue( $t_graph->is_edge_present( 'external_0', 'user_3' ) );
		$this->assertStringContainsString( '<B>Standalone</B>', $t_source );
		$this->assertStringContainsString( '<B>Outside &amp; Co</B>', $t_source );
		$this->assertStringNotContainsString( 'hidden', $t_source );
	}

	public function testCsvPreservesUniqueIdentitiesRelationshipsAndStaffText() {
		$t_chart = org_chart_build( array(
			array( 'id' => 1, 'realname' => 'Alex Smith', 'reports_to' => 2 ),
			array( 'id' => 2, 'realname' => 'Alex Smith', 'reports_to' => 1 ),
			array( 'id' => 3, 'realname' => 'Renée "Staff", Engineering', 'alternative' => 'Outside & Co',
				'position_title' => "Lead\nDesigner", 'department' => 'R&D, Mechanical', 'company' => 'Acme \\ Design' ),
			array( 'id' => 4, 'realname' => '', 'username' => 'hidden' ),
			array( 'id' => 5, 'realname' => 'Named Report', 'reports_to' => 4 ),
		) );
		$t_csv = org_chart_export_csv( $t_chart );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $t_csv );
		$this->assertStringContainsString( "\r\n", $t_csv );
		$t_rows = $this->readCsv( $t_csv );
		$this->assertCount( 5, $t_rows );
		$this->assertSame( 'Alex Smith', $t_rows['user_1']['Name'] );
		$this->assertSame( 'Alex Smith', $t_rows['user_2']['Name'] );
		$this->assertSame( 'user_2', $t_rows['user_1']['Reports To'] );
		$this->assertSame( 'user_1', $t_rows['user_2']['Reports To'] );
		$this->assertSame( 'external_0', $t_rows['user_3']['Reports To'] );
		$this->assertSame( 'Outside & Co', $t_rows['external_0']['Name'] );
		$this->assertSame( 'External', $t_rows['external_0']['Type'] );
		$this->assertSame( 'Renée "Staff", Engineering', $t_rows['user_3']['Name'] );
		$this->assertSame( "Lead\nDesigner", $t_rows['user_3']['Title'] );
		$this->assertSame( 'R&D, Mechanical', $t_rows['user_3']['Department'] );
		$this->assertSame( 'Acme \\ Design', $t_rows['user_3']['Company'] );
		$this->assertSame( '', $t_rows['user_5']['Reports To'] );
		foreach( $t_rows as $t_row ) {
			if( $t_row['Reports To'] !== '' ) {
				$this->assertArrayHasKey( $t_row['Reports To'], $t_rows );
			}
		}
		$this->assertStringNotContainsString( 'hidden', $t_csv );
	}

	public function testCsvNeutralisesSpreadsheetFormulasInStaffAndExternalFields() {
		$t_chart = org_chart_build( array(
			array( 'id' => 1, 'realname' => '=1+1', 'position_title' => '+1+1',
				'department' => " \t@SUM(1)", 'company' => '-1+1', 'alternative' => "\t=2+2" ),
		) );
		$t_rows = $this->readCsv( org_chart_export_csv( $t_chart ) );
		$this->assertSame( "'=1+1", $t_rows['user_1']['Name'] );
		$this->assertSame( "'+1+1", $t_rows['user_1']['Title'] );
		$this->assertSame( "' \t@SUM(1)", $t_rows['user_1']['Department'] );
		$this->assertSame( "'-1+1", $t_rows['user_1']['Company'] );
		$this->assertSame( "'=2+2", $t_rows['external_0']['Name'] );
		$this->assertSame( 'external_0', $t_rows['user_1']['Reports To'] );
	}

	public function testEmptyChartCsvHasHeadersAndNoPlaceholderPerson() {
		$t_csv = org_chart_export_csv( org_chart_build( array( array( 'id' => 1, 'realname' => '' ) ) ) );
		$this->assertStringContainsString( '"Employee ID",Name,"Reports To",Title,Department,Company,Type', $t_csv );
		$this->assertSame( array(), $this->readCsv( $t_csv ) );
	}

	private function readCsv( string $p_csv ): array {
		$t_stream = fopen( 'php://temp', 'w+' );
		fwrite( $t_stream, substr( $p_csv, 3 ) );
		rewind( $t_stream );
		$t_headers = fgetcsv( $t_stream, 0, ',', '"', '' );
		$t_rows = array();
		while( ( $t_row = fgetcsv( $t_stream, 0, ',', '"', '' ) ) !== false ) {
			$t_rows[$t_row[0]] = array_combine( $t_headers, $t_row );
		}
		fclose( $t_stream );
		return $t_rows;
	}
}
