<?php

/**
 * Unit tests for CitizenAdviceService (issue #1418).
 *
 * The OpenRegister object service is a generated mock of the real
 * ObjectServiceInterface contract, so every call this service makes is to a
 * method OpenRegister actually has, with its real parameter names.
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
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Service\CitizenAdviceService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Opening, closing and counting a residents' advisory vote on a motion.
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
 */
class CitizenAdviceServiceTest extends TestCase {

	/**
	 * The patches written, newest last.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The findAll and find calls' RBAC flags, newest last.
	 *
	 * @var array<int, bool>
	 */
	private array $rbacFlags = [];

	/**
	 * A published motion that allows citizen voting with the simple method.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function motion(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'm-1',
				'decisionType' => 'motion',
				'title' => 'Motie meer groen in de wijk',
				'isPublished' => 'public',
				'citizenVotingAllowed' => true,
				'citizenVotingMethod' => 'simple',
			],
			$overrides
		);
	}//end motion()

	/**
	 * Build the service over an in-memory motion and citizen votes.
	 *
	 * @param array<string, mixed>|null $motion The stored motion, or null for none.
	 * @param array<int, array<string, mixed>> $votes The stored citizen votes.
	 *
	 * @return CitizenAdviceService
	 */
	private function service(?array $motion, array $votes = []): CitizenAdviceService {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null, bool $_rbac = true) use ($motion): ?ObjectEntityInterface {
				$this->rbacFlags[] = $_rbac;
				if ($motion === null || $id !== $motion['id'] || $schema !== 'decision') {
					return null;
				}

				return $this->entity(row: $motion);
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($votes): array {
				$this->rbacFlags[] = $_rbac;
				$filters = ($config['filters'] ?? []);
				self::assertSame('citizen-vote', $filters['schema'] ?? null);

				return array_map(fn (array $vote): ObjectEntityInterface => $this->entity(row: $vote), $votes);
			}
		);

		$objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, string|int|null $register = null, string|int|null $schema = null, bool $_rbac = true) use ($motion): ObjectEntityInterface {
				$this->rbacFlags[] = $_rbac;
				$this->patches[] = ['id' => $objectId, 'schema' => $schema, 'data' => $data];

				return $this->entity(row: array_merge((array)$motion, $data));
			}
		);

		return new CitizenAdviceService(objectService: $objectService);
	}//end service()

	/**
	 * An entity that serialises to the row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getObject')->willReturn($row);

		return $entity;
	}//end entity()

	/**
	 * A citizen vote on a motion.
	 *
	 * @param string $motionId The motion.
	 * @param string $value The advice.
	 *
	 * @return array<string, mixed>
	 */
	private function vote(string $motionId, string $value): array {
		return ['motionId' => $motionId, 'voteValue' => $value, 'voterId' => uniqid('resident-'), 'castAt' => '2026-09-28T10:00:00+00:00'];
	}//end vote()

	/**
	 * The griffier opens the advice round on a published motion that allows
	 * citizen voting (REQ-CAV-001 "The griffier opens the advice round").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function testOpenSetsTheStatusToOpen(): void {
		$motion = $this->service(motion: $this->motion())->open(motionId: 'm-1');

		self::assertSame('open', $motion['citizenVotingStatus']);
		self::assertSame([['id' => 'm-1', 'schema' => 'decision', 'data' => ['citizenVotingStatus' => 'open']]], $this->patches);
		self::assertNotContains(true, $this->rbacFlags, 'The caller is authorised by the controller; the decision schema grants update to admins only');
	}//end testOpenSetsTheStatusToOpen()

	/**
	 * A motion residents cannot read cannot be opened (REQ-CAV-001 "A motion
	 * that residents cannot read"), and neither can a motion that does not
	 * allow citizen voting, uses another method, or is already open or closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function testOpenIsRefusedWithAReason(): void {
		$cases = [
			'The motion must be published before residents can give their advice' => ['isPublished' => 'internal'],
			'Citizen voting is not allowed on this motion' => ['citizenVotingAllowed' => false],
			'An advisory vote by residents supports the simple method only' => ['citizenVotingMethod' => 'ranked'],
			'The advisory vote on this motion is already open' => ['citizenVotingStatus' => 'open'],
			'The advisory vote on this motion has closed and cannot open again' => ['citizenVotingStatus' => 'closed'],
		];

		foreach ($cases as $reason => $overrides) {
			$this->patches = [];
			try {
				$this->service(motion: $this->motion($overrides))->open(motionId: 'm-1');
				self::fail('Expected a refusal: ' . $reason);
			} catch (ParticipationValidationException $e) {
				self::assertSame($reason, $e->getMessage());
			}

			self::assertSame([], $this->patches, 'A refused open writes nothing');
		}
	}//end testOpenIsRefusedWithAReason()

	/**
	 * A decision that is not a motion, or no object at all, is not found.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function testOnlyAMotionCanBeOpened(): void {
		foreach ([$this->motion(['decisionType' => 'resolution']), null] as $stored) {
			try {
				$this->service(motion: $stored)->open(motionId: 'm-1');
				self::fail('Expected not found');
			} catch (NotFoundException $e) {
				self::assertStringContainsString('m-1', $e->getMessage());
			}
		}
	}//end testOnlyAMotionCanBeOpened()

	/**
	 * Closing counts voor, tegen and onthoud from this motion's citizen votes
	 * only, and stores them with the closed status (REQ-CAV-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
	 */
	public function testCloseStoresTheCounts(): void {
		$votes = [
			$this->vote(motionId: 'm-1', value: 'voor'),
			$this->vote(motionId: 'm-1', value: 'voor'),
			$this->vote(motionId: 'm-1', value: 'voor'),
			$this->vote(motionId: 'm-1', value: 'tegen'),
			$this->vote(motionId: 'm-1', value: 'onthoud'),
			$this->vote(motionId: 'm-2', value: 'tegen'),
		];

		$motion = $this->service(motion: $this->motion(['citizenVotingStatus' => 'open']), votes: $votes)->close(motionId: 'm-1');

		self::assertSame('closed', $motion['citizenVotingStatus']);
		self::assertSame(3, $motion['citizenAdviceFor']);
		self::assertSame(1, $motion['citizenAdviceAgainst']);
		self::assertSame(1, $motion['citizenAdviceAbstain']);
		self::assertNotContains(true, $this->rbacFlags, 'A count limited to the caller\'s own rows would close the vote at zero');
	}//end testCloseStoresTheCounts()

	/**
	 * A motion whose advisory vote is not open cannot be closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function testCloseWhenNotOpenIsRefused(): void {
		$this->expectException(ParticipationValidationException::class);
		$this->expectExceptionMessage('The advisory vote on this motion is not open');

		$this->service(motion: $this->motion())->close(motionId: 'm-1');
	}//end testCloseWhenNotOpenIsRefused()
}//end class
