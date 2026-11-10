<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Email-vote replies notify the voter through the Nextcloud notification manager.
 *
 * OpenRegister's NotificationService has no generic createNotification(); the
 * processor used to look it up by class-name string inside a catch-all, so
 * every confirmation, reprompt and abandon notice was dropped without a trace.
 * These tests fail on that old lookup.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MailVoteReplyProcessor;
use OCA\Decidiq\Service\MailVoteSigner;
use OCA\Decidiq\Service\VotingService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Covers the notification sent for an unreadable email-vote reply.
 */
class MailVoteReplyNotificationTest extends TestCase {

	/**
	 * Run one unreadable reply through the processor and return the notification it sent.
	 *
	 * @param int $retries The retries already recorded on the entry
	 *
	 * @return array{notification: INotification&\PHPUnit\Framework\MockObject\MockObject, sent: int}
	 */
	private function process(int $retries): array {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$sent    = 0;
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->method('notify')->willReturnCallback(
			static function () use (&$sent): void {
				$sent++;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?object => ($id === IManager::class) ? $manager : null
		);

		$signer = $this->createMock(MailVoteSigner::class);
		$signer->method('verify')->willReturn(true);

		$processor = new MailVoteReplyProcessor(
			votingService: $this->getMockBuilder(VotingService::class)->disableOriginalConstructor()->getMock(),
			signer: $signer,
			container: $container,
			logger: new NullLogger(),
		);

		$objectService = new class {
			/**
			 * @param mixed ...$args The find arguments, in any shape.
			 *
			 * @return object The participant
			 */
			public function find(mixed ...$args): object {
				return new class {
					/**
					 * @return array<string, string>
					 */
					public function jsonSerialize(): array {
						return ['nextcloudUserId' => 'uid-7'];
					}
				};
			}

			/**
			 * @param mixed ...$args The save arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed ...$args): array {
				return [];
			}
		};

		$processor->processRound(
			objectService: $objectService,
			round: ['_mail' => [['participantId' => 'p-1', 'replyBody' => 'maybe later', 'retries' => $retries]]],
			roundId: 'round-1'
		);

		return ['notification' => $notification, 'sent' => $sent];
	}//end process()

	public function testAnUnreadableReplyRepromptsTheVoter(): void {
		$result = $this->process(retries: 0);

		self::assertSame(1, $result['sent']);
	}//end testAnUnreadableReplyRepromptsTheVoter()

	public function testTheNotificationCarriesTheDecidiqSubjectAndRound(): void {
		$notification = $this->createMock(INotification::class);
		$notification->expects(self::once())->method('setApp')->with('decidiq')->willReturnSelf();
		$notification->expects(self::once())->method('setUser')->with('uid-7')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->expects(self::once())->method('setObject')->with('voting-round', 'round-1')->willReturnSelf();
		$notification->expects(self::once())->method('setSubject')
			->with('email_vote_abandoned', ['votingRoundId' => 'round-1'])
			->willReturnSelf();

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects(self::once())->method('notify')->with($notification);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?object => ($id === IManager::class) ? $manager : null
		);

		$signer = $this->createMock(MailVoteSigner::class);
		$signer->method('verify')->willReturn(true);

		$processor = new MailVoteReplyProcessor(
			votingService: $this->getMockBuilder(VotingService::class)->disableOriginalConstructor()->getMock(),
			signer: $signer,
			container: $container,
			logger: new NullLogger(),
		);

		$notify = new \ReflectionMethod($processor, 'notify');
		$notify->invoke($processor, 'uid-7', 'email_vote_abandoned', ['votingRoundId' => 'round-1'], 'failed', 'round-1');
	}//end testTheNotificationCarriesTheDecidiqSubjectAndRound()
}//end class
