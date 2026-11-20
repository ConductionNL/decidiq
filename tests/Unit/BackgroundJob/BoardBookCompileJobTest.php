<?php

/**
 * Unit tests for BoardBookCompileJob.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\BoardBookCompileJob;
use OCA\Decidiq\Kernel\Contracts\BoardBookServiceInterface;
use OCA\Decidiq\Service\BoardBook\BoardBookJobState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The job compiles as the user who asked and records the outcome.
 */
#[CoversClass(BoardBookCompileJob::class)]
class BoardBookCompileJobTest extends TestCase {

	/**
	 * Run the job with these collaborators.
	 *
	 * @param BoardBookServiceInterface $boardBooks The service.
	 * @param BoardBookJobState         $state      The state.
	 * @param IUserManager              $users      The users.
	 * @param IUserSession              $session    The session.
	 * @param array<string, string>     $argument   The job argument.
	 *
	 * @return void
	 */
	private function runJob(BoardBookServiceInterface $boardBooks, BoardBookJobState $state, IUserManager $users, IUserSession $session, array $argument): void {
		$job = new BoardBookCompileJob(
			time: $this->createMock(ITimeFactory::class),
			boardBooks: $boardBooks,
			state: $state,
			users: $users,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * It acts as the user, passes the note, and records done with the outcome.
	 *
	 * @return void
	 */
	public function testCompilesAsTheUserAndRecordsTheOutcome(): void {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('griffier')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->once())->method('setUser')->with($user);
		$boardBooks = $this->createMock(BoardBookServiceInterface::class);
		$boardBooks->expects($this->once())->method('compile')->with('m1', 'griffier', 'note')->willReturn(['success' => true, 'version' => 2]);
		$state = $this->createMock(BoardBookJobState::class);
		$recorded = [];
		$state->method('set')->willReturnCallback(
			static function (string $meetingId, string $phase, array $outcome=[]) use (&$recorded): void {
				$recorded[] = [$meetingId, $phase, $outcome];
			}
		);

		$this->runJob(boardBooks: $boardBooks, state: $state, users: $users, session: $session, argument: ['meetingId' => 'm1', 'uid' => 'griffier', 'changeNote' => 'note']);

		$this->assertSame(expected: [['m1', 'running', []], ['m1', 'done', ['success' => true, 'version' => 2]]], actual: $recorded);
	}//end testCompilesAsTheUserAndRecordsTheOutcome()

	/**
	 * A user who no longer exists compiles nothing; the meeting reads failed.
	 *
	 * @return void
	 */
	public function testAnUnknownUserCompilesNothing(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$boardBooks = $this->createMock(BoardBookServiceInterface::class);
		$boardBooks->expects($this->never())->method('compile');
		$state = $this->createMock(BoardBookJobState::class);
		$state->expects($this->once())->method('set')->with('m1', 'failed', $this->anything());

		$this->runJob(boardBooks: $boardBooks, state: $state, users: $users, session: $this->createMock(IUserSession::class), argument: ['meetingId' => 'm1', 'uid' => 'weg']);
	}//end testAnUnknownUserCompilesNothing()
}//end class
