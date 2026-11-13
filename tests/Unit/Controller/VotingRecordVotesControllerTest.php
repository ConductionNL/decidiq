<?php

/**
 * VotingController::recordVotes(): only the round's own chair or secretary, refusals answered with their names.
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
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Exception\RecordedVotesRefusedException;
use OCA\Decidiq\Kernel\Contracts\OriPublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ProxyDelegationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingErrorResponderInterface;
use OCA\Decidiq\Kernel\Contracts\VotingOpenRequestHandlerInterface;
use OCA\Decidiq\Kernel\Contracts\VotingRoundGuardInterface;
use OCA\Decidiq\Kernel\Contracts\VotingServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The guard runs before the list is read; the service's refusal becomes the answer.
 */
class VotingRecordVotesControllerTest extends TestCase {

	/**
	 * The voting service double.
	 *
	 * @var VotingServiceInterface&MockObject
	 */
	private VotingServiceInterface&MockObject $voting;

	/**
	 * The controller with a guard that answers the given refusal, or lets through when null.
	 *
	 * @param JSONResponse|null $guardAnswer The guard's answer.
	 *
	 * @return VotingController
	 */
	private function controller(?JSONResponse $guardAnswer): VotingController {
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

		$this->voting = $this->createMock(VotingServiceInterface::class);

		return new VotingController(
			request: $request,
			votingService: $this->voting,
			oriService: $this->createMock(OriPublicationServiceInterface::class),
			userSession: $session,
			guard: $guard,
			openHandler: $this->createMock(VotingOpenRequestHandlerInterface::class),
			proxyService: $this->createMock(ProxyDelegationServiceInterface::class),
			errors: $this->createMock(VotingErrorResponderInterface::class),
		);
	}//end controller()

	/**
	 * A member without a presiding role gets 403 and nothing is recorded.
	 *
	 * @return void
	 */
	public function testOnlyTheRoundsOwnChairOrSecretary(): void {
		$controller = $this->controller(guardAnswer: new JSONResponse(['message' => 'forbidden'], 403));
		$this->voting->expects($this->never())->method('recordNamedVotes');

		$this->assertSame(403, $controller->recordVotes(id: 'round-1')->getStatus());
	}//end testOnlyTheRoundsOwnChairOrSecretary()

	/**
	 * The secretary's list reaches the service with the caller as recorder.
	 *
	 * @return void
	 */
	public function testTheListIsRecordedByTheCaller(): void {
		$controller = $this->controller(guardAnswer: null);
		$this->voting->expects($this->once())->method('recordNamedVotes')
			->with('round-1', [['participantId' => 'p-1', 'value' => 'for']], '2026-03-12T21:40:00+01:00', 'griffier1')
			->willReturn(['votingRoundId' => 'round-1', 'recorded' => 1]);

		$response = $controller->recordVotes(id: 'round-1');

		$this->assertSame([200, ['votingRoundId' => 'round-1', 'recorded' => 1]], [$response->getStatus(), $response->getData()]);
	}//end testTheListIsRecordedByTheCaller()

	/**
	 * A refusal is answered with its status and the members it names.
	 *
	 * @return void
	 */
	public function testARefusalNamesTheMembers(): void {
		$controller = $this->controller(guardAnswer: null);
		$this->voting->method('recordNamedVotes')->willThrowException(
			new RecordedVotesRefusedException(message: 'These members cast their own vote, which is not replaced: p-2', status: 409, participantIds: ['p-2'])
		);

		$response = $controller->recordVotes(id: 'round-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['p-2'], $response->getData()['participantIds']);
	}//end testARefusalNamesTheMembers()
}//end class
