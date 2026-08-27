<?php

/**
 * VotingService Phase-0 Regression Tests.
 *
 * Locks the Phase-0 fixes:
 *  - relation filters use `_relations.<schema>` (presence-only in OR) and are
 *    id-scoped client-side via filterByRelation();
 *  - castVote / saveShowOfHandsTally / openVotingRound return ARRAYS via
 *    normaliseSaved() (ObjectEntity::jsonSerialize()), never a raw ObjectEntity;
 *  - tally math: for > against => adopted; quorum is enforced before opening.
 *
 * Mocks return real ObjectEntity instances (not \stdClass) so they satisfy the
 * live ObjectService::find()/saveObject() return types when the OpenRegister
 * app is bootstrapped — see codeberg #90.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Phase-0 regression tests for VotingService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
 */
class VotingServicePhase0RegressionTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var OriPublicationService&MockObject
	 */
	private OriPublicationService $oriService;

	/**
	 * @var MotionService&MockObject
	 */
	private MotionService $motionService;

	/**
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver $participantResolver;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The service under test.
	 *
	 * @var VotingService
	 */
	private VotingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->oriService = $this->createMock(OriPublicationService::class);
		$this->motionService = $this->createMock(MotionService::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->container->method('get')->willReturn($this->objectService);

		$templateService = $this->createMock(\OCA\Decidiq\Service\ProcessTemplateService::class);
		$templateService->method('resolveVotingRuleForBody')->willReturn(null);

		// VotingService is a thin facade: every operation is delegated to a
		// single-purpose collaborator, so the graph is built explicitly here
		// where production relies on Nextcloud's constructor auto-wiring.
		$amendmentOrder = new AmendmentOrderService(
			motionService: $this->motionService,
			objectService: $this->objectService,
		);
		$relationFilter = new ObjectRelationFilter();

		$this->service = new VotingService(
			opener: new VotingRoundOpener(
				motionService: $this->motionService,
				participantResolver: $this->participantResolver,
				preflight: new VotingRoundPreflight(
					logger: $this->logger,
					motionService: $this->motionService,
					participantResolver: $this->participantResolver,
					templateService: $templateService,
					objectService: $this->objectService,
				),
				notifier: new VotingOpenedNotifier(
					logger: $this->logger,
					participantResolver: $this->participantResolver,
					container: $this->container,
				),
				objectService: $this->objectService,
			),
			caster: new VoteCastingService(
				logger: $this->logger,
				participantResolver: $this->participantResolver,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $this->objectService,
				container: $this->container,
			),
			closer: new VotingRoundCloser(
				logger: $this->logger,
				oriService: $this->oriService,
				motionService: $this->motionService,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $this->objectService,
				fileService: $this->createMock(FileService::class),
			),
			results: new VotingRoundResults(
				motionService: $this->motionService,
				participantResolver: $this->participantResolver,
				objectService: $this->objectService,
			),
			projection: new VotingRoundProjection(
				objectService: $this->objectService,
			),
			participants: new ParticipantUuidLookup(
				objectService: $this->objectService,
			),
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock whose jsonSerialize() returns $data.
	 *
	 * Returning a real ObjectEntity (not \stdClass) keeps the value assignable
	 * to ObjectService::find()/saveObject()'s live return types.
	 *
	 * @param array<string, mixed> $data The serialised object payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * openVotingRound() throws when quorum is not met — quorum is enforced at
	 * the service level before any round is persisted.
	 *
	 * @return void
	 */
	public function testOpenVotingRoundEnforcesQuorum(): void {
		// checkQuorum() loads the meeting; quorumRequired 5 with 1 participant fails.
		$this->objectService->method('find')->willReturn(
			$this->entity(['quorumRequired' => 5])
		);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn(
			[['id' => 'p1', 'leftAt' => null]]
		);

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Quorum niet bereikt');

		$this->service->openVotingRound(
			motionId: 'm1',
			meetingId: 'mt1',
			votingMethod: 'roll-call',
			isSecret: false,
			closedAt: null,
		);

	}//end testOpenVotingRoundEnforcesQuorum()

	/**
	 * openVotingRound() returns the persisted round as an ARRAY (normaliseSaved
	 * serialises the ObjectEntity returned by saveObject()).
	 *
	 * @return void
	 */
	public function testOpenVotingRoundReturnsArrayViaNormaliseSaved(): void {
		// quorumRequired 0 => quorum auto-met.
		$this->objectService->method('find')->willReturn(
			$this->entity(['quorumRequired' => 0])
		);

		$savedRound = ['id' => 'vr1', 'votingMethod' => 'roll-call', 'votesFor' => 0];
		$this->objectService->method('saveObject')->willReturn($this->entity($savedRound));

		$result = $this->service->openVotingRound(
			motionId: 'm1',
			meetingId: 'mt1',
			votingMethod: 'roll-call',
			isSecret: false,
			closedAt: null,
		);

		$this->assertIsArray($result);
		$this->assertSame('vr1', $result['id']);

	}//end testOpenVotingRoundReturnsArrayViaNormaliseSaved()

	/**
	 * tallyResults() counts weighted votes and adopts when for > against.
	 * Also proves the round-scoped result set is queried via _relations and
	 * id-scoped by filterByRelation (each vote references the round).
	 *
	 * @return void
	 */
	public function testTallyResultsAdoptedWhenForExceedsAgainst(): void {
		$roundId = 'vr-adopt';

		$voteEntities = [
			$this->entity(['value' => 'for', 'weight' => 1, 'relations' => [['schema' => 'voting-round', 'id' => $roundId]]]),
			$this->entity(['value' => 'for', 'weight' => 1, 'relations' => [['schema' => 'voting-round', 'id' => $roundId]]]),
			$this->entity(['value' => 'against', 'weight' => 1, 'relations' => [['schema' => 'voting-round', 'id' => $roundId]]]),
		];

		$this->objectService->method('findAll')->willReturn($voteEntities);
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => $roundId, 'relations' => [['schema' => 'voting-round', 'id' => $roundId]]])
		);
		$this->objectService->method('saveObject')->willReturn($this->entity(['id' => $roundId]));

		$tally = $this->service->tallyResults(votingRoundId: $roundId);

		$this->assertSame(2, $tally['votesFor']);
		$this->assertSame(1, $tally['votesAgainst']);
		$this->assertSame('adopted', $tally['result']);

	}//end testTallyResultsAdoptedWhenForExceedsAgainst()

	/**
	 * tallyResults() id-scopes the result set: a vote that carries a
	 * voting-round relation to a DIFFERENT round is excluded by filterByRelation,
	 * so a leaked foreign vote does not skew the tally.
	 *
	 * @return void
	 */
	public function testTallyResultsIdScopesRelationFilter(): void {
		$roundId = 'vr-scope';

		$voteEntities = [
			// Belongs to this round.
			$this->entity(['value' => 'for', 'weight' => 1, 'relations' => [['schema' => 'voting-round', 'id' => $roundId]]]),
			// Belongs to ANOTHER round — must be filtered out.
			$this->entity(['value' => 'against', 'weight' => 1, 'relations' => [['schema' => 'voting-round', 'id' => 'other-round']]]),
		];

		$this->objectService->method('findAll')->willReturn($voteEntities);
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => $roundId, 'relations' => [['schema' => 'voting-round', 'id' => $roundId]]])
		);
		$this->objectService->method('saveObject')->willReturn($this->entity(['id' => $roundId]));

		$tally = $this->service->tallyResults(votingRoundId: $roundId);

		// The foreign 'against' vote is excluded; only the in-scope 'for' counts.
		$this->assertSame(1, $tally['votesFor']);
		$this->assertSame(0, $tally['votesAgainst']);
		$this->assertSame('adopted', $tally['result']);

	}//end testTallyResultsIdScopesRelationFilter()

	/**
	 * saveShowOfHandsTally() returns an ARRAY and computes adopted/rejected from
	 * the chair-entered counts (for > against => adopted).
	 *
	 * @return void
	 */
	public function testSaveShowOfHandsTallyReturnsArrayAndAdopts(): void {
		// Round with no motion relation => participant-count validation is skipped.
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => 'vr-soh', 'votingMethod' => 'show-of-hands', 'relations' => []])
		);
		$this->objectService->method('saveObject')->willReturn(
			$this->entity(['id' => 'vr-soh', 'result' => 'adopted', 'votesFor' => 7, 'votesAgainst' => 2])
		);

		$result = $this->service->saveShowOfHandsTally(
			votingRoundId: 'vr-soh',
			votesFor: 7,
			votesAgainst: 2,
			votesAbstain: 1,
		);

		$this->assertIsArray($result);
		$this->assertSame('adopted', $result['result']);

	}//end testSaveShowOfHandsTallyReturnsArrayAndAdopts()

	/**
	 * saveShowOfHandsTally() rejects a non show-of-hands round.
	 *
	 * @return void
	 */
	public function testSaveShowOfHandsTallyRejectsWrongMethod(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => 'vr-x', 'votingMethod' => 'roll-call', 'relations' => []])
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('show-of-hands');

		$this->service->saveShowOfHandsTally(
			votingRoundId: 'vr-x',
			votesFor: 1,
			votesAgainst: 0,
			votesAbstain: 0,
		);

	}//end testSaveShowOfHandsTallyRejectsWrongMethod()

}//end class
