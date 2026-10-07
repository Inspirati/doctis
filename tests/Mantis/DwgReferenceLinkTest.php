<?php
# MantisBT - A PHP based bugtracking system

namespace Mantis\tests\Mantis;

/**
 * Tests document reference link parsing.
 */
class DwgReferenceLinkTest extends MantisCoreBase {

	public function testGitHubMarkdownReference() {
		$t_link = string_parse_dwg_reference_link(
			'[HCROBOHYDERABAD/HCR571](https://github.com/HCROBOHYDERABAD/HCR571)'
		);

		$this->assertSame(
			array(
				'label' => 'HCROBOHYDERABAD/HCR571',
				'url' => 'https://github.com/HCROBOHYDERABAD/HCR571',
			),
			$t_link
		);
	}

	public function testRawGitHubReference() {
		$t_reference = 'https://github.com/HCROBOHYDERABAD/HCR571';

		$this->assertSame(
			array(
				'label' => $t_reference,
				'url' => $t_reference,
			),
			string_parse_dwg_reference_link( $t_reference )
		);
	}

	public function testNonLinkReferenceIsNotParsed() {
		$this->assertNull( string_parse_dwg_reference_link( 'TEAMCENTER123' ) );
	}

	public function testUnsafeMarkdownLinkIsNotParsed() {
		$this->assertNull( string_parse_dwg_reference_link( '[click](javascript:alert(1))' ) );
	}
}
