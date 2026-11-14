<?php

/**
 * The expedited written round of an urgent decision.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use OCA\Decidiq\Service\DecisionContextResolver;
use OCA\Decidiq\Service\ExpeditedRoundService;
use OCA\Decidiq\Tests\Unit\Support\UrgentProcedureStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Bounds enforced, round linked to the decisive stage, guard applied.
 *
 * @covers \OCA\Decidiq\Service\ExpeditedRoundService
 * @uses   \OCA\Decidiq\Lifecycle\UrgencyTriggerGuard
 * @uses   \OCA\Decidiq\Service\DecisionContextResolver
 * @uses   \OCA\Decidiq\Service\UrgentRatificationService
 */
class ExpeditedRoundServiceTest extends TestCase {

	/**
	 * The RvB's template policy.
	 *
	 * @var array<string, mixed>
	 */
	private const POLICY = ['allowedTriggerRoles' => ['chair', 'secretary'], 'ratificationRequired' => true, 'ratifyingBody' => 'body-rvc', 'responseDeadlineHours' => ['min' => 4, 'max' => 72]];

	/**
	 * The register.
	 *
	 * @var UrgentProcedureStore
	 */
	private UrgentProcedureStore $store;

	/**
	 * An urgent RvB decision with a pending decisive stage and a later ratifying stage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new UrgentProcedureStore();
		$this->store->put(schema: 'decision', object: ['id' => 'dec-2', 'title' => 'Forensic IT firm', 'isUrgent' => true, 'meeting' => 'meet-rvb']);
		$this->store->put(schema: 'meeting', object: ['id' => 'meet-rvb', 'governanceBody' => 'body-rvb', 'chair' => 'part-ceo']);
		$this->store->put(schema: 'participant', object: ['id' => 'part-ceo', 'nextcloudUserId' => 'ceo']);
		$this->store->put(schema: 'decision-stage', object: ['id' => 'stage-old', 'decision' => 'dec-2', 'sequence' => 1, 'stageType' => 'decisive', 'status' => 'skipped']);
		$this->store->put(schema: 'decision-stage', object: ['id' => 'stage-rvb', 'decision' => 'dec-2', 'sequence' => 2, 'stageType' => 'decisive', 'status' => 'active', 'label' => 'RvB']);
		$this->store->put(schema: 'decision-stage', object: ['id' => 'stage-rvc', 'decision' => 'dec-2', 'sequence' => 3, 'stageType' => 'ratifying', 'status' => 'pending']);
	}//end setUp()

	/**
	 * The service at 2026-06-10 18:40 +02:00.
	 *
	 * @param array<string, mixed>|null $policy The body's policy.
	 *
	 * @return ExpeditedRoundService
	 */
	private function service(?array $policy = self::POLICY): ExpeditedRoundService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$templates = $this->createMock(ProcessTemplateServiceInterface::class);
		$templates->method('resolveUrgencyPolicyForBody')->willReturn($policy);
		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('hasRole')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-10T18:40:00+02:00'));

		return new ExpeditedRoundService(
			container: $container,
			context: new DecisionContextResolver(logger: new NullLogger()),
			templates: $templates,
			guard: new UrgencyTriggerGuard(participants: $participants),
			timeFactory: $time,
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * A 24-hour round: deadline 24 hours after opening, on the open decisive stage.
	 *
	 * @return void
	 */
	public function testA24HourRoundIsOpenedOnTheDecisiveStage(): void {
		$result = $this->service()->open(decisionId: 'dec-2', userId: 'ceo', hours: 24);

		$this->assertSame(201, $result['status']);
		$this->assertSame(
			['votingMethod' => 'for-against-abstain', 'isSecret' => false, 'openedAt' => '2026-06-10T18:40:00+02:00', 'votingDeadline' => '2026-06-11T18:40:00+02:00', 'decisionStage' => 'stage-rvb'],
			$this->store->saves[0][2]
		);
		$this->assertSame(['decision-stage', 'stage-rvb'], [$this->store->saves[1][0], $this->store->saves[1][1]]);
		$this->assertSame('vote', $this->store->saves[1][2]['method']);
		$this->assertSame('voting-round-new-1', $this->store->saves[1][2]['votingRound']);
		$this->assertSame('RvB', $this->store->saves[1][2]['label']);
	}//end testA24HourRoundIsOpenedOnTheDecisiveStage()

	/**
	 * Hours outside the bounds, or not a whole number, are refused naming the bounds; no round is made.
	 *
	 * @return void
	 */
	public function testHoursOutsideTheBoundsAreRefused(): void {
		foreach ([1, 73, '1.5', 'abc', null, -4] as $hours) {
			$result = $this->service()->open(decisionId: 'dec-2', userId: 'ceo', hours: $hours);
			$this->assertSame(['status' => 422, 'data' => ['message' => 'responseDeadlineHours must be a whole number of hours between 4 and 72.']], $result, var_export($hours, true));
		}

		$this->assertSame(201, $this->service()->open(decisionId: 'dec-2', userId: 'ceo', hours: '72')['status']);
		$this->assertCount(2, $this->store->saves);
	}//end testHoursOutsideTheBoundsAreRefused()

	/**
	 * Another user is refused; a decision that is not urgent or has no policy gets 422.
	 *
	 * @return void
	 */
	public function testTheGuardAndThePreconditions(): void {
		$this->assertSame(403, $this->service()->open(decisionId: 'dec-2', userId: 'member', hours: 24)['status']);
		$this->assertSame(422, $this->service(policy: null)->open(decisionId: 'dec-2', userId: 'ceo', hours: 24)['status']);
		$this->assertSame(404, $this->service()->open(decisionId: 'nope', userId: 'ceo', hours: 24)['status']);

		$this->store->put(schema: 'decision', object: ['id' => 'dec-2', 'meeting' => 'meet-rvb', 'isUrgent' => false]);
		$this->assertSame(['status' => 422, 'data' => ['message' => 'Only an urgent decision has an expedited round.']], $this->service()->open(decisionId: 'dec-2', userId: 'ceo', hours: 24));
		$this->assertSame([], $this->store->saves);
	}//end testTheGuardAndThePreconditions()

	/**
	 * Without an open decisive stage there is nothing to hold the round.
	 *
	 * @return void
	 */
	public function testWithoutAnOpenDecisiveStageNoRoundIsMade(): void {
		$this->store->put(schema: 'decision-stage', object: ['id' => 'stage-rvb', 'decision' => 'dec-2', 'sequence' => 2, 'stageType' => 'decisive', 'status' => 'decided']);

		$result = $this->service()->open(decisionId: 'dec-2', userId: 'ceo', hours: 24);

		$this->assertSame(['status' => 422, 'data' => ['message' => 'The decision has no open decisive stage to hold the round.']], $result);
	}//end testWithoutAnOpenDecisiveStageNoRoundIsMade()

	/**
	 * A failed save answers 500.
	 *
	 * @return void
	 */
	public function testAFailedSaveAnswers500(): void {
		$this->store->failingSaves = ['voting-round'];

		$this->assertSame(500, $this->service()->open(decisionId: 'dec-2', userId: 'ceo', hours: 24)['status']);
	}//end testAFailedSaveAnswers500()
}//end class
