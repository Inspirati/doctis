<?php
# Doctis: System Operations refuse a config_inc.php that would stop Doctis
# from loading (system_ops_config_check(), admin/tools/doctis-config-check.php).
#
# Each case is the server's real config_inc.php with one change appended, so
# the database check runs against the real database.

namespace Mantis\tests\Mantis;

class ConfigCheckTest extends MantisCoreBase {
	private $config = '';

	protected function setUp(): void {
		parent::setUp();
		require_api( 'system_ops_api.php' );
		$this->config = rtrim( (string)file_get_contents( dirname( __DIR__, 2 ) . '/config/config_inc.php' ) ) . "\n";
		if( strpos( $this->config, '?>' ) !== false ) {
			$this->markTestSkipped( 'These cases append to a config_inc.php without a closing ?>.' );
		}
	}

	private function errors( string $p_append ): array {
		return system_ops_config_check( $this->config . $p_append, $this->config )['errors'];
	}

	public function testCurrentConfigPasses() {
		$this->assertSame( array(), system_ops_config_check( $this->config, $this->config )['errors'] );
	}

	public function testBuildingOnADefaultPasses() {
		$this->assertSame( array(), $this->errors( "\$g_dwg_view_page_fields = array_diff( \$g_dwg_view_page_fields, ['dwgnotes'] );\n" ) );
	}

	public function testSyntaxErrorRejected() {
		$t_errors = $this->errors( "\$g_broken = ;\n" );
		$this->assertCount( 1, $t_errors );
		$this->assertStringContainsString( 'Syntax error', $t_errors[0] );
	}

	public function testUndefinedConstantRejected() {
		$t_errors = $this->errors( "\$g_dwg_publish_pdf = OFFF;\n" );
		$this->assertCount( 1, $t_errors );
		$this->assertStringContainsString( 'OFFF', $t_errors[0] );
	}

	public function testOutputRejected() {
		$t_errors = $this->errors( "?>\n\n" );
		$this->assertCount( 1, $t_errors );
		$this->assertStringContainsString( 'output', $t_errors[0] );
	}

	public function testShortSaltRejected() {
		$t_errors = $this->errors( "\$g_crypto_master_salt = 'short';\n" );
		$this->assertStringContainsString( 'crypto_master_salt', implode( ' ', $t_errors ) );
	}

	public function testMissingDatabaseNameRejected() {
		$t_errors = $this->errors( "\$g_database_name = '';\n" );
		$this->assertStringContainsString( '$g_database_name', implode( ' ', $t_errors ) );
	}

	public function testWrongDatabasePasswordRejected() {
		$t_errors = $this->errors( "\$g_db_password = 'not-the-password-" . bin2hex( random_bytes( 4 ) ) . "';\n" );
		$this->assertCount( 1, $t_errors );
		$this->assertStringContainsString( 'database rejects', $t_errors[0] );
	}

	public function testUnrecoverableFatalErrorRejected() {
		# Redeclaring a function stops PHP before the checker can report.
		$t_errors = $this->errors( "function doctis_check_result() {}\n" );
		$this->assertCount( 1, $t_errors );
		$this->assertStringContainsString( 'stops PHP', $t_errors[0] );
	}

	public function testWarningsDoNotBlock() {
		$t_result = system_ops_config_check( $this->config . "\$g_window_title = \$g_no_such_setting ?? 'Doctis';\n\$g_x = \$undefined_variable;\n", $this->config . "\$g_dropped_setting = 1;\n" );
		$this->assertSame( array(), $t_result['errors'] );
		$t_warnings = implode( ' ', $t_result['warnings'] );
		$this->assertStringContainsString( 'undefined_variable', $t_warnings );
		$this->assertStringContainsString( '$g_dropped_setting', $t_warnings );
	}
}
