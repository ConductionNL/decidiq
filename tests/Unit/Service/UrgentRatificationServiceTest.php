<?php

/**
 * The ratifying stage of an urgent decision, and where it is placed.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Service\UrgentRatificationService;
use OCA\Decidiq\Tests\Unit\Support\UrgentProcedureStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Stage append, agenda placement, the pending fallback and its retry.
 *
 * @covers \OCA\Decidiq\Service\UrgentRatificationService
 */
class UrgentRatificationServiceTest extends TestCase {

	/**
	 * The urgent decision.
	 *
	 * @var array<string, mixed>
	 */
	private const DECISION = ['id' => 'dec-1', 'title' => 'Noodopvang statushouders', 'isUrgent' => true, 'urgencyReason' => 'Acute opvangcrisis'];

	/**
	 * The policy: ratification by the Gemeenteraad.
	 *
	 * @var array<string, mixed>
	 */
	private const POLICY = ['allowedTriggerRoles' => ['chair'], 'ratificationRequired' => true, 'ratifyingBody' => 'body-raad'];

	/**
	 * The service at 2026-06-02 10:00 UTC.
	 *
	 * @return UrgentRatificationService
	 */
	private function service(): UrgentRatificationService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-02T10:00:00+00:00'));
		return new UrgentRatificationService(timeFactory: $time);
	}//end service()

	/**
	 * A store with the decision's college stage.
	 *
	 * @return UrgentProcedureStore
	 */
	private function store(): UrgentProcedureStore {
		$store = new UrgentProcedureStore();
		$store->put(schema: 'decision', object: self::DECISION);
		$store->put(schema: 'decision-stage', object: ['id' => 'stage-college', 'decision' => 'dec-1', 'sequence' => 1, 'stageType' => 'decisive', 'status' => 'decided']);
		return $store;
	}//end store()

	/**
	 * The ratifying stage follows the last stage, for the ratifying body, and lands on its next regular meeting.
	 *
	 * @return void
	 */
	public function testTheStageIsAppendedAndPlacedOnTheNextRegularMeeting(): void {
		$store = $this->store();
		$store->put(schema: 'meeting', object: ['id' => 'raad-later', 'governanceBody' => 'body-raad', 'meetingType' => 'regular', 'scheduledDate' => '2026-07-02T19:30:00+02:00']);
		$store->put(schema: 'meeting', object: ['id' => 'raad-next', 'governanceBody' => 'body-raad', 'meetingType' => 'regular', 'scheduledDate' => '2026-06-14T19:30:00+02:00']);
		$store->put(schema: 'meeting', object: ['id' => 'raad-past', 'governanceBody' => 'body-raad', 'meetingType' => 'regular', 'scheduledDate' => '2026-05-20T19:30:00+02:00']);
		$store->put(schema: 'meeting', object: ['id' => 'raad-extra', 'governanceBody' => 'body-raad', 'meetingType' => 'extraordinary', 'scheduledDate' => '2026-06-05T19:30:00+02:00']);
		$store->put(schema: 'meeting', object: ['id' => 'raad-cancelled', 'governanceBody' => 'body-raad', 'meetingType' => 'regular', 'lifecycle' => 'cancelled', 'scheduledDate' => '2026-06-08T19:30:00+02:00']);
		$store->put(schema: 'agenda-item', object: ['id' => 'item-1', 'meeting' => 'raad-next', 'orderNumber' => 4]);

		$result = $this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);

		$this->assertSame('raad-next', $result['meeting']['id']);
		$this->assertSame(
			['decision' => 'dec-1', 'sequence' => 2, 'stageType' => 'ratifying', 'status' => 'pending', 'mandatory' => true, 'decisionMakerType' => 'body', 'assignedBody' => 'body-raad', 'label' => 'Ratification', 'placementPending' => true],
			$store->saves[0][2]
		);
		$this->assertSame(
			['title' => 'Ratification: Noodopvang statushouders', 'itemType' => 'decision', 'orderNumber' => 5, 'meeting' => 'raad-next', 'ratifiesDecision' => 'dec-1', 'description' => 'Acute opvangcrisis'],
			$store->saves[1][2]
		);
		$this->assertSame(['decision-stage', 'decision-stage-new-1'], [$store->saves[2][0], $store->saves[2][1]]);
		$this->assertSame('agenda-item-new-2', $result['stage']['agendaItem']);
		$this->assertFalse($result['stage']['placementPending']);
	}//end testTheStageIsAppendedAndPlacedOnTheNextRegularMeeting()

	/**
	 * An override chosen at trigger time replaces the policy's ratifying body.
	 *
	 * @return void
	 */
	public function testTheTriggerTimeOverrideNamesTheBody(): void {
		$store = $this->store();

		$result = $this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY, bodyOverride: 'body-rvc');

		$this->assertSame('body-rvc', $result['stage']['assignedBody']);
	}//end testTheTriggerTimeOverrideNamesTheBody()

	/**
	 * Without a regular meeting the placement is pending and nothing is put on an agenda.
	 *
	 * @return void
	 */
	public function testWithoutARegularMeetingThePlacementIsPending(): void {
		$store = $this->store();

		$result = $this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);

		$this->assertNull($result['meeting']);
		$this->assertNull($result['agendaItem']);
		$this->assertTrue($result['stage']['placementPending']);
		$this->assertCount(1, $store->saves);
	}//end testWithoutARegularMeetingThePlacementIsPending()

	/**
	 * A pending ratification is placed when a regular meeting of its body is created.
	 *
	 * @return void
	 */
	public function testAPendingRatificationIsPlacedOnANewRegularMeeting(): void {
		$store = $this->store();
		$this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);
		$other = ['id' => 'other-meeting', 'governanceBody' => 'body-college', 'meetingType' => 'regular', 'scheduledDate' => '2026-06-20T10:00:00+02:00'];
		$raad  = ['id' => 'raad-new', 'governanceBody' => 'body-raad', 'meetingType' => 'regular', 'scheduledDate' => '2026-06-18T19:30:00+02:00'];

		$this->assertSame(0, $this->service()->placePending(objectService: $store, meeting: $other));
		$this->assertSame(1, $this->service()->placePending(objectService: $store, meeting: $raad));
		$this->assertSame('raad-new', $store->saves[1][2]['meeting']);
		$this->assertFalse($store->objects['decision-stage']['decision-stage-new-1']['placementPending']);
		$this->assertSame(0, $this->service()->placePending(objectService: $store, meeting: $raad));
	}//end testAPendingRatificationIsPlacedOnANewRegularMeeting()

	/**
	 * A meeting in the past or of another type takes no pending ratification.
	 *
	 * @return void
	 */
	public function testOnlyAFutureRegularMeetingTakesThePlacement(): void {
		$store = $this->store();
		$this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);

		$this->assertSame(0, $this->service()->placePending(objectService: $store, meeting: ['id' => 'm1', 'governanceBody' => 'body-raad', 'meetingType' => 'regular', 'scheduledDate' => '2026-05-01T10:00:00+00:00']));
		$this->assertSame(0, $this->service()->placePending(objectService: $store, meeting: ['id' => 'm2', 'governanceBody' => 'body-raad', 'meetingType' => 'extraordinary', 'scheduledDate' => '2026-07-01T10:00:00+00:00']));
		$this->assertSame(0, $this->service()->placePending(objectService: $store, meeting: ['id' => 'm3', 'governanceBody' => 'body-raad', 'meetingType' => 'regular']));
	}//end testOnlyAFutureRegularMeetingTakesThePlacement()

	/**
	 * A second call does not append a second ratifying stage.
	 *
	 * @return void
	 */
	public function testARepeatedCallAddsNoSecondStage(): void {
		$store = $this->store();
		$this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);

		$again = $this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);

		$this->assertSame('decision-stage-new-1', $again['stage']['id']);
		$this->assertCount(1, $store->saves);
	}//end testARepeatedCallAddsNoSecondStage()

	/**
	 * A policy that does not require ratification appends nothing.
	 *
	 * @return void
	 */
	public function testNoRatificationWhenThePolicyDoesNotRequireIt(): void {
		$store = $this->store();

		$this->assertNull($this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: ['ratificationRequired' => false]));
		$this->assertSame([], $store->saves);
	}//end testNoRatificationWhenThePolicyDoesNotRequireIt()

	/**
	 * Without a ratifying body, or when a save fails, the call fails loudly.
	 *
	 * @return void
	 */
	public function testAMissingBodyOrAFailedSaveFails(): void {
		$store = $this->store();
		try {
			$this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: ['ratificationRequired' => true]);
			$this->fail('expected a refusal without a ratifying body');
		} catch (RuntimeException $e) {
			$this->assertSame('The urgency policy names no ratifying body.', $e->getMessage());
		}

		$store->failingSaves = ['decision-stage'];
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The decision-stage of the ratification could not be saved: save refused');
		$this->service()->appendRatification(objectService: $store, decision: self::DECISION, policy: self::POLICY);
	}//end testAMissingBodyOrAFailedSaveFails()
}//end class
