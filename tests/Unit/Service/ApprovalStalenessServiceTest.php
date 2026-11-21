<?php

/**
 * Unit tests for ApprovalStalenessService and the route rewind it asks of the
 * engine (REQ-DWP-003).
 *
 * The store is a fake over in-memory rows; the watcher and the advancer are the
 * real ones, so a withdrawal and a reopened step are what production writes.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalBasisWatcher;
use OCA\Decidiq\Service\ApprovalRouteAdvancer;
use OCA\Decidiq\Service\ApprovalStalenessService;
use OCA\Decidiq\Service\ApprovalWithdrawalNotifier;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\SubjectVersions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApprovalStalenessService.
 */
final class ApprovalStalenessServiceTest extends TestCase {
	/**
	 * Stored rows per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The schemas read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Every patch: [schema, uuid, data].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $patches = [];

	/**
	 * The notices asked for: [withdrawals, deepLink].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $notices = [];

	/**
	 * The service under test.
	 *
	 * @var ApprovalStalenessService
	 */
	private ApprovalStalenessService $service;

	/**
	 * Wire the service over the fake store, the real watcher and advancer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$store = $this->createMock(originalClassName: RegisterObjectStore::class);
		$store->method('findAll')->willReturnCallback(
			function (string $schema, array $filters): array {
				$this->reads[] = $schema;
				return ($this->rows[$schema] ?? []);
			}
		);
		$store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid): array {
				$this->patches[] = [$schema, $uuid, $data];
				return $data;
			}
		);

		$notifier = $this->createMock(originalClassName: ApprovalWithdrawalNotifier::class);
		$notifier->method('notify')->willReturnCallback(
			function (array $withdrawals, string $deepLink): int {
				$this->notices[] = [$withdrawals, $deepLink];
				return count($withdrawals);
			}
		);

		$this->service = new ApprovalStalenessService(
			store: $store,
			watcher: new ApprovalBasisWatcher(),
			advancer: new ApprovalRouteAdvancer(store: $store),
			notifier: $notifier,
		);
	}//end setUp()

	/**
	 * A route of two steps: step 1 approved by j.jansen with a basis, step 2 live.
	 *
	 * @param array<string, mixed> $first Overrides on the first stage.
	 *
	 * @return void
	 */
	private function routeWithAnApprovedFirstStep(array $first = []): void {
		$this->rows['decision-stage'] = [
			array_merge(
				[
					'id' => 'stage-1', 'route' => 'route-a', 'decision' => 'dec-1', 'note' => 'decision',
					'sequence' => 1, 'status' => 'decided', 'outcome' => 'approved',
					'approvalBasis' => ['body', 'attachments'],
				],
				$first
			),
			['id' => 'stage-2', 'route' => 'route-a', 'decision' => 'dec-1', 'sequence' => 2, 'status' => 'active'],
		];
		$this->rows['approval-action'] = [
			['id' => 'act-1', 'subject' => 'dec-1', 'step' => 1, 'actor' => 'j.jansen', 'action' => 'approved', 'state' => 'granted'],
		];
	}//end routeWithAnApprovedFirstStep()

	/**
	 * The patches written to one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<string, array<string, mixed>> Per uuid, the data.
	 */
	private function patchesOf(string $schema): array {
		$found = [];
		foreach ($this->patches as [$patched, $uuid, $data]) {
			if ($patched === $schema) {
				$found[$uuid] = $data;
			}
		}

		return $found;
	}//end patchesOf()

