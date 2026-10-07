<?php
# Doctis user profile command tests.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class UserProfileUpdateCommandTest extends MantisCoreBase {
	private $user_id;
	private $manager_id;
	private $original;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->user_id = auth_get_current_user_id();
		$this->original = user_get_row( $this->user_id );
		$t_cookie = user_create(
			'OrgManager' . rand(),
			'password',
			'org-manager-' . rand() . '@example.test'
		);
		$this->manager_id = user_get_id_by_cookie( $t_cookie );
	}

	protected function tearDown(): void {
		user_set_fields( $this->user_id, array(
			'realname' => $this->original['realname'],
			'position_title' => $this->original['position_title'],
			'company' => $this->original['company'],
			'phone' => $this->original['phone'],
			'department' => $this->original['department'],
			'reports_to' => $this->original['reports_to'],
			'alternative' => $this->original['alternative'],
			'meeting_invite' => $this->original['meeting_invite'],
			'email_secondary' => $this->original['email_secondary'],
		) );
		user_delete( $this->manager_id );
		parent::tearDown();
	}

	public function testUpdatesOrganisationFields() {
		$t_command = new \UserProfileUpdateCommand( $this->commandData( $this->manager_id, 'External fallback' ) );
		$t_command->execute();
		$t_user = user_get_row( $this->user_id );

		$this->assertSame( $this->manager_id, (int)$t_user['reports_to'] );
		$this->assertSame( 'External fallback', $t_user['alternative'] );
	}

	public function testRejectsSelfReporting() {
		$this->expectException( ClientException::class );
		$t_command = new \UserProfileUpdateCommand( $this->commandData( $this->user_id, '' ) );
		$t_command->execute();
	}

	private function commandData( $p_reports_to, $p_alternative ) {
		return array(
			'query' => array( 'user_id' => $this->user_id ),
			'payload' => array(
				'profile' => array(
					'realname' => $this->original['realname'],
					'position_title' => $this->original['position_title'],
					'company' => $this->original['company'],
					'phone' => $this->original['phone'],
					'department' => $this->original['department'],
					'reports_to' => $p_reports_to,
					'alternative' => $p_alternative,
					'meeting_invite' => $this->original['meeting_invite'],
					'email_secondary' => $this->original['email_secondary'],
				),
			),
		);
	}
}
