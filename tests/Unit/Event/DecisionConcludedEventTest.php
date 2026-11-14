<?php

/**
 * Unit tests for DecisionConcludedEvent.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Event;

use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the decision-concluded announcement, mainly fromEnvelope(): which envelope keys map where, the defaults for a missing key, and the casts.
 *
 * @covers \OCA\Decidiq\Event\DecisionConcludedEvent
 */
class DecisionConcludedEventTest extends TestCase {
	/**
	 * A full envelope maps key for key, with outcome, source and correlation from the arguments.
	 *
	 * @return void
	 */
	public function testFromEnvelopeMapsEveryKey(): void {
		$event = DecisionConcludedEvent::fromEnvelope(
			envelope: [
				'decisionId'        => 'd-1',
				'decisionType'      => 'contract',
				'status'            => 'approved',
				'signed'            => true,
				'signingReference'  => 'sig-9',
				'signers'           => ['alice', 'bob'],
				'decidedAt'         => '2026-11-12T10:00:00+00:00',
				'subjectRegister'   => 'crm',
				'subjectSchema'     => 'lead',
				'subjectId'         => 'l-3',
				'externalReference' => 'EXT-1',
				'outcome'           => 'ignored-from-envelope',
			],
			outcome: 'approved',
			sourceApp: 'pipelinq',
			correlationId: 'c-5',
		);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame(
			['d-1', 'contract', 'approved', 'approved', true, 'sig-9', ['alice', 'bob'], '2026-11-12T10:00:00+00:00', 'pipelinq', 'crm', 'lead', 'l-3', 'EXT-1', 'c-5'],
			[
				$event->getDecisionId(), $event->getDecisionType(), $event->getStatus(), $event->getOutcome(),
				$event->isSigned(), $event->getSigningReference(), $event->getSigners(), $event->getDecidedAt(),
				$event->getSourceApp(), $event->getSubjectRegister(), $event->getSubjectSchema(), $event->getSubjectId(),
				$event->getExternalReference(), $event->getCorrelationId(),
			]
		);
	}//end testFromEnvelopeMapsEveryKey()

	/**
	 * An empty envelope yields a pending, unsigned decision with null subject fields.
	 *
	 * @return void
	 */
	public function testFromEnvelopeDefaultsForAnEmptyEnvelope(): void {
		$event = DecisionConcludedEvent::fromEnvelope(envelope: [], outcome: 'withdrawn', sourceApp: 'shillinq');

		$this->assertSame('', $event->getDecisionId());
		$this->assertSame('', $event->getDecisionType());
		$this->assertSame('pending', $event->getStatus());
		$this->assertSame('withdrawn', $event->getOutcome());
		$this->assertFalse($event->isSigned());
		$this->assertNull($event->getSigningReference());
		$this->assertSame([], $event->getSigners());
		$this->assertNull($event->getDecidedAt());
		$this->assertNull($event->getSubjectRegister());
		$this->assertNull($event->getSubjectSchema());
		$this->assertNull($event->getSubjectId());
		$this->assertSame('', $event->getExternalReference());
		$this->assertSame('', $event->getCorrelationId());
	}//end testFromEnvelopeDefaultsForAnEmptyEnvelope()

	/**
	 * Loosely typed envelope values are cast: ids to string, signed to bool, a lone signer to a list.
	 *
	 * @return void
	 */
	public function testFromEnvelopeCastsLooseValues(): void {
		$event = DecisionConcludedEvent::fromEnvelope(
			envelope: ['decisionId' => 42, 'decisionType' => null, 'status' => null, 'signed' => 1, 'signers' => 'alice', 'externalReference' => 7],
			outcome: 'approved',
			sourceApp: 'a',
		);

		$this->assertSame('42', $event->getDecisionId());
		$this->assertSame('', $event->getDecisionType());
		$this->assertSame('pending', $event->getStatus());
		$this->assertTrue($event->isSigned());
		$this->assertSame(['alice'], $event->getSigners());
		$this->assertSame('7', $event->getExternalReference());
	}//end testFromEnvelopeCastsLooseValues()

	/**
	 * The constructor's optional reference and correlation default to empty strings.
	 *
	 * @return void
	 */
	public function testConstructorOptionalFieldsDefaultEmpty(): void {
		$event = new DecisionConcludedEvent(
			decisionId: 'd',
			decisionType: 't',
			status: 'rejected',
			outcome: 'rejected',
			signed: false,
			signingReference: null,
			signers: [],
			decidedAt: null,
			sourceApp: 'a',
			subjectRegister: null,
			subjectSchema: null,
			subjectId: null,
		);

		$this->assertSame('', $event->getExternalReference());
		$this->assertSame('', $event->getCorrelationId());
	}//end testConstructorOptionalFieldsDefaultEmpty()
}//end class
