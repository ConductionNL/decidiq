<?php

/**
 * Unit tests for VotingRoundCloser.
 *
 * Covers the chair casting vote guard, the subject lifecycle transition and its
 * #318 error split, the adoption side effects (dossier folder, amendment
 * incorporation), ORI publication failures and GDPR anonymisation.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of the close-a-round path.
 *
 * @covers \OCA\Decidiq\Service\VotingRoundCloser
 * @uses   \OCA\Decidiq\Service\AmendmentOrderService
 * @uses   \OCA\Decidiq\Service\ObjectRelationFilter
 */
class VotingRoundCloserTest extends TestCase {

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * ORI publication double.
	 *
	 * @var OriPublicationService&MockObject
	 */
	private OriPublicationService&MockObject $oriService;

	/**
	 * Motion service double.
	 *
	 * @var MotionService&MockObject
	 */
	private MotionService&MockObject $motionService;

	/**
	 * File service double.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Every saveObject() payload, in order.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->oriService    = $this->createMock(OriPublicationService::class);
		$this->motionService = $this->createMock(MotionService::class);
		$this->fileService   = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->saved         = [];

	}//end setUp()

	/**
	 * Wrap a plain array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The object payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Build the closer over a fixture store keyed by UUID.
	 *
	 * @param array<string, array<string, mixed>> $objects Fixture rows
	 *
	 * @return VotingRoundCloser
	 */
	private function closer(array $objects): VotingRoundCloser {
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($objects[(string)$id]) ? $this->entity($objects[(string)$id]) : null
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null): ObjectEntity {
				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];

