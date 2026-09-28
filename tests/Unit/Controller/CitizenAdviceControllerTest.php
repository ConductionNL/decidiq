<?php

/**
 * Unit tests for CitizenAdviceController (issue #1418).
 *
 * Who may open or close a residents' advisory vote on a motion: an admin, the
 * secretariat group, or the chair or secretary of the motion's own meeting.
 * The service below the controller is the real CitizenAdviceService over a
 * generated mock of OpenRegister's ObjectServiceInterface.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\CitizenAdviceController;
use OCA\Decidiq\Service\CitizenAdviceService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Authorisation and status codes of the advisory vote endpoints.
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
 */
class CitizenAdviceControllerTest extends TestCase {

	/**
	 * How many times the motion was written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Build the controller for one caller.
	 *
	 * @param string $uid The caller.
	 * @param array<int, string> $groups The caller's groups.
	 * @param array<int, string> $meetingRoles The caller's roles in the motion's meeting.
	 * @param array<string, mixed> $motion The stored motion.
	 *
	 * @return CitizenAdviceController
	 */
	private function controller(string $uid, array $groups, array $meetingRoles, array $motion): CitizenAdviceController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $group): bool => in_array($group, $groups, true)
		);

		$motionService = $this->createMock(MotionService::class);
		$motionService->method('resolveMeetingId')->willReturn('meeting-1');

		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('hasRole')->willReturnCallback(
			static fn (string $meetingId, string $nextcloudUid, array $roles): bool => $meetingId === 'meeting-1'
				&& array_intersect($roles, $meetingRoles) !== []
		);

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($motion);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) use ($motion): ObjectEntityInterface {
				$this->writes++;
				$stored = $this->createMock(ObjectEntityInterface::class);
				$stored->method('jsonSerialize')->willReturn(array_merge($motion, $data));

				return $stored;
			}
		);

		return new CitizenAdviceController(
			request: $this->createMock(IRequest::class),
			citizenAdviceService: new CitizenAdviceService(objectService: $objectService),
			motionService: $motionService,
			participantResolver: $participants,
			userSession: $session,
			groupManager: $groupManager,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * A published motion that allows citizen voting.
	 *
	 * @return array<string, mixed>
	 */
	private function motion(): array {
		return [
			'id' => 'm-1',
			'decisionType' => 'motion',
			'isPublished' => 'public',
			'citizenVotingAllowed' => true,
			'citizenVotingMethod' => 'simple',
		];
	}//end motion()

	/**
	 * A council member with no secretariat group and no chair or secretary
	 * role gets 403, and nothing is written.
	 *
	 * @return void
	 */
	public function testACouncilMemberCannotOpenTheAdvisoryVote(): void {
		$response = $this->controller(uid: 'member1', groups: ['decidiq-members'], meetingRoles: ['member'], motion: $this->motion())->open(id: 'm-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame(0, $this->writes);
	}//end testACouncilMemberCannotOpenTheAdvisoryVote()

	/**
	 * The secretariat, an admin, and the meeting's chair or secretary may open it.
	 *
	 * @return void
	 */
	public function testTheGriffieAndTheMeetingChairCanOpenTheAdvisoryVote(): void {
		$callers = [
			'griffier' => [['decidiq-secretariat'], []],
			'admin' => [['admin'], []],
			'chair1' => [[], ['chair']],
			'secretary1' => [[], ['secretary']],
		];

		foreach ($callers as $uid => [$groups, $roles]) {
			$response = $this->controller(uid: $uid, groups: $groups, meetingRoles: $roles, motion: $this->motion())->open(id: 'm-1');

			self::assertSame(200, $response->getStatus(), $uid . ' may open the advisory vote');
			self::assertSame('open', $response->getData()['motion']['citizenVotingStatus']);
		}
	}//end testTheGriffieAndTheMeetingChairCanOpenTheAdvisoryVote()

	/**
	 * An unpublished motion answers 422 with the reason.
	 *
	 * @return void
	 */
	public function testAnUnpublishedMotionAnswers422(): void {
		$motion = array_merge($this->motion(), ['isPublished' => 'internal']);
		$response = $this->controller(uid: 'griffier', groups: ['decidiq-secretariat'], meetingRoles: [], motion: $motion)->open(id: 'm-1');

		self::assertSame(422, $response->getStatus());
		self::assertSame('The motion must be published before residents can give their advice', $response->getData()['message']);
	}//end testAnUnpublishedMotionAnswers422()

	/**
	 * Closing is guarded the same way, and closes an open vote with its counts.
	 *
	 * @return void
	 */
	public function testCloseIsGuardedAndStoresTheCounts(): void {
		$open = array_merge($this->motion(), ['citizenVotingStatus' => 'open']);

		$denied = $this->controller(uid: 'member1', groups: [], meetingRoles: [], motion: $open)->close(id: 'm-1');
		self::assertSame(403, $denied->getStatus());

		$closed = $this->controller(uid: 'griffier', groups: ['decidiq-secretariat'], meetingRoles: [], motion: $open)->close(id: 'm-1');
		self::assertSame(200, $closed->getStatus());
		self::assertSame('closed', $closed->getData()['motion']['citizenVotingStatus']);
		self::assertSame(0, $closed->getData()['motion']['citizenAdviceFor']);
	}//end testCloseIsGuardedAndStoresTheCounts()
}//end class
