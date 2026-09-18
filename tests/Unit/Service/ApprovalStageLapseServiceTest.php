<?php

/**
 * Unit tests for ApprovalStageLapseService.
 *
 * The sweep that turns a declared silence into a change on the record. Two
 * properties are load-bearing and both are asserted here: it writes an action
 * that says WHY a step moved when nobody signed it, and running it twice does
 * the work once.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-016, REQ-AR-017)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\ApprovalActorResolver;
use OCA\Decidiq\Service\ApprovalStageActivator;
use OCA\Decidiq\Service\ApprovalStageLapseService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\StageLapsePolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the four policies end to end, the stand-in ask, and idempotency.
 */
final class ApprovalStageLapseServiceTest extends TestCase {
	/**
	 * A stand-in register: the rows the sweep reads and the writes it makes.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The objects saved, by schema.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The store double.
	 *
	 * @var RegisterObjectStore&MockObject
	 */
	private RegisterObjectStore&MockObject $store;

	/**
	 * The activator double.
	 *
	 * @var ApprovalStageActivator&MockObject
	 */
	private ApprovalStageActivator&MockObject $activator;

	/**
	 * Who was told what.
	 *
	 * @var array<int, array{person: string, title: string}>
	 */
	private array $told = [];

	/**
	 * Build the doubles over the stand-in register.
	 *
	 * `onlyMethods` throughout: a double that may ADD a method can pass a test
	 * against a class that does not have it, which is how a green suite covers
	 * a call that 500s in production.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = ['decision-stage' => []];
		$this->saved = [];
		$this->told = [];

		$this->store = $this->getMockBuilder(RegisterObjectStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'patch', 'find', 'findAll'])
			->getMock();

		$this->store->method('findAll')->willReturnCallback(
			function (string $schema, array $filters): array {
				$rows = ($this->rows[$schema] ?? []);
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ((string)($row[$key] ?? '') !== (string)$value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$this->store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid): array {
				foreach (($this->rows[$schema] ?? []) as $index => $row) {
					if ((string)($row['id'] ?? '') !== $uuid) {
						continue;
					}

					$this->rows[$schema][$index] = array_merge($row, $data);
					return $this->rows[$schema][$index];
				}

				return [];
			}
		);

		$this->store->method('save')->willReturnCallback(
			function (string $schema, array $object): array {
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				return $object;
			}
		);

		$this->activator = $this->getMockBuilder(ApprovalStageActivator::class)
			->disableOriginalConstructor()
			->onlyMethods(['activationPatch', 'substituteOf', 'managerOf'])
			->getMock();
		$this->activator->method('activationPatch')->willReturn(['status' => 'active']);
		$this->activator->method('substituteOf')->willReturn(null);
		$this->activator->method('managerOf')->willReturn(null);
	}//end setUp()

	/**
	 * The service, over whatever the doubles were told to answer.
	 *
	 * @param bool $withNotifier Whether to attach a notifier.
	 *
	 * @return ApprovalStageLapseService The service.
	 */
	private function service(bool $withNotifier = false): ApprovalStageLapseService {
		$notifier = null;
		if ($withNotifier === true) {
			$notifier = $this->getMockBuilder(NotificationPreferenceService::class)
				->disableOriginalConstructor()
				->onlyMethods(['dispatch'])
				->getMock();
			$notifier->method('dispatch')->willReturnCallback(
				function (string $personId, string $eventType, string $title, string $message, string $deepLink = ''): int {
					$this->told[] = ['person' => $personId, 'title' => $title];
					return 1;
				}
			);
		}

		return new ApprovalStageLapseService(
			store: $this->store,
			policy: new StageLapsePolicy(),
			activator: $this->activator,
			logger: $this->createMock(LoggerInterface::class),
			notifier: $notifier,
		);
	}//end service()

	/**
	 * A two step route whose first step is overdue.
	 *
	 * @param array<string, mixed> $overrides Overrides on the live stage.
	 *
	 * @return void
	 */
	private function givenAnOverdueRoute(array $overrides = []): void {
		$this->rows['decision-stage'] = [
			array_merge(
				[
					'id' => 'stage-1',
					'sequence' => 1,
					'status' => 'active',
					'decision' => 'subject-1',
					'note' => 'decision',
					'assignedPerson' => 'j.jansen',
					'activatedAt' => '2026-09-08T12:00:00+00:00',
					'dueAt' => '2026-09-16T12:00:00+00:00',
				],
				$overrides
			),
			[
				'id' => 'stage-2',
				'sequence' => 2,
				'status' => 'pending',
				'decision' => 'subject-1',
				'note' => 'decision',
				'assignedPerson' => 'd.devries',
			],
		];
	}//end givenAnOverdueRoute()

