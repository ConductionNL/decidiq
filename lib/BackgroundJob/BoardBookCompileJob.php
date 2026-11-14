<?php

/**
 * Compiles a meeting pack out of the request.
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Kernel\Contracts\BoardBookServiceInterface;
use OCA\Decidiq\Service\BoardBook\BoardBookJobState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one compilation as the secretary or chair who asked for it, so
 * OpenRegister reads the meeting with their access, and records the outcome
 * for the status endpoint.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */
class BoardBookCompileJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time        Clock.
	 * @param BoardBookServiceInterface $boardBooks  Compiles the pack.
	 * @param BoardBookJobState         $state       Records where the compilation stands.
	 * @param IUserManager              $users       Finds the user who asked.
	 * @param IUserSession              $userSession Acts as that user.
	 * @param LoggerInterface           $logger      The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly BoardBookServiceInterface $boardBooks,
		private readonly BoardBookJobState $state,
		private readonly IUserManager $users,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Compile the pack of `meetingId` as `uid`, with `changeNote`.
	 *
	 * @param mixed $argument `{meetingId, uid, changeNote}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			$argument = [];
		}

		$meetingId = (string)($argument['meetingId'] ?? '');
		$uid = (string)($argument['uid'] ?? '');
		$user = $this->users->get($uid);
		if ($meetingId === '' || $user === null) {
			$this->logger->warning('Decidiq: meeting pack job without a meeting or a known user', ['meetingId' => $meetingId]);
			if ($meetingId !== '') {
				$this->state->set(meetingId: $meetingId, state: 'failed', outcome: ['message' => 'The user who asked for the pack no longer exists.']);
			}

			return;
		}

		$this->userSession->setUser($user);
		$this->state->set(meetingId: $meetingId, state: 'running');
		try {
			$outcome = $this->boardBooks->compile(meetingId: $meetingId, userId: $uid, changeNote: (string)($argument['changeNote'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: meeting pack compilation failed', ['meetingId' => $meetingId, 'exception' => $e->getMessage()]);
			$this->state->set(meetingId: $meetingId, state: 'failed', outcome: ['message' => 'The meeting pack could not be compiled. See the server log.']);
			return;
		}

		$state = 'failed';
		if (($outcome['success'] ?? false) === true) {
			$state = 'done';
		}

		$this->state->set(meetingId: $meetingId, state: $state, outcome: $outcome);
	}//end run()
}//end class
