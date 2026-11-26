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

use OCA\Decidiq\Kernel\Contracts\SigningRoundServiceInterface;
use OCA\Decidiq\Listener\SigningConcludedListener;
use OCA\Decidiq\Service\EIDASSignatureService;
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
	 * The round service double.
	 *
	 * @var SigningRoundServiceInterface&MockObject
	 */
	private SigningRoundServiceInterface $rounds;

	/**
	 * The signature service double.
	 *
	 * @var EIDASSignatureService&MockObject
	 */
	private EIDASSignatureService $signatures;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rounds     = $this->createMock(SigningRoundServiceInterface::class);
		$this->signatures = $this->createMock(EIDASSignatureService::class);
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return SigningConcludedListener
	 */
	private function listener(): SigningConcludedListener {
		return new SigningConcludedListener(
			rounds: $this->rounds,
			signatures: $this->signatures,
			logger: $this->createMock(LoggerInterface::class)
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
		return new SigningConcludedEvent(
			signingRequestId: 'sr-9',
			status: $status,
			signedDocumentRef: ($status === 'signed' ? 'file:913' : null),
			signers: [],
			signedAt: '2026-11-26T09:00:00Z',
			provenance: new SigningProvenance(sourceApp: $sourceApp, subjectRegister: 'decidiq', subjectSchema: 'minutes', subjectId: 'min-1'),
		);
	}//end event()

	/**
	 * Signed: the record links the signed document and the stages resolve on
	 * the request id.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-completed-filinq-signing-resolves-the-stage
	 *
	 * @return void
	 */
	public function testSignedResolvesTheStage(): void {
		$this->rounds->expects($this->once())->method('conclude')
			->with(
				'minutes',
				'min-1',
				['requestId' => 'sr-9', 'status' => 'signed', 'signedDocumentRef' => 'file:913', 'signedAt' => '2026-11-26T09:00:00Z']
			)
			->willReturn(['status' => 'signed', 'message' => 'ok']);
		$this->signatures->expects($this->once())->method('resolveSignatureStage')->with('min-1', 'sr-9');
		$this->signatures->expects($this->never())->method('recordUnsignedOutcome');

		$this->listener()->handle($this->event(status: 'signed'));
	}//end testSignedResolvesTheStage()

	/**
	 * Declined, expired and cancelled leave the stage unresolved with the reason.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-a-declined-signing-leaves-the-stage-open
	 *
	 * @return void
	 */
	public function testUnsignedOutcomesLeaveTheStageOpen(): void {
		$this->rounds->method('conclude')->willReturn(['status' => 'failed', 'message' => 'ok']);
		$this->signatures->expects($this->never())->method('resolveSignatureStage');
		$this->signatures->expects($this->exactly(3))->method('recordUnsignedOutcome')
			->willReturnCallback(
				function (string $signingReference, string $outcome): int {
					$this->assertSame('sr-9', $signingReference);
					$this->assertContains($outcome, ['declined', 'expired', 'cancelled']);
					return 1;
				}
			);

		foreach (['declined', 'expired', 'cancelled'] as $status) {
			$this->listener()->handle($this->event(status: $status));
		}
	}//end testUnsignedOutcomesLeaveTheStageOpen()

	/**
	 * Another app's signing request is none of decidiq's business.
	 *
	 * @return void
	 */
	public function testOtherAppsAreIgnored(): void {
		$this->rounds->expects($this->never())->method('conclude');
		$this->signatures->expects($this->never())->method('resolveSignatureStage');

		$this->listener()->handle($this->event(status: 'signed', sourceApp: 'shillinq'));
		$this->listener()->handle(new Event());
	}//end testOtherAppsAreIgnored()

	/**
	 * An answer for a request the record is not out on touches no stage.
	 *
	 * @return void
	 */
	public function testUnmatchedRequestTouchesNoStage(): void {
		$this->rounds->method('conclude')->willReturn(['status' => 'ignored', 'message' => 'The record is not out on this signing request.']);
		$this->signatures->expects($this->never())->method('resolveSignatureStage');
		$this->signatures->expects($this->never())->method('recordUnsignedOutcome');

		$this->listener()->handle($this->event(status: 'signed'));
	}//end testUnmatchedRequestTouchesNoStage()

	/**
	 * A failure in decidiq never reaches filinq's signing flow.
	 *
	 * @return void
	 */
	public function testFailureIsContained(): void {
		$this->rounds->method('conclude')->willThrowException(new \RuntimeException('database gone'));

		$this->listener()->handle($this->event(status: 'signed'));

		$this->addToAssertionCount(1);
	}//end testFailureIsContained()
}//end class
