<?php

/**
 * Unit tests for PaperVoteController: who may enter a paper vote, and what
 * reaches the recorder (board DcStemmenInvoeren).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\PaperVoteController;
use OCA\Decidiq\Service\PaperVoteRecorder;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * The paper vote endpoint is guarded and hands the ballots on as given.
 */
class PaperVoteControllerTest extends TestCase {

	/**
	 * Build the controller for a user (null for none) and request parameters.
	 *
	 * @param string|null $uid The logged-in user, or null.
	 * @param array<string, mixed> $params The request parameters.
	 * @param PaperVoteRecorder $recorder The recorder double.
	 *
	 * @return PaperVoteController
	 */
	private function controller(?string $uid, array $params, PaperVoteRecorder $recorder): PaperVoteController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new PaperVoteController(
			request: $request,
			recorder: $recorder,
			userSession: $session,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $groupManager,
				appConfig: $this->createMock(originalClassName: IAppConfig::class),
				participantResolver: $this->createMock(originalClassName: ParticipantResolver::class),
				container: $this->createMock(originalClassName: ContainerInterface::class),
			),
		);
	}//end controller()

	/**
	 * Without a user nothing is recorded.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallerIsRefused(): void {
		$recorder = $this->createMock(originalClassName: PaperVoteRecorder::class);
		$recorder->expects($this->never())->method('recordVotes');

		$result = $this->controller(uid: null, params: [], recorder: $recorder)->record(id: 'round-1');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $result->getStatus());
	}//end testAnUnauthenticatedCallerIsRefused()

	/**
	 * An authorised caller's ballots reach the recorder, with the caller as
	 * the one who entered them.
	 *
	 * @return void
	 */
	public function testTheBallotsReachTheRecorder(): void {
		$votes = [['participant' => 'p-1', 'value' => 'for']];
		$recorder = $this->createMock(originalClassName: PaperVoteRecorder::class);
		$recorder->expects($this->once())
			->method('recordVotes')
			->with('round-1', $votes, 'griffier')
			->willReturn(['recorded' => 1, 'refused' => []]);

		$result = $this->controller(uid: 'griffier', params: ['votes' => $votes], recorder: $recorder)->record(id: 'round-1');

		self::assertSame(expected: Http::STATUS_CREATED, actual: $result->getStatus());
		self::assertSame(expected: ['recorded' => 1, 'refused' => []], actual: $result->getData());
	}//end testTheBallotsReachTheRecorder()

	/**
	 * A request without ballots is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyListIsRefused(): void {
		$recorder = $this->createMock(originalClassName: PaperVoteRecorder::class);
		$recorder->expects($this->never())->method('recordVotes');

		$result = $this->controller(uid: 'griffier', params: ['votes' => []], recorder: $recorder)->record(id: 'round-1');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $result->getStatus());
	}//end testAnEmptyListIsRefused()

	/**
	 * The endpoint carries NoAdminRequired, so a chair who is not an admin reaches the guard.
	 *
	 * @return void
	 */
	public function testTheEndpointIsOpenToNonAdmins(): void {
		$method = new ReflectionMethod(objectOrMethod: PaperVoteController::class, method: 'record');

		self::assertCount(expectedCount: 1, haystack: $method->getAttributes(name: NoAdminRequired::class));
	}//end testTheEndpointIsOpenToNonAdmins()
}//end class
