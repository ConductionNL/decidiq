<?php

/**
 * The griffie names a portal reader on a confidential item by BSN or KvK
 * number; portaliq provisions the account and writes the claim, decidiq keeps
 * only the portal reference (portal-voting-record-and-confidential-papers task 3).
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
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\ConfidentialReaderRefusedException;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Service\ConfidentialReaders;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Portaliq\Event\PortalAccountClaimRequestedEvent;
use OCA\Portaliq\Event\PortalAccountProvisionRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConfidentialReaders.
 *
 * @covers \OCA\Decidiq\Service\ConfidentialReaders
 * @covers \OCA\Decidiq\Exception\ConfidentialReaderRefusedException
 */
class ConfidentialReadersTest extends TestCase {

	/**
	 * A BSN that passes the elfproef.
	 */
	private const BSN = '111222333';

	/**
	 * The objects the service saved, per schema.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The events the service sent.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Lines the logger received, to prove no number is in any of them.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The service for a signed-in user over a fixed set of rows.
	 *
	 * @param array<int, string> $groups   The user's groups.
	 * @param bool               $chair    Whether the user chairs the item's meeting.
	 * @param bool               $public   Whether the item is public.
	 * @param string             $provided The subject reference portaliq answers, '' for no answer.
	 * @param string             $claimed  The claim result portaliq answers.
	 *
	 * @return ConfidentialReaders
	 */
	private function readers(array $groups=['decidiq-secretariat'], bool $chair=false, bool $public=false, string $provided='portal-1', string $claimed='ok'): ConfidentialReaders {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));
		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('hasRole')->willReturnCallback(
			static fn (string $meetingId, string $nextcloudUid, array $roles): bool => $chair === true && $meetingId === 'raad-2025-04-10' && $roles === ['chair', 'secretary']
		);

		$rows = [
			'agenda-item' => [
				['id' => 'item-1', 'title' => 'Grondaankoop Lindelaan', 'meeting' => 'raad-2025-04-10', 'public' => $public, 'authorisedPersons' => []],
			],
			'person' => [['id' => 'pieter-bos', 'name' => 'Pieter Bos']],
		];
		$objects = $this->getMockBuilder(ObjectServiceInterface::class)->getMock();
		$objects->method('findAll')->willReturnCallback(static fn (array $config=[]): array => ($rows[$config['filters']['schema'] ?? ''] ?? []));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, $options=null): ObjectEntityInterface {
				$this->saved[] = ['schema' => $options->target->schema, 'object' => $object];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($provided, $claimed): void {
				$this->sent[] = $event;
				if ($event instanceof PortalAccountProvisionRequestedEvent && $provided !== '') {
					$event->answer($provided, 'pending');
				}

				if ($event instanceof PortalAccountClaimRequestedEvent && $claimed !== '') {
					$event->answer($claimed);
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]): void {
					$this->logged[] = $message . json_encode($context);
				}
			);
		}

		return new ConfidentialReaders(
			objectService: $objects,
			userSession: $session,
			groupManager: $groupManager,
			participants: $participants,
			dispatcher: $dispatcher,
			logger: $logger
		);
	}//end readers()

	/**
	 * The status a call is refused with.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{status: int, message: string}
	 */
	private static function refusal(callable $call): array {
		try {
			$call();
		} catch (ConfidentialReaderRefusedException $e) {
			return ['status' => $e->getStatus(), 'message' => $e->getMessage()];
		}

		self::fail('The call was not refused.');
	}//end refusal()

	/**
	 * The griffier adds Pieter Bos: the provision and claim events are sent,
	 * `portalAccountRef` and `authorisedPersons` are set, and no BSN is stored.
	 *
	 * @return void
	 */
	public function testTheGriffierAddsAReaderAndOnlyThePortalReferenceIsKept(): void {
		$result = $this->readers()->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: '111 222 333');

		self::assertSame(['authorisedPersons' => ['pieter-bos'], 'person' => 'pieter-bos'], $result);
		self::assertCount(2, $this->sent);
		self::assertInstanceOf(PortalAccountProvisionRequestedEvent::class, $this->sent[0]);
		self::assertSame('decidiq', $this->sent[0]->getAppId());
		self::assertSame('digid', $this->sent[0]->getIdentityType());
		self::assertSame(self::BSN, $this->sent[0]->getIdentityRef());
		self::assertInstanceOf(PortalAccountClaimRequestedEvent::class, $this->sent[1]);
		self::assertSame('portal-1', $this->sent[1]->getSubjectRef());
		self::assertSame('personId', $this->sent[1]->getClaimName());
		self::assertSame('pieter-bos', $this->sent[1]->getValue());

		self::assertSame(['person', 'agenda-item'], array_column($this->saved, 'schema'));
		self::assertSame('portal-1', $this->saved[0]['object']['portalAccountRef']);
		self::assertSame(['pieter-bos'], $this->saved[1]['object']['authorisedPersons']);
		$stored = json_encode($this->saved) . implode('', $this->logged);
		self::assertStringNotContainsString(self::BSN, $stored);
	}//end testTheGriffierAddsAReaderAndOnlyThePortalReferenceIsKept()

	/**
	 * A KvK number provisions an eHerkenning account.
	 *
	 * @return void
	 */
	public function testAKvkNumberAsksForAnEherkenningAccount(): void {
		$this->readers()->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'kvk', number: '12345678');

		self::assertSame('eherkenning', $this->sent[0]->getIdentityType());
		self::assertStringNotContainsString('12345678', json_encode($this->saved));
	}//end testAKvkNumberAsksForAnEherkenningAccount()

	/**
	 * The chair of the item's meeting may add a reader without the secretariat group.
	 *
	 * @return void
	 */
	public function testTheChairOfTheMeetingMayAddAReader(): void {
		$result = $this->readers(groups: [], chair: true)->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: self::BSN);

		self::assertSame(['pieter-bos'], $result['authorisedPersons']);
	}//end testTheChairOfTheMeetingMayAddAReader()

	/**
	 * An invalid BSN and an invalid KvK number are 422 and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnInvalidNumberIsRefusedWithItsReason(): void {
		$readers = $this->readers();

		self::assertSame(
			['status' => 422, 'message' => 'This is not a valid BSN.'],
			self::refusal(fn () => $readers->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: '111222334'))
		);
		self::assertSame(
			['status' => 422, 'message' => 'This is not a valid KvK number.'],
			self::refusal(fn () => $readers->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'kvk', number: '1234567'))
		);
		self::assertSame([], $this->sent);
		self::assertSame([], $this->saved);
	}//end testAnInvalidNumberIsRefusedWithItsReason()

	/**
	 * A reader on a public item is 409 "Make the item confidential first".
	 *
	 * @return void
	 */
	public function testAPublicItemTakesNoReader(): void {
		$readers = $this->readers(public: true);

		self::assertSame(
			['status' => 409, 'message' => 'Make the item confidential first.'],
			self::refusal(fn () => $readers->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: self::BSN))
		);
		self::assertSame([], $this->saved);
	}//end testAPublicItemTakesNoReader()

	/**
	 * A council member without the secretariat group or a chair or secretary role is 403.
	 *
	 * @return void
	 */
	public function testACouncilMemberCannotAddOrRemoveAReader(): void {
		$readers = $this->readers(groups: ['decidiq-members']);

		self::assertSame(403, self::refusal(fn () => $readers->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: self::BSN))['status']);
		self::assertSame(403, self::refusal(fn () => $readers->remove(itemId: 'item-1', personId: 'pieter-bos'))['status']);
		self::assertSame([], $this->sent);
		self::assertSame([], $this->saved);
	}//end testACouncilMemberCannotAddOrRemoveAReader()

	/**
	 * When no listener answers, the request is 409 naming portaliq and nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutAnAnswerFromPortaliqNothingIsWritten(): void {
		$readers = $this->readers(provided: '');

		$refusal = self::refusal(fn () => $readers->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: self::BSN));
		self::assertSame(409, $refusal['status']);
		self::assertStringContainsString('Portaliq', $refusal['message']);
		self::assertSame([], $this->saved);
	}//end testWithoutAnAnswerFromPortaliqNothingIsWritten()

	/**
	 * A claim portaliq does not confirm is 409 and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnconfirmedClaimWritesNothing(): void {
		$readers = $this->readers(claimed: 'refused');

		self::assertSame(409, self::refusal(fn () => $readers->add(itemId: 'item-1', personId: 'pieter-bos', identityType: 'bsn', number: self::BSN))['status']);
		self::assertSame([], $this->saved);
	}//end testAnUnconfirmedClaimWritesNothing()

	/**
	 * An unknown item is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownItemIsNotFound(): void {
		$readers = $this->readers();

		self::assertSame(404, self::refusal(fn () => $readers->add(itemId: 'nope', personId: 'pieter-bos', identityType: 'bsn', number: self::BSN))['status']);
	}//end testAnUnknownItemIsNotFound()

	/**
	 * The elfproef and the KvK form.
	 *
	 * @return void
	 */
	public function testTheNumberChecks(): void {
		self::assertTrue(ConfidentialReaders::validBsn(bsn: self::BSN));
		self::assertTrue(ConfidentialReaders::validBsn(bsn: '123456782'));
		self::assertFalse(ConfidentialReaders::validBsn(bsn: '123456789'));
		self::assertFalse(ConfidentialReaders::validBsn(bsn: '000000000'));
		self::assertFalse(ConfidentialReaders::validBsn(bsn: '12345678'));
		self::assertTrue(ConfidentialReaders::validKvk(kvk: '12345678'));
		self::assertFalse(ConfidentialReaders::validKvk(kvk: '1234567a'));
	}//end testTheNumberChecks()
}//end class
