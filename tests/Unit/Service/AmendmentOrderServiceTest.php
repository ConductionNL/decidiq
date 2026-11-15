<?php

/**
 * Unit tests for AmendmentOrderService: the amendment-before-motion ordering
 * rules and the relation walks they share.
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
 * @spec openspec/specs/motion-amendment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\FindCallShape;
use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ordering enforcement, parent-motion and meeting resolution.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
#[CoversClass(AmendmentOrderService::class)]
class AmendmentOrderServiceTest extends TestCase {

	/**
	 * The motion service double.
	 *
	 * @var MotionService&MockObject
	 */
	private MotionService&MockObject $motionService;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Decisions served by the object service double, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $decisions = [];

	/**
	 * The service under test.
	 *
	 * @var AmendmentOrderService
	 */
	private AmendmentOrderService $service;

	/**
	 * Build the service over a motion service mock and an in-memory decision store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->motionService = $this->createMock(MotionService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturnCallback(FindCallShape::legacy(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntity {
				if ($register !== 'decidiq' || $schema !== 'decision' || isset($this->decisions[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($this->decisions[$id]);
				return $entity;
			}
		));
		$this->service = new AmendmentOrderService(motionService: $this->motionService, objectService: $this->objectService);
	}//end setUp()

	/**
	 * A motion round passes when every amendment is decided or withdrawn.
	 *
	 * @return void
	 */
	public function testMotionRoundPassesWhenAmendmentsDecided(): void {
		$this->motionService->expects($this->once())
			->method('getAmendmentsForMotion')
			->with('motion-1')
			->willReturn([['id' => 'a1', 'lifecycle' => 'decided'], ['id' => 'a2', 'lifecycle' => 'withdrawn']]);

		$this->service->assertOrdering(subjectId: 'motion-1', subjectType: 'motion');
	}//end testMotionRoundPassesWhenAmendmentsDecided()

	/**
	 * A motion round is refused while amendments are undecided, naming each.
	 *
	 * @return void
	 */
	public function testMotionRoundRefusedWhileAmendmentsUndecided(): void {
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				['id' => 'a1', 'title' => 'Amend art. 1', 'lifecycle' => 'voting'],
				['id' => 'a2', 'lifecycle' => 'draft'],
				['lifecycle' => 'proposed'],
				['id' => 'a4', 'title' => 'Done', 'lifecycle' => 'enacted'],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'Cannot open a voting round on the motion: 3 amendment(s) must be decided first '
			.'(amendments are voted before the main motion): Amend art. 1, a2, amendment'
		);

		$this->service->assertOrdering(subjectId: 'motion-1', subjectType: 'motion');
	}//end testMotionRoundRefusedWhileAmendmentsUndecided()

	/**
	 * An amendment round on an unknown object is refused.
	 *
	 * @return void
	 */
	public function testAmendmentRoundOnUnknownObjectIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Amendment nope not found');

		$this->service->assertOrdering(subjectId: 'nope', subjectType: 'amendment');
	}//end testAmendmentRoundOnUnknownObjectIsRefused()

	/**
	 * A decision that is not an amendment is refused as not found.
	 *
	 * @return void
	 */
	public function testAmendmentRoundOnNonAmendmentDecisionIsRefused(): void {
		$this->decisions['m-1'] = ['id' => 'm-1', 'decisionType' => 'motion'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Amendment m-1 not found');

		$this->service->assertOrdering(subjectId: 'm-1', subjectType: 'amendment');
	}//end testAmendmentRoundOnNonAmendmentDecisionIsRefused()

	/**
	 * A standalone amendment without a parent motion may be voted directly.
	 *
	 * @return void
	 */
	public function testStandaloneAmendmentIsNotOrdered(): void {
		$this->decisions['a-1'] = ['id' => 'a-1', 'decisionType' => 'amendment', 'lifecycle' => 'proposed'];
		$this->motionService->expects($this->never())->method('getAmendmentsForMotion');

		$this->service->assertOrdering(subjectId: 'a-1', subjectType: 'amendment');
	}//end testStandaloneAmendmentIsNotOrdered()

	/**
	 * The siblings sorted by order, then submission, then id; an amendment
	 * missing from its parent's list is still compared, and passes when next.
	 *
	 * @return void
	 */
	public function testAmendmentAbsentFromSiblingsIsNextByOrder(): void {
		$this->decisions['A'] = [
			'decisionType' => 'amendment',
			'amends' => 'motion-1',
			'lifecycle' => 'proposed',
			'submittedAt' => '2026-01-01',
			'title' => 'A',
		];
		$this->motionService->expects($this->once())
			->method('getAmendmentsForMotion')
			->with('motion-1')
			->willReturn(
				[
					['id' => 'S3', 'lifecycle' => 'proposed', 'submittedAt' => '2026-01-02'],
					['id' => 'S1', 'lifecycle' => 'decided', 'votingOrder' => '2'],
					['id' => 'S2', 'lifecycle' => 'proposed', 'submittedAt' => '2026-01-02'],
				]
			);

		$this->service->assertOrdering(subjectId: 'A', subjectType: 'amendment');
	}//end testAmendmentAbsentFromSiblingsIsNextByOrder()

	/**
	 * Equal order and submission fall back to id order: S2 comes before S3.
	 *
	 * @return void
	 */
	public function testIdBreaksTieBetweenUnorderedSiblings(): void {
		$this->decisions['S3'] = [
			'id' => 'S3',
			'decisionType' => 'amendment',
			'amends' => ['id' => 'motion-1'],
			'lifecycle' => 'proposed',
			'submittedAt' => '2026-01-02',
		];
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				['id' => 'S3', 'title' => 'Third', 'lifecycle' => 'proposed', 'submittedAt' => '2026-01-02'],
				['id' => 'S2', 'title' => 'Second', 'lifecycle' => 'proposed', 'submittedAt' => '2026-01-02'],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Amendment voting order violated: 'Second' must be voted first");

		$this->service->assertOrdering(subjectId: 'S3', subjectType: 'amendment');
	}//end testIdBreaksTieBetweenUnorderedSiblings()

	/**
	 * Earlier submission wins over later among unordered siblings; an ordered one beats both.
	 *
	 * @return void
	 */
	public function testOutOfOrderAmendmentIsRefused(): void {
		$this->decisions['late'] = [
			'id' => 'late',
			'decisionType' => 'amendment',
			'amends' => 'motion-1',
			'lifecycle' => 'proposed',
			'submittedAt' => '2026-02-01',
		];
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				['id' => 'late', 'lifecycle' => 'proposed', 'submittedAt' => '2026-02-01'],
				['uuid' => 'early', 'lifecycle' => 'proposed', 'submittedAt' => '2026-01-01'],
				['id' => 'first', 'lifecycle' => 'archived', 'votingOrder' => 1],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Amendment voting order violated: 'early' must be voted first");

		$this->service->assertOrdering(subjectId: 'late', subjectType: 'amendment');
	}//end testOutOfOrderAmendmentIsRefused()

	/**
	 * An amendment that is itself decided cannot be opened again.
	 *
	 * @return void
	 */
	public function testDecidedAmendmentIsRefused(): void {
		$this->decisions['a-1'] = ['id' => 'a-1', 'decisionType' => 'amendment', 'amends' => 'motion-1', 'lifecycle' => 'decided'];
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[['id' => 'a-1', 'lifecycle' => 'decided']]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Amendment a-1 has already been decided');

		$this->service->assertOrdering(subjectId: 'a-1', subjectType: 'amendment');
	}//end testDecidedAmendmentIsRefused()

	/**
	 * Every supported shape of the parent motion reference.
	 *
	 * @return void
	 */
	public function testResolveParentMotionIdShapes(): void {
		$this->assertSame('m-str', $this->service->resolveParentMotionId(amendment: ['amends' => 'm-str']));
		$this->assertSame('m-id', $this->service->resolveParentMotionId(amendment: ['amends' => ['id' => 'm-id']]));
		$this->assertSame('m-uuid', $this->service->resolveParentMotionId(amendment: ['amends' => ['uuid' => 'm-uuid']]));
		$this->assertSame(
			'm-rel',
			$this->service->resolveParentMotionId(
				amendment: [
					'amends' => [],
					'relations' => [
						'junk',
						['schema' => 'meeting', 'id' => 'meet-1'],
						['schema' => 'motion', 'id' => ''],
						['schema' => 'motion', 'uuid' => 'm-rel'],
					],
				]
			)
		);
		$this->assertNull($this->service->resolveParentMotionId(amendment: ['amends' => 42, 'relations' => [['schema' => 'meeting', 'id' => 'x']]]));
		$this->assertNull($this->service->resolveParentMotionId(amendment: []));
	}//end testResolveParentMotionIdShapes()

	/**
	 * A motion round resolves to the motion's flat meeting reference.
	 *
	 * @return void
	 */
	public function testMeetingOfMotionRound(): void {
		$this->decisions['motion-1'] = ['id' => 'motion-1', 'meeting' => ['uuid' => 'meeting-1']];

		$round = ['relations' => [['schema' => 'meeting', 'id' => 'ignored'], ['schema' => 'motion', 'id' => 'motion-1']]];
		$this->assertSame('meeting-1', $this->service->resolveMeetingIdForRound(round: $round));
	}//end testMeetingOfMotionRound()

	/**
	 * An amendment round walks amendment, parent motion, then the motion's meeting relation.
	 *
	 * @return void
	 */
	public function testMeetingOfAmendmentRoundViaRelations(): void {
		$this->decisions['a-1']      = ['id' => 'a-1', 'amends' => 'motion-2'];
		$this->decisions['motion-2'] = ['id' => 'motion-2', 'relations' => [['schema' => 'meeting', 'id' => 'meeting-2']]];

		$this->assertSame('meeting-2', $this->service->resolveMeetingIdForRound(round: ['relations' => [['schema' => 'amendment', 'id' => 'a-1']]]));
	}//end testMeetingOfAmendmentRoundViaRelations()

	/**
	 * Every unresolvable step of the walk answers null.
	 *
	 * @return void
	 */
	public function testUnresolvableRoundsAnswerNull(): void {
		$this->decisions['a-orphan'] = ['id' => 'a-orphan'];
		$this->decisions['motion-3'] = ['id' => 'motion-3'];

		$cases = [
			'no relations' => [],
			'motion without id' => ['relations' => [['schema' => 'motion']]],
			'missing motion' => ['relations' => [['schema' => 'motion', 'id' => 'gone']]],
			'amendment without id' => ['relations' => [['schema' => 'amendment']]],
			'missing amendment' => ['relations' => [['schema' => 'amendment', 'id' => 'gone']]],
			'amendment without parent' => ['relations' => [['schema' => 'amendment', 'id' => 'a-orphan']]],
			'motion without meeting' => ['relations' => [['schema' => 'motion', 'id' => 'motion-3']]],
		];
		foreach ($cases as $label => $round) {
			$this->assertNull($this->service->resolveMeetingIdForRound(round: $round), $label);
		}
	}//end testUnresolvableRoundsAnswerNull()
}//end class
