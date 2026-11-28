<?php

/**
 * NamedVotesController::record(): only the round's own chair or secretary, refusals answered with their names.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\NamedVotesController;
use OCA\Decidiq\Exception\RecordedVotesRefusedException;
use OCA\Decidiq\Kernel\Contracts\VotingRoundGuardInterface;
use OCA\Decidiq\Service\RecordedVoteWriter;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The guard runs before the list is read; the writer's refusal becomes the answer.
 */
class NamedVotesControllerTest extends TestCase {

	/**
	 * The writer double.
	 *
	 * @var RecordedVoteWriter&MockObject
	 */
	private RecordedVoteWriter&MockObject $writer;

	/**
	 * The controller with a guard that answers the given refusal, or lets through when null.
	 *
	 * @param JSONResponse|null $guardAnswer The guard's answer.
	 *
	 * @return NamedVotesController
	 */
	private function controller(?JSONResponse $guardAnswer): NamedVotesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(
			['heldAt' => '2026-03-12T21:40:00+01:00', 'entries' => [['participantId' => 'p-1', 'value' => 'for']]]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$guard = $this->createMock(VotingRoundGuardInterface::class);
		$guard->method('resolveMeetingIdFromVotingRound')->willReturn('meeting-1');
		$guard->expects($this->once())->method('requireChairOrSecretary')->with('meeting-1')->willReturn($guardAnswer);

		$this->writer = $this->createMock(RecordedVoteWriter::class);

		return new NamedVotesController(
			request: $request,
			writer: $this->writer,
			userSession: $session,
			guard: $guard,
		);
	}//end controller()

	/**
	 * A member without a presiding role gets 403 and nothing is recorded.
	 *
	 * @return void
	 */
	public function testOnlyTheRoundsOwnChairOrSecretary(): void {
		$controller = $this->controller(guardAnswer: new JSONResponse(['message' => 'forbidden'], 403));
		$this->writer->expects($this->never())->method('record');

		$this->assertSame(403, $controller->record(id: 'round-1')->getStatus());
	}//end testOnlyTheRoundsOwnChairOrSecretary()

	/**
	 * The secretary's list reaches the writer with the caller as recorder.
	 *
	 * @return void
	 */
	public function testTheListIsRecordedByTheCaller(): void {
		$controller = $this->controller(guardAnswer: null);
		$this->writer->expects($this->once())->method('record')
			->with('round-1', [['participantId' => 'p-1', 'value' => 'for']], '2026-03-12T21:40:00+01:00', 'griffier1')
			->willReturn(['votingRoundId' => 'round-1', 'recorded' => 1]);

		$response = $controller->record(id: 'round-1');

		$this->assertSame([200, ['votingRoundId' => 'round-1', 'recorded' => 1]], [$response->getStatus(), $response->getData()]);
	}//end testTheListIsRecordedByTheCaller()

	/**
	 * A refusal is answered with its status and the members it names.
	 *
	 * @return void
	 */
	public function testARefusalNamesTheMembers(): void {
		$controller = $this->controller(guardAnswer: null);
		$this->writer->method('record')->willThrowException(
			new RecordedVotesRefusedException(message: 'These members cast their own vote, which is not replaced: p-2', status: 409, participantIds: ['p-2'])
		);

		$response = $controller->record(id: 'round-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['p-2'], $response->getData()['participantIds']);
	}//end testARefusalNamesTheMembers()
}//end class
