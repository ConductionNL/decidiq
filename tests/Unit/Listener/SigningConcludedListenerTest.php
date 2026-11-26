<?php

/**
 * Unit tests for SigningConcludedListener.
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
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\SigningConcludedListener;
use OCA\Decidiq\Service\SigningConclusion;
use OCA\Filinq\Event\SigningConcludedEvent;
use OCA\Filinq\Event\SigningProvenance;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SigningConcludedListener.
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 */
class SigningConcludedListenerTest extends TestCase {

	/**
	 * The conclusion double.
	 *
	 * @var SigningConclusion&MockObject
	 */
	private SigningConclusion $conclusion;

	/**
	 * Set up the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->conclusion = $this->createMock(originalClassName: SigningConclusion::class);
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return SigningConcludedListener
	 */
	private function listener(): SigningConcludedListener {
		return new SigningConcludedListener(
			conclusion: $this->conclusion,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A conclusion event from filinq.
	 *
	 * @param string $status    The outcome
	 * @param string $sourceApp The app that asked
	 *
	 * @return SigningConcludedEvent
	 */
	private function event(string $status, string $sourceApp = 'decidiq'): SigningConcludedEvent {
		$signedDocumentRef = null;
		if ($status === 'signed') {
			$signedDocumentRef = 'file:913';
		}

		return new SigningConcludedEvent(
			signingRequestId: 'sr-9',
			status: $status,
			signedDocumentRef: $signedDocumentRef,
			signers: [],
			signedAt: '2026-11-26T09:00:00Z',
			provenance: new SigningProvenance(sourceApp: $sourceApp, subjectRegister: 'decidiq', subjectSchema: 'minutes', subjectId: 'min-1'),
		);
	}//end event()

	/**
	 * filinq's outcome for a decidiq request reaches the conclusion with the
	 * subject and the request it names.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-completed-filinq-signing-resolves-the-stage
	 *
	 * @return void
	 */
	public function testTheOutcomeReachesTheConclusion(): void {
		$this->conclusion->expects($this->once())->method('record')
			->with(
				'minutes',
				'min-1',
				['requestId' => 'sr-9', 'status' => 'signed', 'signedDocumentRef' => 'file:913', 'signedAt' => '2026-11-26T09:00:00Z']
			)
			->willReturn('signed');

		$this->listener()->handle(event: $this->event(status: 'signed'));
	}//end testTheOutcomeReachesTheConclusion()

	/**
	 * A declined request is handed on as declined.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-a-declined-signing-leaves-the-stage-open
	 *
	 * @return void
	 */
	public function testADeclinedRequestIsHandedOn(): void {
		$this->conclusion->expects($this->once())->method('record')
			->with('minutes', 'min-1', ['requestId' => 'sr-9', 'status' => 'declined', 'signedDocumentRef' => '', 'signedAt' => '2026-11-26T09:00:00Z'])
			->willReturn('failed');

		$this->listener()->handle(event: $this->event(status: 'declined'));
	}//end testADeclinedRequestIsHandedOn()

	/**
	 * Another app's signing request, or any other event, is ignored.
	 *
	 * @return void
	 */
	public function testOtherAppsAreIgnored(): void {
		$this->conclusion->expects($this->never())->method('record');

		$this->listener()->handle(event: $this->event(status: 'signed', sourceApp: 'shillinq'));
		$this->listener()->handle(event: new Event());
	}//end testOtherAppsAreIgnored()

	/**
	 * A failure in decidiq never reaches filinq's signing flow.
	 *
	 * @return void
	 */
	public function testAFailureIsContained(): void {
		$this->conclusion->method('record')->willThrowException(new \RuntimeException(message: 'database gone'));

		$this->listener()->handle(event: $this->event(status: 'signed'));

		$this->addToAssertionCount(count: 1);
	}//end testAFailureIsContained()
}//end class