				return $this->entity($object);
			}
		);

		return new VotingRoundCloser(
			logger: $this->logger,
			oriService: $this->oriService,
			motionService: $this->motionService,
			amendmentOrder: new AmendmentOrderService(
				motionService: $this->motionService,
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			relationFilter: new ObjectRelationFilter(),
			fileService: $this->fileService,
			objectService: $this->objectService,
		);
	}//end closer()

	/**
	 * A closed round deciding on the given subject.
	 *
	 * @param string $schema The subject schema ('motion'|'amendment')
	 * @param string $id The subject UUID
	 *
	 * @return array<string, mixed>
	 */
	private function round(string $schema, string $id): array {
		return [
			'id' => 'round-1',
			'closedAt' => '2026-09-01T10:00:00+00:00',
			'relations' => [['schema' => 'meeting', 'id' => 'm-1'], ['schema' => $schema, 'id' => $id]],
		];
	}//end round()

	/**
	 * A casting vote other than for/against is refused before any lookup.
	 *
	 * @return void
	 */
	public function testCastingVoteRejectsAnInvalidValue(): void {
		$closer = $this->closer([]);
		$this->objectService->expects(self::never())->method('find');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Casting vote refused: value must be 'for' or 'against'");

		$closer->applyChairCastingVote(votingRoundId: 'round-1', chairCasting: 'abstain');
	}//end testCastingVoteRejectsAnInvalidValue()

	/**
	 * A casting vote on an unknown round is refused.
	 *
	 * @return void
	 */
	public function testCastingVoteRejectsAnUnknownRound(): void {
		$closer = $this->closer([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('VotingRound round-x not found');

		$closer->applyChairCastingVote(votingRoundId: 'round-x', chairCasting: 'for');
	}//end testCastingVoteRejectsAnUnknownRound()

	/**
	 * A casting vote is refused unless the tie-break rule is chair-decides.
	 *
	 * @return void
	 */
	public function testCastingVoteRejectsARoundWithoutChairDecides(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1']]);

		try {
			$closer->applyChairCastingVote(votingRoundId: 'round-1', chairCasting: 'for');
			self::fail('a round without chair-decides must refuse the casting vote');
		} catch (RuntimeException $e) {
			self::assertSame("Casting vote refused: this round's tie-break rule is not 'chair-decides'", $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testCastingVoteRejectsARoundWithoutChairDecides()

	/**
	 * A permitted casting vote is persisted on the round.
	 *
	 * @return void
	 */
	public function testCastingVoteIsPersistedOnTheRound(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1', 'tieBreakRule' => 'chair-decides']]);

		$closer->applyChairCastingVote(votingRoundId: 'round-1', chairCasting: 'against');

		self::assertCount(1, $this->saved);
		self::assertSame('voting-round', $this->saved[0]['schema']);
		self::assertSame('against', $this->saved[0]['object']['chairCastingVote']);
	}//end testCastingVoteIsPersistedOnTheRound()

	/**
	 * An open round gets closedAt stamped; an unknown round closes to an empty array.
	 *
	 * @return void
	 */
	public function testCloseStampsClosedAtAndReturnsEmptyForUnknownRound(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1']]);

		$round = $closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'invalid']);
		self::assertNotEmpty($round['closedAt']);
		self::assertSame($round['closedAt'], $this->saved[0]['object']['closedAt']);

		self::assertSame([], $closer->close(votingRoundId: 'nope', anonymise: false, tally: []));
	}//end testCloseStampsClosedAtAndReturnsEmptyForUnknownRound()

	/**
	 * An adopted motion is decided and gets a dossier folder named after its title.
	 *
	 * @return void
	 */
	public function testAdoptedMotionIsDecidedAndGetsADossierFolder(): void {
		$closer = $this->closer(
			[
				'round-1' => $this->round('motion', 'mot-1'),
				'mot-1' => ['id' => 'mot-1', 'title' => 'Fix the Bridge!'],
			]
		);

		$this->motionService->expects(self::once())->method('transitionLifecycle')->with(
			objectId: 'mot-1',
			objectType: 'motion',
			newState: 'decided',
			actorId: 'system',
			outcome: 'adopted',
		);
		$this->fileService->expects(self::once())->method('createFolder')->with('motions/fix-the-bridge--mot-1');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testAdoptedMotionIsDecidedAndGetsADossierFolder()

	/**
	 * A rejected motion is decided with outcome rejected and gets no folder.
	 *
	 * @return void
	 */
	public function testRejectedMotionGetsNoDossierFolder(): void {
		$closer = $this->closer(['round-1' => $this->round('motion', 'mot-1')]);

		$this->motionService->expects(self::once())->method('transitionLifecycle')
			->with('mot-1', 'motion', 'decided', 'system', 'rejected');
		$this->fileService->expects(self::never())->method('createFolder');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'rejected']);
	}//end testRejectedMotionGetsNoDossierFolder()

	/**
	 * A tied/invalid result does not touch the subject lifecycle.
	 *
	 * @return void
	 */
	public function testTiedResultLeavesTheSubjectAlone(): void {
		$closer = $this->closer(['round-1' => $this->round('motion', 'mot-1')]);

		$this->motionService->expects(self::never())->method('transitionLifecycle');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'tied']);
	}//end testTiedResultLeavesTheSubjectAlone()

	/**
	 * A subject relation without an id stops the transition.
	 *
	 * @return void
	 */
	public function testSubjectRelationWithoutIdIsIgnored(): void {
		$closer = $this->closer(
			['round-1' => ['id' => 'round-1', 'closedAt' => 'x', 'relations' => [['schema' => 'motion']]]]
		);

		$this->motionService->expects(self::never())->method('transitionLifecycle');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testSubjectRelationWithoutIdIsIgnored()

	/**
	 * A refused state transition surfaces as a RuntimeException (#318).
	 *
	 * @return void
	 */
	public function testRefusedTransitionIsRethrown(): void {
		$closer = $this->closer(['round-1' => $this->round('motion', 'mot-1')]);
		$this->motionService->method('transitionLifecycle')->willThrowException(new InvalidArgumentException('bad edge'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Stemronde gesloten maar motie kon niet worden bijgewerkt: bad edge');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testRefusedTransitionIsRethrown()

	/**
	 * A transient transition failure is logged at error level and the round still closes.
	 *
	 * @return void
	 */
	public function testTransientTransitionFailureIsLoggedAndSwallowed(): void {
		$closer = $this->closer(['round-1' => $this->round('motion', 'mot-1')]);
		$this->motionService->method('transitionLifecycle')->willThrowException(new RuntimeException('db down'));

		$this->logger->expects(self::once())->method('error')->with(
			'Decidiq: lifecycle transition after close failed — round is closed but motion state may be stale',
			['votingRoundId' => 'round-1', 'motionId' => 'mot-1', 'error' => 'db down']
		);

		$round = $closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
		self::assertSame('round-1', $round['id']);
	}//end testTransientTransitionFailureIsLoggedAndSwallowed()

	/**
	 * A failing dossier folder creation is logged as a warning, not thrown.
	 *
	 * @return void
	 */
	public function testDossierFolderFailureIsLogged(): void {
		$closer = $this->closer(['round-1' => $this->round('motion', 'mot-1')]);
		$this->fileService->method('createFolder')->willThrowException(new RuntimeException('no quota'));

		$this->fileService->expects(self::once())->method('createFolder')->with('motions/mot-1-mot-1');
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: dossier folder creation failed',
			['motionId' => 'mot-1', 'error' => 'no quota']
		);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testDossierFolderFailureIsLogged()

	/**
	 * An adopted amendment is worked into its parent motion.
	 *
	 * @return void
	 */
	public function testAdoptedAmendmentIsIncorporatedIntoItsParentMotion(): void {
		$closer = $this->closer(
			[
				'round-1' => $this->round('amendment', 'am-1'),
				'am-1' => ['id' => 'am-1', 'decisionType' => 'amendment', 'amends' => 'mot-9'],
			]
		);

		$this->motionService->expects(self::once())->method('applyAmendment')->with(motionId: 'mot-9', amendmentId: 'am-1');
		$this->fileService->expects(self::never())->method('createFolder');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testAdoptedAmendmentIsIncorporatedIntoItsParentMotion()

	/**
	 * An amendment that is not a decision of type amendment, or has no parent, is not applied.
	 *
	 * @param array<string, mixed>|null $amendment The stored amendment, or null when missing
	 *
	 * @return void
	 *
	 * @dataProvider unapplicableAmendmentProvider
	 */
	public function testUnapplicableAmendmentIsNotApplied(?array $amendment): void {
		$objects = ['round-1' => $this->round('amendment', 'am-1')];
		if ($amendment !== null) {
			$objects['am-1'] = $amendment;
		}

		$closer = $this->closer($objects);
		$this->motionService->expects(self::once())->method('transitionLifecycle');
		$this->motionService->expects(self::never())->method('applyAmendment');

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testUnapplicableAmendmentIsNotApplied()

	/**
	 * Amendments that must not be merged.
	 *
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function unapplicableAmendmentProvider(): array {
		return [
			'missing amendment' => [null],
			'not an amendment decision' => [['id' => 'am-1', 'decisionType' => 'motion', 'amends' => 'mot-9']],
			'amendment without parent' => [['id' => 'am-1', 'decisionType' => 'amendment']],
		];
	}//end unapplicableAmendmentProvider()

	/**
	 * A failing amendment merge is logged at error level and swallowed.
	 *
	 * @return void
	 */
	public function testAmendmentMergeFailureIsLogged(): void {
		$closer = $this->closer(
			[
				'round-1' => $this->round('amendment', 'am-1'),
				'am-1' => ['id' => 'am-1', 'decisionType' => 'amendment', 'amends' => 'mot-9'],
			]
		);
		$this->motionService->method('applyAmendment')->willThrowException(new RuntimeException('merge conflict'));

		$this->logger->expects(self::once())->method('error')->with(
			'Decidiq: failed to incorporate adopted amendment into the parent motion text',
			['amendmentId' => 'am-1', 'error' => 'merge conflict']
		);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);
	}//end testAmendmentMergeFailureIsLogged()

	/**
	 * An ORI failure is logged and attached to the returned round.
	 *
	 * @return void
	 */
	public function testOriFailureIsAttachedToTheRound(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1', 'closedAt' => 'x']]);
		$this->oriService->expects(self::once())->method('publish')->with('round-1')
			->willThrowException(new RuntimeException('ORI 503'));

		$this->logger->expects(self::once())->method('error')->with(
			'Decidiq: ORI publication failed after round close',
			['votingRoundId' => 'round-1', 'error' => 'ORI 503']
		);

		$round = $closer->close(votingRoundId: 'round-1', anonymise: false, tally: []);
		self::assertSame('ORI 503', $round['oriPublicationError']);
	}//end testOriFailureIsAttachedToTheRound()

	/**
	 * An ORI failure on an unknown round still returns an empty array.
	 *
	 * @return void
	 */
	public function testOriFailureOnUnknownRoundReturnsEmpty(): void {
		$closer = $this->closer([]);
		$this->oriService->method('publish')->willThrowException(new RuntimeException('ORI 503'));

		self::assertSame([], $closer->close(votingRoundId: 'nope', anonymise: false, tally: []));
	}//end testOriFailureOnUnknownRoundReturnsEmpty()

	/**
	 * A stage given as an expanded object is resolved by its id.
	 *
	 * @return void
	 */
	public function testExpandedStageObjectIsResolved(): void {
		$closer = $this->closer(
			[
				'round-1' => ['id' => 'round-1', 'closedAt' => 'x', 'decisionStage' => ['uuid' => 'stage-1']],
				'stage-1' => ['id' => 'stage-1', 'status' => 'active'],
			]
		);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);

		self::assertCount(1, $this->saved);
		self::assertSame('decision-stage', $this->saved[0]['schema']);
		self::assertSame('adopted', $this->saved[0]['object']['outcome']);
	}//end testExpandedStageObjectIsResolved()

	/**
	 * A stage that cannot be found writes nothing.
	 *
	 * @return void
	 */
	public function testMissingStageWritesNothing(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1', 'closedAt' => 'x', 'decisionStage' => 'stage-gone']]);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);

		self::assertSame([], $this->saved);
	}//end testMissingStageWritesNothing()

	/**
	 * A stage that is not active is logged and left unwritten.
	 *
	 * @return void
	 */
	public function testInactiveStageIsLoggedAndLeftAlone(): void {
		$closer = $this->closer(
			[
				'round-1' => ['id' => 'round-1', 'closedAt' => 'x', 'decisionStage' => 'stage-1'],
				'stage-1' => ['id' => 'stage-1', 'status' => 'decided'],
			]
		);

		$this->logger->expects(self::once())->method('info')->with(
			'Decidiq: round closed on a decision stage that is not active, outcome not recorded',
			['votingRoundId' => 'round-1', 'stageId' => 'stage-1', 'status' => 'decided']
		);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);

		self::assertSame([], $this->saved);
	}//end testInactiveStageIsLoggedAndLeftAlone()

	/**
	 * A failing stage write is logged at error level and swallowed.
	 *
	 * @return void
	 */
	public function testStageWriteFailureIsLogged(): void {
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ObjectEntity => $this->entity(
				$id === 'round-1'
					? ['id' => 'round-1', 'closedAt' => 'x', 'decisionStage' => 'stage-1']
					: ['id' => 'stage-1', 'status' => 'active']
			)
		);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$closer = $this->closer([]);

		$this->logger->expects(self::once())->method('error')->with(
			'Decidiq: round closed but the decision stage outcome could not be recorded',
			['votingRoundId' => 'round-1', 'stageId' => 'stage-1', 'error' => 'locked']
		);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'rejected']);
	}//end testStageWriteFailureIsLogged()

	/**
	 * Anonymisation nulls the value and ranking of every vote that references the round.
	 *
	 * @return void
	 */
	public function testAnonymiseNullsMatchingVotes(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1', 'closedAt' => 'x']]);

		$ranked = ['id' => 'v-1', 'value' => 'for', 'ranking' => ['a', 'b'], 'relations' => [['schema' => 'voting-round', 'id' => 'round-1']]];
		$plain  = ['id' => 'v-2', 'value' => 'against', 'relations' => [['schema' => 'voting-round', 'id' => 'round-1']]];
		$other  = ['id' => 'v-3', 'value' => 'for', 'relations' => [['schema' => 'voting-round', 'id' => 'round-2']]];

		$this->objectService->expects(self::once())->method('setRegister')->with('decidiq')->willReturnSelf();
		$this->objectService->expects(self::once())->method('setSchema')->with('vote')->willReturnSelf();
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => (new ObjectRelationFilter())->filterFor(targetId: 'round-1')])
			->willReturn([$this->entity($ranked), $this->entity($plain), $this->entity($other)]);
		$this->logger->expects(self::once())->method('info')
			->with('Decidiq: votes anonymised', ['votingRoundId' => 'round-1']);

		$closer->close(votingRoundId: 'round-1', anonymise: true, tally: []);

		$votes = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'vote'));
		self::assertCount(2, $votes);
		self::assertSame(['v-1', null, null], [$votes[0]['object']['id'], $votes[0]['object']['value'], $votes[0]['object']['ranking']]);
		self::assertSame('v-2', $votes[1]['object']['id']);
		self::assertNull($votes[1]['object']['value']);
		self::assertArrayNotHasKey('ranking', $votes[1]['object']);
	}//end testAnonymiseNullsMatchingVotes()

	/**
	 * A failing anonymisation is logged as a warning, not thrown.
	 *
	 * @return void
	 */
	public function testAnonymiseFailureIsLogged(): void {
		$closer = $this->closer(['round-1' => ['id' => 'round-1', 'closedAt' => 'x']]);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('search down'));

		$this->logger->expects(self::once())->method('warning')
			->with('Decidiq: vote anonymisation failed', ['error' => 'search down']);

		$round = $closer->close(votingRoundId: 'round-1', anonymise: true, tally: []);
		self::assertSame('round-1', $round['id']);
	}//end testAnonymiseFailureIsLogged()
}//end class
