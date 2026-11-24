<?php

/**
 * Unit tests for ApprovalWithdrawalNotifier (REQ-DWP-003: the actor whose
 * approval was withdrawn is told).
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

use OCA\Decidiq\Service\ApprovalWithdrawalNotifier;
use OCA\Decidiq\Kernel\Contracts\NotificationPreferenceServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApprovalWithdrawalNotifier.
 */
final class ApprovalWithdrawalNotifierTest extends TestCase {
	/**
	 * The notices dispatched: personId, eventType, title, message, deepLink.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $sent = [];

	/**
	 * An actor whose notice throws.
	 *
	 * @var string
	 */
	private string $failFor = '';

	/**
	 * A notifier over a dispatcher that records, or throws for one actor.
	 *
	 * @param string $failFor An actor whose notice throws.
	 *
	 * @return ApprovalWithdrawalNotifier
	 */
	private function notifier(string $failFor = ''): ApprovalWithdrawalNotifier {
		$dispatcher = $this->createMock(originalClassName: NotificationPreferenceServiceInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message, string $deepLink = ''): int {
				if ($personId !== '' && $personId === $this->failFor) {
					throw new RuntimeException('mail down');
				}

				$this->sent[] = compact('personId', 'eventType', 'title', 'message', 'deepLink');
				return 1;
			}
		);
		$this->failFor = $failFor;

		return new ApprovalWithdrawalNotifier(notifications: $dispatcher, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end notifier()

	/**
	 * Each actor is told once, with the changed properties, the step and a link to the decision.
	 *
	 * @return void
	 */
	public function testEachActorIsToldOnceWithWhatChanged(): void {
		$notifier = $this->notifier();
		$notified = $notifier->notify(
			withdrawals: [
				['actor' => 'j.jansen', 'step' => 1, 'withdrawnPaths' => ['body']],
				['actor' => 'j.jansen', 'step' => 1, 'withdrawnPaths' => ['body', 'attachments']],
				['actor' => 'p.de.vries', 'step' => 2, 'withdrawnPaths' => ['attachments']],
				['step' => 3, 'withdrawnPaths' => ['body']],
			],
			deepLink: $notifier->deepLink(subject: 'dec-1', subjectSchema: 'decision'),
		);

		self::assertSame(expected: 2, actual: $notified);
		self::assertSame(expected: ['j.jansen', 'p.de.vries'], actual: array_column($this->sent, 'personId'));
		self::assertSame(expected: 'taskAssigned', actual: $this->sent[0]['eventType']);
		self::assertSame(expected: 'Your approval was withdrawn', actual: $this->sent[0]['title']);
		self::assertSame(
			expected: 'What you approved was changed (body, attachments). Step 1 waits for your approval again.',
			actual: $this->sent[0]['message']
		);
		self::assertSame(expected: '/decisions/dec-1', actual: $this->sent[0]['deepLink']);
	}//end testEachActorIsToldOnceWithWhatChanged()

	/**
	 * A notice that fails for one actor still reaches the others.
	 *
	 * @return void
	 */
	public function testANoticeThatFailsForOneActorStillReachesTheOthers(): void {
		$notifier = $this->notifier(failFor: 'j.jansen');
		$notified = $notifier->notify(
			withdrawals: [
				['actor' => 'j.jansen', 'step' => 1],
				['actor' => 'p.de.vries', 'step' => 1],
			],
			deepLink: $notifier->deepLink(subject: 'doc-1', subjectSchema: 'digital-document'),
		);

		self::assertSame(expected: 1, actual: $notified);
		self::assertSame(expected: ['p.de.vries'], actual: array_column($this->sent, 'personId'));
		self::assertSame(expected: '', actual: $this->sent[0]['deepLink'], message: 'decidiq has no page for a host app object');
	}//end testANoticeThatFailsForOneActorStillReachesTheOthers()

	/**
	 * No withdrawals send nothing.
	 *
	 * @return void
	 */
	public function testNoWithdrawalsSendNothing(): void {
		self::assertSame(expected: 0, actual: $this->notifier()->notify(withdrawals: [], deepLink: '/decisions/dec-1'));
		self::assertSame(expected: [], actual: $this->sent);
	}//end testNoWithdrawalsSendNothing()

	/**
	 * The message names the step only when there is one.
	 *
	 * @return void
	 */
	public function testTheMessage(): void {
		self::assertSame(
			expected: 'What you approved was changed. The step waits for your approval again.',
			actual: ApprovalWithdrawalNotifier::message(steps: [1, 2], paths: [])
		);
		self::assertSame(
			expected: ['j.jansen' => ['steps' => [1, 2], 'paths' => ['body']]],
			actual: ApprovalWithdrawalNotifier::noticesPerActor(withdrawals: [
				['actor' => 'j.jansen', 'step' => 1, 'withdrawnPaths' => ['body']],
				['actor' => 'j.jansen', 'step' => 2, 'withdrawnPaths' => ['body']],
			])
		);
	}//end testTheMessage()
}//end class