	/**
	 * The clock every test sweeps on.
	 *
	 * @return DateTimeImmutable The clock.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-18T03:00:00+00:00');
	}//end now()

	/**
	 * A stage of a given id, as the stand-in register now holds it.
	 *
	 * @param string $id The stage id.
	 *
	 * @return array<string, mixed> The stage.
	 */
	private function stage(string $id): array {
		foreach ($this->rows['decision-stage'] as $row) {
			if ((string)$row['id'] === $id) {
				return $row;
			}
		}

		return [];
	}//end stage()

	/**
	 * The default leaves everything exactly as it was.
	 *
	 * @return void
	 */
	public function testTheDefaultChangesNothing(): void {
		$this->givenAnOverdueRoute();

		$result = $this->service()->sweep($this->now());

		$this->assertSame(0, $result['lapsed']);
		$this->assertSame('active', $this->stage('stage-1')['status']);
		$this->assertSame('pending', $this->stage('stage-2')['status']);
		$this->assertSame([], $this->saved);
	}//end testTheDefaultChangesNothing()

	/**
	 * A declared approval advances the route and writes an auditable action.
	 *
	 * @return void
	 */
	public function testADeclaredApprovalAdvancesAndIsRecorded(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE]);

		$result = $this->service()->sweep($this->now());

		$this->assertSame(1, $result['lapsed']);
		$this->assertSame('decided', $this->stage('stage-1')['status']);
		$this->assertSame('approved', $this->stage('stage-1')['outcome']);
		$this->assertSame('active', $this->stage('stage-2')['status']);

		$this->assertCount(1, $this->saved);
		$this->assertSame('approval-action', $this->saved[0]['schema']);
		$this->assertSame('system', $this->saved[0]['object']['actorType']);
		$this->assertSame('lapsed', $this->saved[0]['object']['action']);
		$this->assertSame(
			StageLapsePolicy::ON_SILENCE_APPROVE,
			$this->saved[0]['object']['onSilencePolicy']
		);
	}//end testADeclaredApprovalAdvancesAndIsRecorded()

	/**
	 * A declared refusal concludes and leaves nothing live.
	 *
	 * @return void
	 */
	public function testADeclaredRefusalConcludesTheRoute(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_REFUSE]);

		$this->service()->sweep($this->now());

		$this->assertSame('rejected', $this->stage('stage-1')['outcome']);
		$this->assertSame('pending', $this->stage('stage-2')['status']);
		$this->assertSame('refused', $this->saved[0]['object']['state']);
	}//end testADeclaredRefusalConcludesTheRoute()

	/**
	 * Two sweeps do the work once. This is the property that lets the sweep be
	 * re-run after a failed cron tick.
	 *
	 * @return void
	 */
	public function testTwoSweepsDoTheWorkOnce(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE]);

		$service = $this->service();
		$service->sweep($this->now());
		$service->sweep($this->now()->modify('+1 hour'));

		$this->assertCount(1, $this->saved);
		$this->assertSame('decided', $this->stage('stage-1')['status']);
		$this->assertSame('active', $this->stage('stage-2')['status']);
	}//end testTwoSweepsDoTheWorkOnce()

	/**
	 * Somebody who signed after the term but before the sweep keeps their
	 * signature.
	 *
	 * @return void
	 */
	public function testAPersonWhoActedJustInTimeWins(): void {
		$this->givenAnOverdueRoute([
			'onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE,
			'status' => 'decided',
			'outcome' => 'approved',
		]);

		$result = $this->service()->sweep($this->now());

		$this->assertSame(0, $result['lapsed']);
		$this->assertSame([], $this->saved);
	}//end testAPersonWhoActedJustInTimeWins()

	/**
	 * Escalation moves the stage to the manager and gives it a fresh term.
	 *
	 * @return void
	 */
	public function testEscalationReassignsAndRestartsTheWindow(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_ESCALATE]);
		$this->activator = $this->getMockBuilder(ApprovalStageActivator::class)
			->disableOriginalConstructor()
			->onlyMethods(['activationPatch', 'substituteOf', 'managerOf'])
			->getMock();
		$this->activator->method('managerOf')->willReturn('b.bakker');
		$this->activator->method('substituteOf')->willReturn(null);
		$this->activator->method('activationPatch')->willReturn(['status' => 'active']);

		$this->service()->sweep($this->now());

		$stage = $this->stage('stage-1');
		$this->assertSame('b.bakker', $stage['assignedPerson']);
		$this->assertSame('active', $stage['status']);
		$this->assertSame(ApprovalActorResolver::RULE_MANAGER_OF_ACTOR, $stage['actorResolvedBy']);
		// Eight days was the original term, so the fresh one ends eight days out.
		$this->assertSame('2026-09-26T03:00:00+00:00', $stage['dueAt']);
	}//end testEscalationReassignsAndRestartsTheWindow()

	/**
	 * Escalation with nobody to escalate to holds. It does not advance and it
	 * does not conclude: both turn a missing line in the organisation record
	 * into a decision.
	 *
	 * @return void
	 */
	public function testEscalationWithNoManagerHolds(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_ESCALATE]);

		$result = $this->service()->sweep($this->now());

		$this->assertSame(0, $result['lapsed']);
		$this->assertSame('active', $this->stage('stage-1')['status']);
		$this->assertSame('j.jansen', $this->stage('stage-1')['assignedPerson']);
		$this->assertSame([], $this->saved);
	}//end testEscalationWithNoManagerHolds()

	/**
	 * The stand-in is asked part-way through the term, and BOTH are told.
	 *
	 * @return void
	 */
	public function testTheSubstituteIsAskedAndBothAreTold(): void {
		$this->givenAnOverdueRoute([
			'activatedAt' => '2026-09-13T03:00:00+00:00',
			'dueAt' => '2026-09-23T03:00:00+00:00',
			'askSubstituteAfter' => 0.5,
		]);
		$this->activator = $this->getMockBuilder(ApprovalStageActivator::class)
			->disableOriginalConstructor()
			->onlyMethods(['activationPatch', 'substituteOf', 'managerOf'])
			->getMock();
		$this->activator->method('substituteOf')->willReturn('p.peters');
		$this->activator->method('managerOf')->willReturn(null);
		$this->activator->method('activationPatch')->willReturn(['status' => 'active']);

		$result = $this->service(withNotifier: true)->sweep($this->now());

		$this->assertSame(1, $result['substitutesAsked']);
		$this->assertSame('p.peters', $this->stage('stage-1')['substituteActor']);
		$this->assertSame('2026-09-18T03:00:00+00:00', $this->stage('stage-1')['substituteAskedAt']);

		$people = array_column($this->told, 'person');
		$this->assertContains('p.peters', $people);
		$this->assertContains('j.jansen', $people);
	}//end testTheSubstituteIsAskedAndBothAreTold()

	/**
	 * No stand-in is a note on the stage, not a refusal: the term and its
	 * declared meaning are untouched.
	 *
	 * @return void
	 */
	public function testNoSubstituteIsANoteNotARefusal(): void {
		$this->givenAnOverdueRoute([
			'activatedAt' => '2026-09-13T03:00:00+00:00',
			'dueAt' => '2026-09-23T03:00:00+00:00',
			'askSubstituteAfter' => 0.5,
			'onSilence' => StageLapsePolicy::ON_SILENCE_REFUSE,
		]);

		$result = $this->service()->sweep($this->now());

		$this->assertSame(0, $result['substitutesAsked']);
		$stage = $this->stage('stage-1');
		$this->assertSame('2026-09-18T03:00:00+00:00', $stage['substituteSearchedAt']);
		$this->assertSame('j.jansen', $stage['assignedPerson']);
		$this->assertSame('2026-09-23T03:00:00+00:00', $stage['dueAt']);
		$this->assertSame(StageLapsePolicy::ON_SILENCE_REFUSE, $stage['onSilence']);
		$this->assertSame('active', $stage['status']);
	}//end testNoSubstituteIsANoteNotARefusal()

	/**
	 * The silent actor is told what their silence was taken to mean.
	 *
	 * @return void
	 */
	public function testTheSilentActorIsToldWhatTheirSilenceMeant(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE]);

		$this->service(withNotifier: true)->sweep($this->now());

		$this->assertSame('j.jansen', $this->told[0]['person']);
		$this->assertStringContainsString('taken as approved', $this->told[0]['title']);
	}//end testTheSilentActorIsToldWhatTheirSilenceMeant()

	/**
	 * A missing notifier changes who hears about it, never whether the route
	 * moved.
	 *
	 * @return void
	 */
	public function testAMissingNotifierDoesNotStopTheRoute(): void {
		$this->givenAnOverdueRoute(['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE]);

		$this->service(withNotifier: false)->sweep($this->now());

		$this->assertSame('decided', $this->stage('stage-1')['status']);
		$this->assertSame([], $this->told);
	}//end testAMissingNotifierDoesNotStopTheRoute()
}//end class
