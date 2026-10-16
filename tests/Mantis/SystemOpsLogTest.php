<?php
# Doctis: System Operations → PHP Error Log reads the end of PHP's error log
# (system_ops_php_error_log(), system_ops_log_tail(), system_ops_log_entries()).

namespace Mantis\tests\Mantis;

class SystemOpsLogTest extends MantisCoreBase {
	private $file = '';
	private $error_log = '';

	protected function setUp(): void {
		parent::setUp();
		require_api( 'system_ops_api.php' );
		$this->file = (string)tempnam( sys_get_temp_dir(), 'doctis-log-test-' );
		$this->error_log = (string)ini_get( 'error_log' );
	}

	protected function tearDown(): void {
		@unlink( $this->file );
		ini_set( 'error_log', $this->error_log );
		parent::tearDown();
	}

	public function testWholeSmallFileIsRead() {
		file_put_contents( $this->file, "one\ntwo\nthree\n" );
		$this->assertSame( array( 'one', 'two', 'three' ), system_ops_log_tail( $this->file, 1024 ) );
	}

	public function testLineCutByByteLimitIsLeftOut() {
		file_put_contents( $this->file, "first line\nsecond\nthird\n" );
		# The last 10 bytes start inside "second".
		$this->assertSame( array( 'third' ), system_ops_log_tail( $this->file, 10 ) );
	}

	public function testLineStartingAtByteLimitIsKept() {
		file_put_contents( $this->file, "first line\nsecond\nthird\n" );
		# The last 13 bytes are exactly "second\nthird\n".
		$this->assertSame( array( 'second', 'third' ), system_ops_log_tail( $this->file, 13 ) );
	}

	public function testLastLineWithoutNewlineAndCrlfAreRead() {
		file_put_contents( $this->file, "one\r\ntwo" );
		$this->assertSame( array( 'one', 'two' ), system_ops_log_tail( $this->file, 1024 ) );
	}

	public function testEmptyAndMissingFiles() {
		$this->assertSame( array(), system_ops_log_tail( $this->file, 1024 ) );
		$this->assertFalse( system_ops_log_tail( $this->file . '-missing', 1024 ) );
	}

	public function testEntriesKeepStackTracesAndPutNewestFirst() {
		$t_lines = array(
			'Error Description: written before the first stamp',
			'[16-Oct-2026 13:12:03 UTC] PHP Warning:  first',
			'',
			'[16-Oct-2026 14:31:14 UTC] Call to undefined function x()',
			'Stack Trace:',
			"dwg_api.php L289 __set(<string>'a')",
			'',
			'[17-Oct-2026 01:02:03] WARNING: [pool doctis] child 12 said into stderr',
		);
		$this->assertSame( array(
			'[17-Oct-2026 01:02:03] WARNING: [pool doctis] child 12 said into stderr',
			"[16-Oct-2026 14:31:14 UTC] Call to undefined function x()\nStack Trace:\ndwg_api.php L289 __set(<string>'a')",
			'[16-Oct-2026 13:12:03 UTC] PHP Warning:  first',
			'Error Description: written before the first stamp',
		), system_ops_log_entries( $t_lines ) );
	}

	public function testOnlyAFileCountsAsTheLog() {
		ini_set( 'error_log', '/var/log/php-fpm/php_errors.log' );
		$this->assertSame( '/var/log/php-fpm/php_errors.log', system_ops_php_error_log() );
		foreach( array( '', 'syslog', '/proc/self/fd/2', '/dev/stderr' ) as $t_target ) {
			ini_set( 'error_log', $t_target );
			$this->assertSame( '', system_ops_php_error_log(), $t_target );
		}
	}
}
