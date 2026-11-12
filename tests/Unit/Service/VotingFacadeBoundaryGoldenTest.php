<?php

/**
 * Golden test for what reaches the round opener and the vote caster.
 *
 * Opens rounds through VotingOpenRequestHandler::handle() and casts votes
 * through VotingController::cast() and the e-mail reply processor, with the
 * real VotingService in between and recording doubles for the opener and the
 * caster. It records every field of the request and the ballot they receive,
 * and the acting user passed with the ballot. The expected values live in
 * tests/fixtures/voting-facade-boundary-golden.json; run with
 * RECORD_VOTING_FACADE_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Service\MailVoteReplyProcessor;
use OCA\Decidiq\Service\MailVoteSigner;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VoteCast;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\Decidiq\Service\VotingRoundRequest;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the VotingService facades, one level above them.
 *
 * @covers \OCA\Decidiq\Service\VotingService
 * @covers \OCA\Decidiq\Service\VotingOpenRequestHandler
 * @covers \OCA\Decidiq\Controller\VotingController
 * @covers \OCA\Decidiq\Service\MailVoteReplyProcessor
 * @uses   \OCA\Decidiq\Service\VotingOpenRequestParser
 * @uses   \OCA\Decidiq\Service\VotingRoundRequest
 * @uses   \OCA\Decidiq\Service\VotingRoundRules
 * @uses   \OCA\Decidiq\Service\VoteCast
 * @uses   \OCA\Decidiq\Service\VotingErrorResponder
 * @uses   \OCA\Decidiq\Service\ProxyDelegationService
 * @uses   \OCA\Decidiq\Service\VotingRoundGuard
 */
class VotingFacadeBoundaryGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/voting-facade-boundary-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every open and cast reaches the opener and caster as recorded.
	 *
	 * @return void
	 */
	public function testEveryCallReachesTheOpenerAndCasterAsRecorded(): void {
		$actual = [];
		foreach ($this->openCases() as $name => $params) {
			$this->log = [];
			try {
				$response = (new VotingOpenRequestHandler(votingService: $this->service()))->handle(params: $params);
				$actual['open ' . $name] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->log];
			} catch (RuntimeException $e) {
				$actual['open ' . $name] = ['throws' => $e->getMessage(), 'calls' => $this->log];
			}
		}

		foreach ($this->castCases() as $name => $case) {
			$this->log = [];
			$response = $this->controller(case: $case)->cast($case['roundId']);
			$actual['cast ' . $name] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->log];
		}

		foreach (['for', 'against', 'abstain'] as $keyword) {
			foreach ([null, 'uid-7'] as $notifyUid) {
				$this->log = [];
				$processor = new MailVoteReplyProcessor(
					votingService: $this->service(),
					signer: $this->createMock(MailVoteSigner::class),
					container: $this->createMock(ContainerInterface::class),
					logger: $this->createMock(LoggerInterface::class),
				);
				$cast = new \ReflectionMethod($processor, 'castVote');
				$entry = ['participantId' => 'p-mail', 'replyBody' => $keyword];
				$result = $cast->invoke($processor, $entry, $keyword, 'p-mail', 'round-mail', $notifyUid);
				$actual['mail ' . $keyword . ' ' . ($notifyUid ?? 'no-notify')] = ['returns' => $result, 'calls' => $this->log];
			}
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_VOTING_FACADE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryCallReachesTheOpenerAndCasterAsRecorded()

	/**
	 * Open-round request bodies.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function openCases(): array {
		$base = ['motionId' => 'mo-1', 'meetingId' => 'me-1'];

		return [
			'minimal' => $base,
			'missing ids' => ['motionId' => 'mo-1'],
			'everything' => $base + [
				'votingMethod' => 'show-of-hands',
				'isSecret' => true,
				'closedAt' => '2026-12-01T10:00:00+00:00',
				'presetParticipantIds' => ['p-1', 'p-2'],
				'voteThreshold' => 'qualified-majority-two-thirds',
				'abstentionHandling' => 'count',
				'tieBreakRule' => 'revote',
				'revoteOfRound' => 'r-0',
				'subjectType' => 'amendment',
				'governanceBody' => 'gb-1',
			],
			'secret as text' => $base + ['isSecret' => '1', 'closedAt' => ''],
			'presets not a list' => $base + ['presetParticipantIds' => 'p-1'],
			'revote not a string' => $base + ['revoteOfRound' => ['r-0'], 'governanceBody' => 7],
			'unknown rule' => $base + ['tieBreakRule' => 'coin'],
			'unknown subject' => $base + ['subjectType' => 'resolution'],
			'opener refuses' => ['motionId' => 'mo-refused', 'meetingId' => 'me-1'],
			'ranked with options' => $base + [
				'votingMethod' => 'ranked-choice',
				'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
			],
			'options on a show of hands' => $base + ['votingMethod' => 'show-of-hands', 'options' => [['key' => 'a', 'label' => 'A']]],
		];
	}//end openCases()

	/**
	 * Cast cases: session user, round id and body.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function castCases(): array {
		return [
			'own vote' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'for']],
			'proxy vote' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'against', 'isProxy' => true, 'delegatorId' => 'p-bert']],
			'proxy without delegator' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'abstain', 'isProxy' => 'yes', 'delegatorId' => '']],
			'delegator without proxy' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'for', 'isProxy' => false, 'delegatorId' => 'p-bert']],
			'forged participant and caller' => [
				'uid' => 'anna',
				'roundId' => 'r-2',
				'body' => ['value' => 'for', 'participantId' => 'p-bert', 'callerUid' => 'bert', 'userId' => 'bert'],
			],
			'ranked ballot' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['ranking' => ['o-2', 'o-1']]],
			'ranked ballot with value' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'x', 'ranking' => ['o-1']]],
			'ranked proxy ballot' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'x', 'ranking' => ['o-1'], 'isProxy' => true, 'delegatorId' => 'p-bert']],
			'ranking not a list' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'for', 'ranking' => 'o-1']],
			'other member' => ['uid' => 'bert', 'roundId' => 'r-1', 'body' => ['value' => 'against']],
			'no participant profile' => ['uid' => 'zoe', 'roundId' => 'r-1', 'body' => ['value' => 'for']],
			'signed out' => ['uid' => null, 'roundId' => 'r-1', 'body' => ['value' => 'for']],
			'no value' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => []],
			'unknown value' => ['uid' => 'anna', 'roundId' => 'r-1', 'body' => ['value' => 'maybe']],
			'caster refuses' => ['uid' => 'anna', 'roundId' => 'r-refused', 'body' => ['value' => 'for']],
		];
	}//end castCases()

	/**
	 * The real VotingService over recording opener and caster doubles.
	 *
	 * @return VotingService
	 */
	private function service(): VotingService {
		$opener = $this->createMock(VotingRoundOpener::class);
		$opener->method('openVotingRound')->willReturnCallback(
			function (VotingRoundRequest $request): array {
				$rules = $request->rules();
				$this->log[] = ['open' => [
					'motionId' => $request->motionId,
					'meetingId' => $request->meetingId,
					'votingMethod' => $request->votingMethod,
					'isSecret' => $request->isSecret,
					'closedAt' => $request->closedAt(),
					'presets' => $request->presetParticipantIds(),
					'revoteOf' => $request->revoteOfRoundId(),
					'fresh' => $request->isFreshRound(),
					'rules' => [$rules->voteThreshold, $rules->abstentionHandling, $rules->tieBreakRule, $rules->subjectType, $rules->governanceBodyId, $rules->options],
				]];
				if ($request->motionId === 'mo-refused') {
					throw new RuntimeException('Quorum niet bereikt');
				}

				return ['id' => 'round-new'];
			}
		);

		$caster = $this->createMock(VoteCastingService::class);
		$caster->method('castVote')->willReturnCallback(
			function (VoteCast $cast, ?string $callerUid = null): array {
				$this->log[] = ['cast' => [
					'round' => $cast->votingRoundId,
					'participant' => $cast->participantId,
					'value' => $cast->value(),
					'ranking' => $cast->ranking(),
					'proxy' => $cast->isProxy(),
					'delegator' => $cast->delegatorId(),
					'callerUid' => $callerUid,
				]];
				if ($cast->votingRoundId === 'r-refused') {
					throw new RuntimeException('Voting round is not open');
				}

				return ['id' => 'vote-1'];
			}
		);

		$participants = $this->createMock(ParticipantUuidLookup::class);
		$participants->method('forNextcloudUser')->willReturnCallback(
			static fn (string $nextcloudUid): ?string => ['anna' => 'p-anna', 'bert' => 'p-bert'][$nextcloudUid] ?? null
		);

		return new VotingService(
			opener: $opener,
			caster: $caster,
			closer: $this->createMock(VotingRoundCloser::class),
			results: $this->createMock(VotingRoundResults::class),
			projection: $this->createMock(VotingRoundProjection::class),
			participants: $participants,
		);
	}//end service()

	/**
	 * The voting controller for one cast case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return VotingController
	 */
	private function controller(array $case): VotingController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($case['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['body']);
		$service = $this->service();
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new VotingController(
			request: $request,
			votingService: $service,
			oriService: $this->createMock(OriPublicationService::class),
			userSession: $session,
			guard: $this->createMock(VotingRoundGuard::class),
			openHandler: new VotingOpenRequestHandler(votingService: $service),
			proxyService: new ProxyDelegationService(container: $container, logger: $logger, objectService: $this->createMock(ObjectServiceInterface::class), appConfig: $this->createMock(IAppConfig::class)),
			errors: new VotingErrorResponder(logger: $logger),
		);
	}//end controller()
}//end class
