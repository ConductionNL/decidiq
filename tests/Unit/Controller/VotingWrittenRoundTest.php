<?php

/**
 * Tests for the written-round branch of VotingController.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Kernel\Contracts\OriPublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ProxyDelegationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingOpenRequestHandlerInterface;
use OCA\Decidiq\Kernel\Contracts\VotingRoundGuardInterface;
use OCA\Decidiq\Kernel\Contracts\VotingServiceInterface;
use OCA\Decidiq\Kernel\Contracts\WrittenRoundServiceInterface;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A written round opens and is answered past the meeting guard and the
 * participant lookup, with its own refusals mapped to 403, 404 and 400.
 *
 * @covers \OCA\Decidiq\Controller\VotingController
 * @uses   \OCA\Decidiq\Service\VotingErrorResponder
 */
class VotingWrittenRoundTest extends TestCase {

	/**
	 * The written-round service.
	 *
	 * @var WrittenRoundServiceInterface&MockObject
	 */
	private WrittenRoundServiceInterface&MockObject $written;

	/**
	 * The meeting guard.
	 *
	 * @var VotingRoundGuardInterface&MockObject
	 */
	private VotingRoundGuardInterface&MockObject $guard;

	/**
	 * The voting service.
	 *
	 * @var VotingServiceInterface&MockObject
	 */
	private VotingServiceInterface&MockObject $voting;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->written = $this->createMock(WrittenRoundServiceInterface::class);
		$this->guard   = $this->createMock(VotingRoundGuardInterface::class);
		$this->voting  = $this->createMock(VotingServiceInterface::class);
	}//end setUp()

	/**
	 * A controller for a request, signed in as a user or anonymous.
	 *
	 * @param array<string, mixed> $params The request body.
	 * @param string|null          $uid    The user, or null.
	 *
	 * @return VotingController
	 */
	private function controller(array $params, ?string $uid='jan'): VotingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new VotingController(
			request: $request,
			votingService: $this->voting,
			oriService: $this->createMock(OriPublicationServiceInterface::class),
			userSession: $session,
			guard: $this->guard,
			openHandler: $this->createMock(VotingOpenRequestHandlerInterface::class),
			proxyService: $this->createMock(ProxyDelegationServiceInterface::class),
			errors: new VotingErrorResponder(logger: new NullLogger()),
			written: $this->written,
		);
	}//end controller()

	/**
	 * A written open goes to the written service as the signed-in user, never the meeting guard.
	 *
	 * @return void
	 */
	public function testAWrittenOpenSkipsTheMeetingGuard(): void {
		$params = ['procedure' => 'written', 'decisionId' => 'd-1', 'governanceBodyId' => 'board', 'votingDeadline' => '2030-01-01T00:00:00Z'];
		$this->guard->expects($this->never())->method('requireChairOrSecretary');
		$this->written->expects($this->once())->method('open')->with($params, 'jan')->willReturn(['id' => 'r-1', 'procedure' => 'written']);

		$response = $this->controller(params: $params)->open();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'r-1', 'procedure' => 'written'], $response->getData());
	}//end testAWrittenOpenSkipsTheMeetingGuard()

	/**
	 * The opener's refusals: outside the signatory scope 403, a bad request 400, anonymous 401.
	 *
	 * @return void
	 */
	public function testWrittenOpenRefusals(): void {
		$this->written->method('open')->willReturnCallback(
			static function (array $params, string $userId): array {
				if ($userId === 'mark') {
					throw new AccessDeniedException('Only the chair, vice-chair or secretary of the body can put a decision to it in writing.');
				}

				throw new InvalidArgumentException('These members have no Nextcloud account and cannot answer: Mark');
			}
		);

		$denied = $this->controller(params: ['procedure' => 'written'], uid: 'mark')->open();
		$this->assertSame(Http::STATUS_FORBIDDEN, $denied->getStatus());
		$this->assertSame(['message' => 'Only the chair, vice-chair or secretary of the body can put a decision to it in writing.'], $denied->getData());

		$bad = $this->controller(params: ['procedure' => 'written'], uid: 'jan')->open();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());
		$this->assertSame(['message' => 'These members have no Nextcloud account and cannot answer: Mark'], $bad->getData());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(params: ['procedure' => 'written'], uid: null)->open()->getStatus());
	}//end testWrittenOpenRefusals()

	/**
	 * An answer to a written round skips the participant lookup and is mapped by refusal.
	 *
	 * @return void
	 */
	public function testAnAnswerToAWrittenRound(): void {
		$this->written->method('writtenRound')->willReturn(['id' => 'r-1', 'procedure' => 'written']);
		$this->voting->expects($this->never())->method('resolveParticipantUuid');
		$this->voting->expects($this->never())->method('castVote');
		$this->written->method('answer')->willReturnCallback(
			static function (string $roundId, string $userId, string $value): array {
				return match ($userId) {
					'outsider' => throw new AccessDeniedException('You are not one of the members this decision was put to.'),
					'gone' => throw new NotFoundException('Written voting round not found'),
					'late' => throw new InvalidArgumentException('This written round is closed.'),
					default => ['vote' => ['value' => $value, 'round' => $roundId], 'round' => ['id' => $roundId]],
				};
			}
		);

		$ok = $this->controller(params: ['value' => 'objection'], uid: 'janneke')->cast(id: 'r-1');
		$this->assertSame(Http::STATUS_CREATED, $ok->getStatus());
		$this->assertSame(['value' => 'objection', 'round' => 'r-1'], $ok->getData()['vote']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(params: ['value' => 'for'], uid: 'outsider')->cast(id: 'r-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(params: ['value' => 'for'], uid: 'gone')->cast(id: 'r-1')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(params: ['value' => 'for'], uid: 'late')->cast(id: 'r-1')->getStatus());
	}//end testAnAnswerToAWrittenRound()

	/**
	 * An objection on a round held in a meeting is refused with 400, before any vote.
	 *
	 * @return void
	 */
	public function testAnObjectionOnAMeetingRoundIsRefused(): void {
		$this->written->method('writtenRound')->willReturn(null);
		$this->voting->method('resolveParticipantUuid')->willReturn('participant-1');
		$this->voting->expects($this->never())->method('castVote');
		$this->written->expects($this->never())->method('answer');

		$response = $this->controller(params: ['value' => 'objection'], uid: 'janneke')->cast(id: 'r-meeting');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'value must be for, against, or abstain'], $response->getData());
	}//end testAnObjectionOnAMeetingRoundIsRefused()
}//end class
