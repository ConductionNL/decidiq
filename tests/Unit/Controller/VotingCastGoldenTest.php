<?php

/**
 * Golden runs of VotingController::cast().
 *
 * Pins, for every outcome, the status, the body and what reached the voting
 * service: no session (401), no participant profile (403), a missing and an
 * unknown value (400), a ranked ballot, a plain vote, a proxy vote with and
 * without a delegator, and a refusal from the service (400). Pinned before
 * cast() was split into named steps.
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
 * @spec exclude golden test of the vote cast endpoint, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VoteCast;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VotingCastGoldenTest extends TestCase {

	/**
	 * What reached the voting service.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $casts = [];

	/**
	 * Every outcome is unchanged.
	 *
	 * @return void
	 */
	public function testEveryOutcomeIsUnchanged(): void {
		$runs = [
			'no session' => $this->runCast(null, 'p-1', ['value' => 'for']),
			'no participant' => $this->runCast('alice', null, ['value' => 'for']),
			'no value' => $this->runCast('alice', 'p-1', []),
			'unknown value' => $this->runCast('alice', 'p-1', ['value' => 'maybe']),
			'ranked' => $this->runCast('alice', 'p-1', ['ranking' => ['b', 'a'], 'value' => 'ignored']),
			'plain' => $this->runCast('alice', 'p-1', ['value' => 'against', 'delegatorId' => 'd-1']),
			'proxy with delegator' => $this->runCast('alice', 'p-1', ['value' => 'abstain', 'isProxy' => true, 'delegatorId' => 'd-1']),
			'proxy without delegator' => $this->runCast('alice', 'p-1', ['value' => 'for', 'isProxy' => 1, 'delegatorId' => '']),
			'refused by the service' => $this->runCast('bob', 'p-2', ['value' => 'for']),
		];

		self::assertSame(
			[
				'no session' => [401, ['message' => 'Unauthenticated'], []],
				'no participant' => [403, ['message' => 'Geen deelnemersprofiel gevonden voor de ingelogde gebruiker'], []],
				'no value' => [400, ['message' => 'value is required'], []],
				'unknown value' => [400, ['message' => 'value must be for, against, or abstain'], []],
				'ranked' => [201, ['ok' => true], [['round' => 'r-1', 'participant' => 'p-1', 'value' => 'ranked', 'ranking' => ['b', 'a'], 'proxy' => false, 'delegator' => null, 'caller' => 'alice']]],
				'plain' => [201, ['ok' => true], [['round' => 'r-1', 'participant' => 'p-1', 'value' => 'against', 'ranking' => null, 'proxy' => false, 'delegator' => null, 'caller' => 'alice']]],
				'proxy with delegator' => [201, ['ok' => true], [['round' => 'r-1', 'participant' => 'p-1', 'value' => 'abstain', 'ranking' => null, 'proxy' => true, 'delegator' => 'd-1', 'caller' => 'alice']]],
				'proxy without delegator' => [201, ['ok' => true], [['round' => 'r-1', 'participant' => 'p-1', 'value' => 'for', 'ranking' => null, 'proxy' => true, 'delegator' => null, 'caller' => 'alice']]],
				'refused by the service' => [400, ['message' => 'not open'], [['round' => 'r-1', 'participant' => 'p-2', 'value' => 'for', 'ranking' => null, 'proxy' => false, 'delegator' => null, 'caller' => 'bob']]],
			],
			$runs
		);
	}//end testEveryOutcomeIsUnchanged()

	/**
	 * Cast once.
	 *
	 * @param string|null          $uid         The signed-in user.
	 * @param string|null          $participant Their participant profile.
	 * @param array<string, mixed> $params      The request body.
	 *
	 * @return array{0: int, 1: mixed, 2: list<array<string, mixed>>} Status, body and casts.
	 */
	private function runCast(?string $uid, ?string $participant, array $params): array {
		$this->casts = [];
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$voting = $this->createMock(VotingService::class);
		$voting->method('resolveParticipantUuid')->willReturn($participant);
		$voting->method('castVote')->willReturnCallback(
			function (VoteCast $cast, string $callerUid): array {
				$this->casts[] = [
					'round' => $cast->votingRoundId,
					'participant' => $cast->participantId,
					'value' => $cast->value(),
					'ranking' => $cast->ranking(),
					'proxy' => $cast->isProxy(),
					'delegator' => $cast->delegatorId(),
					'caller' => $callerUid,
				];
				if ($callerUid === 'bob') {
					throw new InvalidArgumentException('not open');
				}

				return ['ok' => true];
			}
		);

		$controller = new VotingController(
			$request,
			$voting,
			$this->createMock(OriPublicationService::class),
			$session,
			$this->createMock(VotingRoundGuard::class),
			$this->createMock(VotingOpenRequestHandler::class),
			$this->createMock(ProxyDelegationService::class),
			new VotingErrorResponder($this->createMock(LoggerInterface::class)),
		);
		$response = $controller->cast('r-1');
		return [$response->getStatus(), $response->getData(), $this->casts];
	}//end runCast()
}//end class