	/**
	 * Editing what was approved withdraws the grant, reopens the step, puts the
	 * step after it back to pending and tells the approver.
	 *
	 * @return void
	 */
	public function testEditingWhatWasApprovedWithdrawsTheApprovalAndReopensTheStep(): void {
		$this->routeWithAnApprovedFirstStep();

		$withdrawn = $this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1',
			before: ['body' => 'Version one', 'internalNote' => 'x'],
			after: ['body' => 'Version two', 'internalNote' => 'x'],
		));

		self::assertCount(expectedCount: 1, haystack: $withdrawn);
		self::assertSame(expected: ['body'], actual: $withdrawn[0]['withdrawnPaths']);

		$actions = $this->patchesOf(schema: 'approval-action');
		self::assertSame(expected: ['act-1'], actual: array_keys($actions));
		self::assertSame(expected: 'withdrawn', actual: $actions['act-1']['state']);
		self::assertSame(expected: 'basis-changed', actual: $actions['act-1']['withdrawnReason']);
		self::assertNotSame(expected: '', actual: $actions['act-1']['withdrawnAt']);

		$stages = $this->patchesOf(schema: 'decision-stage');
		self::assertSame(expected: ['status' => 'active', 'outcome' => null, 'decidedAt' => null], actual: $stages['stage-1']);
		self::assertSame(expected: ['status' => 'pending', 'outcome' => null, 'decidedAt' => null], actual: $stages['stage-2']);

		self::assertCount(expectedCount: 1, haystack: $this->notices);
		self::assertSame(expected: 'j.jansen', actual: $this->notices[0][0][0]['actor']);
		self::assertSame(expected: '/decisions/dec-1', actual: $this->notices[0][1]);
	}//end testEditingWhatWasApprovedWithdrawsTheApprovalAndReopensTheStep()

	/**
	 * Editing outside the basis keeps the approval: nothing is written, nobody is told.
	 *
	 * @return void
	 */
	public function testEditingOutsideTheBasisKeepsTheApproval(): void {
		$this->routeWithAnApprovedFirstStep();

		$withdrawn = $this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1',
			before: ['body' => 'Version one', 'internalNote' => 'old'],
			after: ['body' => 'Version one', 'internalNote' => 'new'],
		));

		self::assertSame(expected: [], actual: $withdrawn);
		self::assertSame(expected: [], actual: $this->patches);
		self::assertSame(expected: [], actual: $this->notices);
	}//end testEditingOutsideTheBasisKeepsTheApproval()

	/**
	 * A subject whose stages declare no basis costs one stage read and nothing more.
	 *
	 * @return void
	 */
	public function testAStageWithoutABasisIsNeverLookedAt(): void {
		$this->routeWithAnApprovedFirstStep(first: ['approvalBasis' => []]);

		$withdrawn = $this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1', before: ['body' => 'a'], after: ['body' => 'b']));

		self::assertSame(expected: [], actual: $withdrawn);
		self::assertSame(expected: ['decision-stage'], actual: $this->reads);
		self::assertSame(expected: [], actual: $this->patches);
	}//end testAStageWithoutABasisIsNeverLookedAt()

	/**
	 * A pending or skipped stage holds only history: its old grants are left alone.
	 *
	 * @return void
	 */
	public function testAStageTheRouteIsNotOnIsLeftAlone(): void {
		foreach (['pending', 'skipped'] as $status) {
			$this->patches = [];
			$this->routeWithAnApprovedFirstStep(first: ['status' => $status]);

			self::assertSame(
				expected: [],
				actual: $this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1', before: ['body' => 'a'], after: ['body' => 'b'])),
				message: $status
			);
			self::assertSame(expected: [], actual: $this->patches, message: $status);
		}
	}//end testAStageTheRouteIsNotOnIsLeftAlone()

	/**
	 * On a step still signing, the grant is withdrawn and the stage stays live
	 * as it is: there is nothing to reopen.
	 *
	 * @return void
	 */
	public function testAGrantOnAStepStillSigningIsWithdrawnWithoutARewind(): void {
		$this->routeWithAnApprovedFirstStep(first: ['status' => 'active', 'outcome' => null]);
		$this->rows['decision-stage'][1]['status'] = 'pending';

		$withdrawn = $this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1',
			before: ['attachments' => ['a.pdf']],
			after: ['attachments' => ['b.pdf']],
		));

		self::assertCount(expectedCount: 1, haystack: $withdrawn);
		self::assertSame(expected: ['act-1'], actual: array_keys($this->patchesOf(schema: 'approval-action')));
		self::assertSame(expected: [], actual: $this->patchesOf(schema: 'decision-stage'));
	}//end testAGrantOnAStepStillSigningIsWithdrawnWithoutARewind()

	/**
	 * Only grants are withdrawn: a refusal, an already withdrawn grant and an
	 * action on another step stay as they are.
	 *
	 * @return void
	 */
	public function testOnlyStandingGrantsOnTheStepAreWithdrawn(): void {
		$this->routeWithAnApprovedFirstStep();
		$this->rows['approval-action'] = [
			['id' => 'act-1', 'step' => 1, 'actor' => 'j.jansen', 'action' => 'approved', 'state' => 'granted'],
			['id' => 'act-2', 'step' => 1, 'actor' => 'p.de.vries', 'action' => 'returned', 'state' => 'refused'],
			['id' => 'act-3', 'step' => 1, 'actor' => 'a.bakker', 'action' => 'approved', 'state' => 'withdrawn'],
			['id' => 'act-4', 'step' => 2, 'actor' => 'k.smit', 'action' => 'approved', 'state' => 'granted'],
			['step' => 1, 'actor' => 'no.id', 'action' => 'approved', 'state' => 'granted'],
		];

		$this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1', before: ['body' => 'a'], after: ['body' => 'b']));

		self::assertSame(expected: ['act-1'], actual: array_keys($this->patchesOf(schema: 'approval-action')));
	}//end testOnlyStandingGrantsOnTheStepAreWithdrawn()

	/**
	 * Another route on the same subject is not rewound by this one's withdrawal.
	 *
	 * @return void
	 */
	public function testTheRewindStaysOnTheRouteWhoseGrantWentStale(): void {
		$this->routeWithAnApprovedFirstStep();
		$this->rows['decision-stage'][] = [
			'id' => 'other-1', 'route' => 'route-b', 'decision' => 'dec-1', 'sequence' => 3, 'status' => 'decided', 'outcome' => 'approved',
		];
		$this->rows['decision-stage'][] = ['id' => 'other-2', 'route' => 'route-b', 'decision' => 'dec-1', 'sequence' => 4, 'status' => 'active'];

		$this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1', before: ['body' => 'a'], after: ['body' => 'b']));

		self::assertSame(expected: ['stage-1', 'stage-2'], actual: array_keys($this->patchesOf(schema: 'decision-stage')));
	}//end testTheRewindStaysOnTheRouteWhoseGrantWentStale()

	/**
	 * Two routes with a stage at the same step see the same action: it is
	 * written and announced once, and both stages reopen.
	 *
	 * @return void
	 */
	public function testAnActionSeenByTwoRoutesIsWithdrawnOnce(): void {
		$this->routeWithAnApprovedFirstStep();
		$this->rows['decision-stage'][] = [
			'id' => 'other-1', 'route' => 'route-b', 'decision' => 'dec-1', 'sequence' => 1, 'status' => 'decided',
			'approvalBasis' => ['body'],
		];

		$withdrawn = $this->service->subjectChanged(change: new SubjectVersions(subject: 'dec-1', before: ['body' => 'a'], after: ['body' => 'b']));

		self::assertCount(expectedCount: 1, haystack: $withdrawn);
		self::assertSame(expected: ['act-1'], actual: array_keys($this->patchesOf(schema: 'approval-action')));
		self::assertSame(expected: ['stage-1', 'stage-2', 'other-1'], actual: array_keys($this->patchesOf(schema: 'decision-stage')));
		self::assertCount(expectedCount: 1, haystack: $this->notices[0][0]);
	}//end testAnActionSeenByTwoRoutesIsWithdrawnOnce()

	/**
	 * A subject without a uuid reads nothing.
	 *
	 * @return void
	 */
	public function testNoSubjectReadsNothing(): void {
		self::assertSame(expected: [], actual: $this->service->subjectChanged(change: new SubjectVersions(subject: ' ', before: [], after: [])));
		self::assertSame(expected: [], actual: $this->reads);
	}//end testNoSubjectReadsNothing()

	/**
	 * The filters that pick the watched stages and a step's actions.
	 *
	 * @return void
	 */
	public function testTheWatchedStagesAndTheStepsActions(): void {
		self::assertTrue(condition: ApprovalStalenessService::isWatched(stage: ['id' => 's', 'status' => 'decided', 'approvalBasis' => ['body']]));
		self::assertTrue(condition: ApprovalStalenessService::isWatched(stage: ['id' => 's', 'status' => 'active', 'approvalBasis' => ['body']]));
		self::assertFalse(condition: ApprovalStalenessService::isWatched(stage: ['id' => 's', 'status' => 'pending', 'approvalBasis' => ['body']]));
		self::assertFalse(condition: ApprovalStalenessService::isWatched(stage: ['id' => 's', 'status' => 'decided']));
		self::assertFalse(condition: ApprovalStalenessService::isWatched(stage: ['id' => '', 'status' => 'decided', 'approvalBasis' => ['body']]));

		$actions = [['id' => 'a', 'step' => 1], ['id' => 'b', 'step' => 2], ['id' => 'c', 'step' => 1], ['step' => 1]];
		self::assertSame(
			expected: [['id' => 'a', 'step' => 1], ['id' => 'c', 'step' => 1]],
			actual: ApprovalStalenessService::actionsOfStep(actions: $actions, sequence: 1)
		);
	}//end testTheWatchedStagesAndTheStepsActions()
}//end class
