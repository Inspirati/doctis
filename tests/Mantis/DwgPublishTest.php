<?php
# Doctis document publishing (core/dwg_publish_api.php, doc/publish/PUBLISHING.md).
#
# Path, navigation, marker and template checks, and site file handling under
# a temporary publishing root.  The end-to-end test publishes and withdraws a
# real Markdown document into a temporary site; it runs only when
# DOCTIS_PUBLISH_TEST_DWG names that document's id, because it adds two
# entries to the document's history.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class DwgPublishTest extends MantisCoreBase {
	private $root = '';
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		require_api( 'dwg_publish_api.php' );
		foreach( array( 'dwg_publish_roots', 'git_storage_root' ) as $t_option ) {
			$this->saved[$t_option] = config_get_global( $t_option );
		}
		$this->root = rtrim( sys_get_temp_dir(), '/' ) . '/doctis-publish-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root );
		config_set_global( 'dwg_publish_roots', array( $this->root ) );
	}

	protected function tearDown(): void {
		foreach( $this->saved as $t_option => $t_value ) {
			config_set_global( $t_option, $t_value );
		}
		$this->deleteTree( $this->root );
		parent::tearDown();
	}

	private function deleteTree( string $p_dir ): void {
		if( !is_dir( $p_dir ) || is_link( $p_dir ) ) {
			@unlink( $p_dir );
			return;
		}
		foreach( scandir( $p_dir ) as $t_name ) {
			if( $t_name !== '.' && $t_name !== '..' ) {
				$this->deleteTree( $p_dir . '/' . $t_name );
			}
		}
		rmdir( $p_dir );
	}

	public function testPathsAndPrefixes() {
		$this->assertSame( '/var/www/site', dwg_publish_normalize_dir( '//var//www/site/' ) );
		$this->assertSame( '', dwg_publish_normalize_dir( '/var/www/../etc' ) );
		$this->assertSame( '', dwg_publish_normalize_dir( 'site' ) );
		$this->assertTrue( dwg_publish_path_within( '/srv/site/x', '/srv/site' ) );
		$this->assertFalse( dwg_publish_path_within( '/srv/site2', '/srv/site' ) );
		$this->assertSame( 'system/procedures/A.html', dwg_publish_page_path( 'system/procedures/A.md' ) );
		$this->assertSame( 'B.html', dwg_publish_page_path( 'B.markdown' ) );
		$this->assertTrue( dwg_publish_is_markdown( 'a/b.MD' ) );
		$this->assertFalse( dwg_publish_is_markdown( 'a/b.md.php' ) );
		$this->assertSame( '../../', dwg_publish_prefix( 'a/b/c.html' ) );
		$this->assertSame( 'my%20dir/a%26b.html', dwg_publish_href( 'my dir/a&b.html' ) );
	}

	/**
	 * A repository whose documents all live under one directory publishes
	 * every page beneath that directory's name unless the project strips it.
	 */
	public function testStripPrefix() {
		# Only a path that actually begins with the prefix is shortened.
		$this->assertSame( 'system/procedures/A.html',
			dwg_publish_page_path( 'content/system/procedures/A.md', 'content' ) );
		$this->assertSame( 'Z.html', dwg_publish_page_path( 'content/Z.md', 'content' ) );
		$this->assertSame( 'other/W.html', dwg_publish_page_path( 'other/W.md', 'content' ) );

		# A file *named* like the prefix is not a path under it.
		$this->assertSame( 'content.html', dwg_publish_page_path( 'content.md', 'content' ) );

		# A path equal to the prefix is not under it, so it keeps its name and
		# a page is never written to the site root with an empty name.
		$this->assertSame( 'content.html', dwg_publish_page_path( 'content', 'content' ) );
		$this->assertSame( 'content/.html', dwg_publish_page_path( 'content/', 'content' ) );

		# Surrounding slashes are tolerated; no prefix is the old behaviour.
		$this->assertSame( 'system/A.html', dwg_publish_page_path( 'content/system/A.md', '/content/' ) );
		$this->assertSame( 'content/system/A.html', dwg_publish_page_path( 'content/system/A.md', '' ) );

		# Validation: nothing that could climb out of the repository path.
		$this->assertSame( '', dwg_publish_strip_problem( '' ) );
		$this->assertSame( '', dwg_publish_strip_problem( 'content' ) );
		$this->assertSame( '', dwg_publish_strip_problem( 'a/b-c_d.e' ) );
		$this->assertNotSame( '', dwg_publish_strip_problem( '../etc' ) );
		$this->assertNotSame( '', dwg_publish_strip_problem( 'a/../b' ) );
		$this->assertNotSame( '', dwg_publish_strip_problem( './x' ) );
		$this->assertNotSame( '', dwg_publish_strip_problem( 'a//b' ) );
		$this->assertNotSame( '', dwg_publish_strip_problem( 'we ird' ) );
	}

	public function testRootsDropFilesystemRootAndRelativePaths() {
		config_set_global( 'dwg_publish_roots', array( $this->root . '/', '/', 'relative' ) );
		$this->assertSame( array( $this->root ), dwg_publish_roots() );
		config_set_global( 'dwg_publish_roots', array() );
		$this->assertFalse( dwg_publish_enabled() );
	}

	public function testDirectoryLabels() {
		$this->assertSame(
			array( 'IT', 'HR', 'VCRM', 'Supply Chain', 'Terms of Use' ),
			array_map( 'dwg_publish_dir_label', array( 'it', 'hr', 'vcrm', 'supply-chain', 'terms-of-use' ) ) );
	}

	public function testWebsiteUrls() {
		$this->assertSame( '', dwg_publish_url_problem( '' ) );
		$this->assertSame( '', dwg_publish_url_problem( '/site/' ) );
		$this->assertSame( '', dwg_publish_url_problem( 'https://qms.example.com/' ) );
		$this->assertNotSame( '', dwg_publish_url_problem( 'javascript:alert(1)' ) );
		$this->assertNotSame( '', dwg_publish_url_problem( '/site/" onmouseover="x' ) );
	}

	public static function providerPartials() {
		return array(
			'relative' => array( '${ ../secret() }' ),
			'plain' => array( '$secret()$' ),
			'absolute' => array( '${ /etc/x() }' ),
			'applied' => array( '$items:item()$' ),
			'separator' => array( '${ items:item()[, ] }' ),
		);
	}

	/**
	 * @dataProvider providerPartials
	 */
	public function testTemplatePartialsRefused( string $p_partial ) {
		$this->assertNotSame( '', dwg_publish_template_problem( '<p>' . $p_partial . '</p>$body$' ) );
	}

	public function testTemplateChecks() {
		$this->assertSame( '', dwg_publish_template_problem( '<script>$$foo()</script>$body$' ), 'literal $$ is not a partial' );
		$this->assertSame( '', dwg_publish_template_problem( '<main>${ body }</main>' ) );
		$this->assertNotSame( '', dwg_publish_template_problem( '<p>$title$</p>' ), 'no body' );
		$t_default = file_get_contents( dirname( __DIR__, 2 ) . '/core/publish/dwg_publish_template.html' );
		$this->assertSame( '', dwg_publish_template_problem( $t_default ) );
	}

	public function testMarkerRoundTrip() {
		$t_marker = array( 'dwg_id' => 7, 'title' => 'A -- B <x> & "q"', 'sha' => str_repeat( 'a', 40 ) );
		$t_line = dwg_publish_marker_line( $t_marker );
		$this->assertSame( 0, substr_count( substr( $t_line, 4, -5 ), '--' ), 'no "--" inside the comment' );
		$t_file = $this->root . '/page.html';
		file_put_contents( $t_file, "<html></html>\n" . $t_line );
		$this->assertSame( $t_marker, dwg_publish_read_marker( $t_file ) );
		file_put_contents( $t_file, "<html></html>\n" );
		$this->assertNull( dwg_publish_read_marker( $t_file ) );
	}

	public function testNavigation() {
		$t_pages = array(
			'README.html' => array( 'dwg_id' => 1, 'title' => '' ),
			'content/it/S.html' => array( 'dwg_id' => 3, 'title' => 'S & T' ),
			'system/procedures/A.html' => array( 'dwg_id' => 4, 'title' => 'Alpha' ),
		);
		$t_nav = dwg_publish_nav_html( dwg_publish_nav_tree( $t_pages ), '../../' );
		$this->assertStringContainsString( '<li class="nav-file"><a href="../../system/procedures/A.html">Alpha</a></li>', $t_nav );
		$this->assertStringContainsString( '>README</a>', $t_nav );
		$this->assertStringContainsString( 'S &amp; T', $t_nav );
		$this->assertStringContainsString( '<summary>IT</summary>', $t_nav );
		$this->assertLessThan( strpos( $t_nav, 'Content' ), strpos( $t_nav, 'README' ), 'files before directories' );

		$t_page = dwg_publish_before_body( '../', $t_nav, '<span>X</span>' );
		$t_new = dwg_publish_replace_nav( $t_page, "<li>NEW</li>\n" );
		$this->assertStringContainsString( DWG_PUBLISH_NAV_BEGIN . "\n<li>NEW</li>\n" . DWG_PUBLISH_NAV_END, $t_new );
		$this->assertStringNotContainsString( 'Alpha', $t_new );
		$this->assertNull( dwg_publish_replace_nav( '<p>x</p>', 'y' ) );
	}

	public function testTargetProblems() {
		$this->assertSame( '', dwg_publish_target_problem( $this->root ) );
		$this->assertSame( '', dwg_publish_target_problem( $this->root . '/not/yet/created' ) );
		$this->assertNotSame( '', dwg_publish_target_problem( '/etc' ), 'outside the root' );
		$this->assertNotSame( '', dwg_publish_target_problem( $this->root . '/../x' ), 'traversal' );

		file_put_contents( $this->root . '/file', 'x' );
		$this->assertNotSame( '', dwg_publish_target_problem( $this->root . '/file' ), 'not a directory' );

		$t_outside = $this->root . '-outside';
		mkdir( $t_outside );
		symlink( $t_outside, $this->root . '/link' );
		try {
			$this->assertNotSame( '', dwg_publish_target_problem( $this->root . '/link/sub' ), 'symbolic link out of the root' );
		} finally {
			rmdir( $t_outside );
		}

		config_set_global( 'git_storage_root', $this->root . '/store' );
		$this->assertNotSame( '', dwg_publish_target_problem( $this->root ), 'contains the git store' );
	}

	public function testSiteFiles() {
		$t_target = $this->root . '/site';
		$t_lock = dwg_publish_lock( $t_target );
		dwg_publish_unlock( $t_lock );
		$this->assertDirectoryExists( $t_target );

		$t_nav = dwg_publish_before_body( '', '', '' );
		dwg_publish_write( $t_target, 'b/c/two.html', $t_nav . dwg_publish_marker_line( array( 'dwg_id' => 2, 'title' => 'Two' ) ) );
		dwg_publish_write( $t_target, 'one.html', $t_nav . dwg_publish_marker_line( array( 'dwg_id' => 1, 'title' => 'One' ) ) );
		dwg_publish_write( $t_target, 'foreign.html', '<p>not from Doctis</p>' );
		mkdir( $t_target . '/.hidden' );
		dwg_publish_write( $t_target, '.hidden/x.html', dwg_publish_marker_line( array( 'dwg_id' => 9 ) ) );

		$t_pages = dwg_publish_site_pages( $t_target );
		$this->assertSame( array( 'b/c/two.html', 'one.html' ), array_keys( $t_pages ) );
		$this->assertSame( 'b/c/two.html', $t_pages['b/c/two.html']['page'] );

		# Navigation refresh rewrites Doctis pages only; no template removes the index.
		dwg_publish_refresh_site( array( 'target' => $t_target, 'project_id' => ALL_PROJECTS ), $t_pages, '', null, '' );
		$this->assertStringContainsString( 'href="b/c/two.html">Two</a>', file_get_contents( $t_target . '/one.html' ) );
		$this->assertStringContainsString( 'href="../../one.html">One</a>', file_get_contents( $t_target . '/b/c/two.html' ) );
		$this->assertSame( '<p>not from Doctis</p>', file_get_contents( $t_target . '/foreign.html' ) );
		$this->assertFileDoesNotExist( $t_target . '/index.html' );

		dwg_publish_remove( $t_target, 'b/c/two.html' );
		$this->assertDirectoryDoesNotExist( $t_target . '/b', 'empty directories are pruned' );
		$this->assertFileExists( $t_target . '/one.html' );
	}

	public function testSymbolicLinkPageNotReplaced() {
		$t_target = $this->root . '/site';
		mkdir( $t_target );
		symlink( '/etc/hostname', $t_target . '/evil.html' );
		$this->expectException( ClientException::class );
		dwg_publish_write( $t_target, 'evil.html', 'x' );
	}

	public function testSettingsRefuseTargetOutsideRoots() {
		$this->expectException( ClientException::class );
		dwg_publish_settings_save( 1, '/etc', '', '', '' );
	}

	public function testAbsoluteUrls() {
		$t_saved = config_get_global( 'path' );
		try {
			config_set_global( 'path', 'https://qms.example.com:8443/doctis/' );
			$this->assertSame( 'https://qms.example.com:8443/site/a%20b.html', dwg_publish_absolute_url( '/site/a%20b.html' ) );
			$this->assertSame( 'https://other.example/x.html', dwg_publish_absolute_url( '//other.example/x.html' ) );
			$this->assertSame( 'http://host/site/x.html', dwg_publish_absolute_url( 'http://host/site/x.html' ) );
			$this->assertSame( '', dwg_publish_absolute_url( '' ) );
			config_set_global( 'path', '' );
			$this->assertSame( '', dwg_publish_absolute_url( '/site/x.html' ), 'no Doctis address to complete it' );
		} finally {
			config_set_global( 'path', $t_saved );
		}
	}

	public function testPdfHelpers() {
		$this->assertSame( 'a/b.pdf', dwg_publish_pdf_path( 'a/b.html' ) );
		$this->assertSame( '\{x\}\_\%\&\#\$ \textbackslash{}y', dwg_publish_latex_escape( "{x}_%&#\$\n\\y" ) );
		$t_header = dwg_publish_pdf_header( array( 'metadata' => array(
			'doctis-number' => 'PROC_1', 'doctis-revision' => 'A}', 'doctis-status' => 'APPROVED', 'doctis-title' => 'T',
			'doctis-classification' => 'Internal & co' ) ) );
		$this->assertStringContainsString( '\fancyhead[C]{\small\bfseries INTERNAL \& CO}', $t_header );
		$this->assertStringContainsString( '{PROC\_1 Rev A\} --- APPROVED}', $t_header );
		$this->assertStringContainsString( 'UNCONTROLLED COPY WHEN PRINTED OR TRANSMITTED', $t_header );
	}

	public function testPdfBuildRefusesRawLatex() {
		if( dwg_publish_pdf_problem() !== '' ) {
			$this->markTestSkipped( 'No PDF engine: ' . dwg_publish_pdf_problem() );
		}
		$t_secret = $this->root . '/secret.txt';
		file_put_contents( $t_secret, 'SECRET-VALUE' );
		$t_work = dwg_publish_work_dir();
		try {
			file_put_contents( $t_work . '/source.md', "Before \\input{" . $t_secret . "} after\n\n```{=latex}\n\\input{" . $t_secret . "}\n```\n\n~~struck — dash~~\n" );
			$t_pdf = dwg_publish_build_pdf( $t_work, array( 'metadata' => array(
				'pagetitle' => 'T', 'doctis-number' => 'N-1', 'doctis-revision' => 'A', 'doctis-status' => 'DRAFT' ), 'variables' => array() ) );
			$this->assertStringStartsWith( '%PDF', $t_pdf );
			if( !is_executable( '/usr/bin/pdftotext' ) ) {
				$this->markTestIncomplete( 'pdftotext (poppler-utils) is needed to read the PDF text.' );
			}
			file_put_contents( $this->root . '/out.pdf', $t_pdf );
			$t_text = (string)shell_exec( '/usr/bin/pdftotext ' . escapeshellarg( $this->root . '/out.pdf' ) . ' -' );
			$this->assertStringNotContainsString( 'SECRET-VALUE', $t_text );
			$this->assertStringContainsString( 'input{', $t_text, 'raw LaTeX is printed as text' );
			$this->assertStringContainsString( 'struck — dash', $t_text, 'em dash kept in strikethrough' );
		} finally {
			dwg_publish_work_dir_delete( $t_work );
		}
	}

	public function testPublishAndWithdraw() {
		$t_dwg_id = (int)getenv( 'DOCTIS_PUBLISH_TEST_DWG' );
		if( $t_dwg_id <= 0 ) {
			$this->markTestSkipped( 'Set DOCTIS_PUBLISH_TEST_DWG to the id of a public Markdown document.' );
		}
		self::login();
		$t_project_id = (int)dwg_get_field( $t_dwg_id, 'project_id' );
		$t_target = $this->root . '/site';
		config_set( 'dwg_publish_target', $t_target, ALL_USERS, $t_project_id );
		config_set( 'dwg_publish_url', '/test-site/', ALL_USERS, $t_project_id );
		$t_with_pdf = dwg_publish_pdf_problem() === '';
		config_set( 'dwg_publish_pdf', $t_with_pdf ? ON : OFF, ALL_USERS, $t_project_id );
		$t_link_before = (string)dwg_get_field( $t_dwg_id, 'link_url' );
		try {
			$this->assertSame( '', dwg_publish_refusal( $t_dwg_id ) );
			$t_result = dwg_publish( $t_dwg_id, auth_get_current_user_id() );
			$t_file = $t_target . '/' . $t_result['page'];
			$this->assertFileExists( $t_file );
			$this->assertSame( '/test-site/' . dwg_publish_href( $t_result['page'] ), $t_result['url'] );

			$t_html = file_get_contents( $t_file );
			$this->assertStringContainsString( 'class="doctis-status"', $t_html );
			$this->assertStringContainsString( DWG_PUBLISH_NAV_BEGIN, $t_html );
			$this->assertStringContainsString( '<div class="doctis-control">', $t_html );
			$t_published = dwg_publish_get( $t_dwg_id );
			$this->assertSame( file_dwg_primary_get( $t_dwg_id )['git_sha'], $t_published['sha'] );
			$this->assertFalse( dwg_publish_is_stale( $t_published, $t_dwg_id ) );
			$this->assertSame( dwg_publish_absolute_url( $t_result['url'] ), dwg_get_field( $t_dwg_id, 'link_url' ), 'URL field set' );
			$this->assertNotNull( dwg_publish_read_marker( $t_target . '/index.html' ) );
			if( $t_with_pdf ) {
				$this->assertSame( dwg_publish_pdf_path( $t_result['page'] ), $t_result['pdf'] );
				$this->assertStringStartsWith( '%PDF', file_get_contents( $t_target . '/' . $t_result['pdf'] ) );
				$this->assertStringContainsString( 'href="' . rawurlencode( basename( $t_result['pdf'] ) ) . '">PDF</a>', $t_html );
			}

			$this->assertSame( 1, dwg_publish_withdraw( $t_dwg_id, auth_get_current_user_id() ) );
			$this->assertFileDoesNotExist( $t_file );
			$this->assertFileDoesNotExist( $t_target . '/' . dwg_publish_pdf_path( $t_result['page'] ) );
			$this->assertFileDoesNotExist( $t_target . '/index.html', 'the index goes with the last page' );
			$this->assertNull( dwg_publish_get( $t_dwg_id ) );
			$this->assertSame( '', dwg_get_field( $t_dwg_id, 'link_url' ), 'URL field cleared' );
		} finally {
			config_delete( 'dwg_publish_target', ALL_USERS, $t_project_id );
			config_delete( 'dwg_publish_url', ALL_USERS, $t_project_id );
			config_delete( 'dwg_publish_pdf', ALL_USERS, $t_project_id );
			dwg_publish_set_link_url( $t_dwg_id, $t_link_before, auth_get_current_user_id() );
		}
	}
}
