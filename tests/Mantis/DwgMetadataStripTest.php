<?php
# Doctis metadata cut-over: removing lifecycle metadata from Markdown content
# (core/dwg_metadata_api.php, doc/publish/METADATA_CUTOVER.md).

namespace Mantis\tests\Mantis;

class DwgMetadataStripTest extends MantisCoreBase {
	protected function setUp(): void {
		parent::setUp();
		require_api( 'dwg_metadata_api.php' );
	}

	private function strip( string $p_content, array &$p_removed = array() ): string {
		return dwg_metadata_strip( $p_content, $p_removed );
	}

	public function testOneFieldPerLine() {
		$t_in = "---\ndoc_id:         PROC-SYS-001\ntitle:          Document Control Procedure\nrevision:       A\n"
			. "status:         Draft         # Draft | Under Review\nowner:          QMS Lead\napprover:\neffective_date:\n"
			. "review_period:  12 months\nclassification: Internal\n---\n\n# Document Control Procedure\n\n"
			. "**Document ID:** PROC-SYS-001\n**Revision:** A\n**Status:** Draft\n**Owner:** QMS Lead\n**Classification:** Internal\n\n"
			. "---\n\n## Related Documents\n\nText.\n";
		$t_removed = array();
		$t_out = $this->strip( $t_in, $t_removed );
		$this->assertSame( "---\ndoc_id:         PROC-SYS-001\n---\n\n# Document Control Procedure\n\n## Related Documents\n\nText.\n", $t_out );
		$this->assertCount( 8, $t_removed['keys'] );
		$this->assertSame( array( 'Document ID', 'Revision', 'Status', 'Owner', 'Classification' ), $t_removed['labels'] );
	}

	public function testPipeSeparatedLabelsKeepOthers() {
		$t_in = "# PRD\n\n**Document ID:** PRD-1 | **Revision:** A | **Status:** Draft | **Owner:** Lead\n\n"
			. "**Associated ICD:** [ICD](icd.md) |\n**Associated Test Procedures:** [TP](tp.md)\n\n**Related documents:**\n\n| A | B |\n";
		$t_out = $this->strip( $t_in );
		$this->assertSame( "# PRD\n\n**Associated ICD:** [ICD](icd.md) |\n**Associated Test Procedures:** [TP](tp.md)\n\n**Related documents:**\n\n| A | B |\n", $t_out );
	}

	public function testMixedSegmentsOnOneLine() {
		$t_out = $this->strip( "# T\n\n**Date:** 1 May | **Status:** Draft | **Chair:** Ann\n\nBody\n" );
		$this->assertSame( "# T\n\n**Date:** 1 May | **Chair:** Ann\n\nBody\n", $t_out );
	}

	public function testMeetingRecordKeepsMeetingFields() {
		$t_in = "---\ndoc_id: MIN-1\ntitle: Meeting\nmeeting_type: Team\nstatus: Agenda\nchair: Ann\n---\n\n<!-- markdownlint-disable MD025 -->\n# Meeting\n\n"
			. "**Date:** 1 May\n**Chair:** Ann\n**Document ID:** MIN-1\n**Status:** Agenda\n\n> Note\n";
		$t_out = $this->strip( $t_in );
		$this->assertSame( "---\ndoc_id: MIN-1\nmeeting_type: Team\nchair: Ann\n---\n\n<!-- markdownlint-disable MD025 -->\n# Meeting\n\n"
			. "**Date:** 1 May\n**Chair:** Ann\n\n> Note\n", $t_out );
	}

	public function testOnlyTheHeaderRegionIsTouched() {
		$t_in = "# T\n\nIntro paragraph.\n\n**Status:** this line is body text\n";
		$this->assertSame( $t_in, $this->strip( $t_in ) );
	}

	public function testFrontmatterRemovedWhenEmpty() {
		$t_out = $this->strip( "---\ntitle: T\nstatus: Draft\n---\n\n# T\n\nBody\n" );
		$this->assertSame( "# T\n\nBody\n", $t_out );
	}

	public function testListContinuationGoesWithKey() {
		$t_out = $this->strip( "---\ndoc_id: X\nowner:\n  - Ann\n  - Bob\ntags: [a]\n---\nBody\n" );
		$this->assertSame( "---\ndoc_id: X\ntags: [a]\n---\nBody\n", $t_out );
	}

	public function testNoMetadataUnchanged() {
		$t_in = "# TODO\n\nNothing here yet.\n";
		$this->assertSame( $t_in, $this->strip( $t_in ) );
	}

	public function testCrlfPreserved() {
		$t_out = $this->strip( "# T\r\n\r\n**Status:** Draft\r\n\r\nBody\r\n" );
		$this->assertSame( "# T\r\n\r\nBody\r\n", $t_out );
	}

	public function testIdempotent() {
		$t_in = "---\ndoc_id: X\nstatus: Draft\n---\n\n# T\n\n**Revision:** A\n\n---\n\nBody\n";
		$t_once = $this->strip( $t_in );
		$this->assertSame( $t_once, $this->strip( $t_once ) );
	}
}
