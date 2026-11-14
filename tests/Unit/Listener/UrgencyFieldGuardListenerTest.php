<?php

/**
 * Urgency fields are written only by the urgency flow and survive unrelated edits.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\UrgencyFieldGuardListener;
use OCA\Decidiq\Service\UrgencyWriteGrant;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Direct writes refused, the flow's own write allowed, PUT carry-forward.
 *
 * @covers \OCA\Decidiq\Listener\UrgencyFieldGuardListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\UrgencyWriteGrant
 */
class UrgencyFieldGuardListenerTest extends TestCase {

	/**
	 * The stored urgent decision.
	 *
	 * @var array<string, mixed>
	 */
	private const URGENT = [
		'title'             => 'Noodopvang statushouders',
		'isUrgent'          => true,
		'urgencyReason'     => 'Acute opvangcrisis',
		'urgencyDeclaredBy' => 'burgemeester',
		'urgencyDeclaredAt' => '2026-06-02T09:15:00+02:00',
	];

	/**
	 * An entity mock over a payload.
	 *
	 * @param array<string, mixed> $row The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn('dec-1');
		return $entity;
	}//end entity()

	/**
	 * The listener with a signed-in user (or none) and a grant.
	 *
	 * @param UrgencyWriteGrant $grant  The grant.
	 * @param bool              $signed Whether someone is signed in.
	 *
	 * @return UrgencyFieldGuardListener
	 */
	private function listener(UrgencyWriteGrant $grant, bool $signed = true): UrgencyFieldGuardListener {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signed === true ? $this->createMock(IUser::class) : null);
		return new UrgencyFieldGuardListener(grant: $grant, userSession: $session);
	}//end listener()

	/**
	 * Setting isUrgent on a direct update is refused.
	 *
	 * @return void
	 */
	public function testADirectUrgencyWriteIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity(['title' => 'Budget', 'isUrgent' => true]), $this->entity(['title' => 'Budget']));

		$this->listener(grant: new UrgencyWriteGrant())->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => UrgencyFieldGuardListener::REFUSAL], $event->getErrors());
	}//end testADirectUrgencyWriteIsRefused()

	/**
	 * Changing the stored reason directly is refused too.
	 *
	 * @return void
	 */
	public function testChangingTheReasonDirectlyIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity(array_merge(self::URGENT, ['urgencyReason' => 'Something else'])), $this->entity(self::URGENT));

		$this->listener(grant: new UrgencyWriteGrant())->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testChangingTheReasonDirectlyIsRefused()

	/**
	 * A create carrying urgency is refused; a create with isUrgent false passes.
	 *
	 * @return void
	 */
	public function testACreateCarryingUrgencyIsRefused(): void {
		$urgent = new ObjectCreatingEvent($this->entity(['title' => 'X', 'isUrgent' => true, 'urgencyReason' => 'r']));
		$plain  = new ObjectCreatingEvent($this->entity(['title' => 'X', 'isUrgent' => false]));

		$this->listener(grant: new UrgencyWriteGrant())->handle($urgent);
		$this->listener(grant: new UrgencyWriteGrant())->handle($plain);

		$this->assertTrue($urgent->isPropagationStopped());
		$this->assertFalse($plain->isPropagationStopped());
	}//end testACreateCarryingUrgencyIsRefused()

	/**
	 * The urgency flow's own save, under its grant, passes.
	 *
	 * @return void
	 */
	public function testTheGrantedSavePasses(): void {
		$grant = new UrgencyWriteGrant();
		$grant->grant(decisionId: 'dec-1');
		$event = new ObjectUpdatingEvent($this->entity(self::URGENT), $this->entity(['title' => 'Noodopvang statushouders']));

		$this->listener(grant: $grant)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());
	}//end testTheGrantedSavePasses()

	/**
	 * An unrelated title edit that leaves the fields out keeps the stored urgency.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEditCarriesTheUrgencyForward(): void {
		$event = new ObjectUpdatingEvent($this->entity(['title' => 'Noodopvang statushouders De Vliet']), $this->entity(self::URGENT));

		$this->listener(grant: new UrgencyWriteGrant())->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(
			['isUrgent' => true, 'urgencyReason' => 'Acute opvangcrisis', 'urgencyDeclaredBy' => 'burgemeester', 'urgencyDeclaredAt' => '2026-06-02T09:15:00+02:00'],
			$event->getModifiedData()
		);
	}//end testAnUnrelatedEditCarriesTheUrgencyForward()

	/**
	 * An edit that repeats the stored values unchanged passes.
	 *
	 * @return void
	 */
	public function testRepeatingTheStoredValuesPasses(): void {
		$event = new ObjectUpdatingEvent($this->entity(array_merge(self::URGENT, ['title' => 'New title'])), $this->entity(self::URGENT));

		$this->listener(grant: new UrgencyWriteGrant())->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testRepeatingTheStoredValuesPasses()

	/**
	 * A save without a user (seed import, background job) is not a client write.
	 *
	 * @return void
	 */
	public function testASaveWithoutAUserPasses(): void {
		$event = new ObjectCreatingEvent($this->entity(self::URGENT));

		$this->listener(grant: new UrgencyWriteGrant(), signed: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testASaveWithoutAUserPasses()
}//end class
