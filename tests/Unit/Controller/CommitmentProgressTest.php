<?php

/**
 * Unit tests for adding a progress entry to a commitment (followup-public-progress).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\CommitmentController;
use OCA\Decidiq\Service\CommitmentProgressService;
use OCA\Decidiq\Service\MeetingRoleGate;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The chair or secretary of the commitment's meeting adds a dated progress
 * entry; anyone else is refused and the commitment is unchanged.
 *
 * @covers \OCA\Decidiq\Controller\CommitmentController
 * @covers \OCA\Decidiq\Service\CommitmentProgressService
 * @covers \OCA\Decidiq\Service\MeetingRoleGate
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
class CommitmentProgressTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The participant resolver double.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participants;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The controller under test.
	 *
	 * @var CommitmentController
	 */
	private CommitmentController $controller;

	/**
	 * Build the controller over the real service and role gate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->participants = $this->createMock(ParticipantResolver::class);
		$this->request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bert');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn(
			[
				'uuid' => 'c-1',
				'meeting' => 'meeting-1',
				'progress' => [['date' => '2026-09-01', 'note' => 'Research started']],
			]
		);
		$this->objectService->method('find')->willReturn($entity);

		$service = new CommitmentProgressService(
			objectService: $this->objectService,
			roleGate: new MeetingRoleGate(
				groupManager: $this->createMock(IGroupManager::class),
				participantResolver: $this->participants,
			),
		);

		$this->controller = new CommitmentController(
			request: $this->request,
			progress: $service,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The secretary of the meeting adds an entry dated today.
	 *
	 * @return void
	 */
	public function testSecretaryAddsProgressEntry(): void {
		$this->participants->method('hasRole')
			->with('meeting-1', 'bert', ['chair', 'secretary'])
			->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['note', null, '  Draft report sent to the committee  ']]);

		$today = (new \DateTimeImmutable())->format('Y-m-d');
		$expected = [
			['date' => '2026-09-01', 'note' => 'Research started'],
			['date' => $today, 'note' => 'Draft report sent to the committee'],
		];
		$this->objectService->expects(self::once())->method('patchObject')
			->with('c-1', ['progress' => $expected], 'decidiq', 'governance-commitment', false);

		$response = $this->controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($expected, $response->getData()['progress']);
	}//end testSecretaryAddsProgressEntry()

	/**
	 * A member who does not chair or minute the meeting is refused and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testMemberIsRefused(): void {
		$this->participants->method('hasRole')->willReturn(false);
		$this->request->method('getParam')->willReturnMap([['note', null, 'Done']]);
		$this->objectService->expects(self::never())->method('patchObject');

		$response = $this->controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testMemberIsRefused()

	/**
	 * An empty note is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testEmptyNoteIsRefused(): void {
		$this->participants->method('hasRole')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['note', null, '   ']]);
		$this->objectService->expects(self::never())->method('patchObject');

		$response = $this->controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testEmptyNoteIsRefused()
}//end class
