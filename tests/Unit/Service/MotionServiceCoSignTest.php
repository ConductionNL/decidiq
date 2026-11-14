<?php

/**
 * Unit tests for MotionService co-signature, budget impact and delegation seams.
 *
 * Covers the paths MotionServiceTest leaves untouched: inviting co-signers
 * (UID resolution by stored id or unique email, notification, persistence of
 * the pending signer list), the pending co-signer check, the budget impact
 * note, the motion lookup refusal, and the thin delegations to the link
 * resolver, the amendment service and the forwarding service.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MotionLinkResolver;
use OCA\Decidiq\Service\MotionNotifier;
use OCA\Decidiq\Service\MotionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MotionService co-signature and budget impact handling.
 *
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-1.1
 */
class MotionServiceCoSignTest extends TestCase {

	/**
	 * Captured saveObject() calls, in order.
	 *
	 * @var \ArrayObject<int, array{uuid: string|null, object: array<string, mixed>, register: mixed, schema: mixed}>
	 */
	private \ArrayObject $saves;

	/**
	 * The mocked notifier handed out by the container.
	 *
	 * @var MotionNotifier&MockObject
	 */
	private MotionNotifier $notifier;

	/**
	 * The mocked link resolver handed out by the container.
	 *
	 * @var MotionLinkResolver&MockObject
	 */
	private MotionLinkResolver $linkResolver;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The mocked user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * Build a MotionService over an in-memory object store.
	 *
	 * @param array<string, array<string, mixed>> $store Seed objects keyed by id
	 * @param array<string, \Throwable> $failing Ids whose find() throws
	 *
	 * @return MotionService
	 */
	private function buildService(array $store, array $failing = []): MotionService {
		$this->saves = new \ArrayObject();
		$saves = $this->saves;

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($store, $failing): ?ObjectEntity {
				if (isset($failing[(string)$id]) === true) {
					throw $failing[(string)$id];
				}

				return isset($store[(string)$id]) === true ? $this->entity($store[(string)$id]) : null;
			}
		);
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($saves): ObjectEntity {
				$saves->append(['uuid' => $uuid, 'object' => $object, 'register' => $register, 'schema' => $schema]);
				return $this->entity($object);
			}
		);

		$this->notifier = $this->createMock(MotionNotifier::class);
		$this->linkResolver = $this->createMock(MotionLinkResolver::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$notifier = $this->notifier;
		$linkResolver = $this->linkResolver;
		$appConfig = $this->createMock(IAppConfig::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				MotionNotifier::class => $notifier,
				MotionLinkResolver::class => $linkResolver,
				IAppConfig::class => $appConfig,
				default => throw new RuntimeException('not wired in test: ' . $id),
			}
		);

		return new MotionService(
			container: $container,
			logger: $this->logger,
			userManager: $this->userManager,
			objectService: $objectService,
		);

	}//end buildService()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it verbatim.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * A motion-typed decision.
	 *
	 * @param array<string, mixed> $fields Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function motion(array $fields = []): array {
		return array_merge(
			['id' => 'motion-uuid', 'decisionType' => 'motion', 'title' => 'Test Motie'],
			$fields
		);

	}//end motion()

	/**
	 * A user double with the given UID.
	 *
	 * @param string $uid The UID
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()

	/**
	 * Invitations resolve UIDs by stored id or unique email, notify each and persist the merged pending list.
	 *
	 * @return void
	 */
	public function testRequestCoSignatureNotifiesResolvedParticipantsAndPersistsPendingUids(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(['pendingCoSignerUids' => ['earlier', 'alice']]),
				'p-uid' => ['nextcloudUserId' => 'alice'],
				'p-mail' => ['email' => 'bob@example.org'],
				'p-ambiguous' => ['email' => 'shared@example.org'],
				'p-nothing' => ['name' => 'No link'],
			]
		);
		$this->userManager->method('getByEmail')->willReturnMap(
			[
				['bob@example.org', [$this->user('bob')]],
				['shared@example.org', [$this->user('x'), $this->user('y')]],
			]
		);

		$notified = [];
		$this->notifier->expects($this->exactly(2))->method('notify')->willReturnCallback(
			static function (string $userId, string $motionId, string $subject, array $parameters, string $failureLog) use (&$notified): void {
				$notified[] = [$userId, $motionId, $subject, $parameters, $failureLog];
			}
		);

		$service->requestCoSignature('motion-uuid', ['p-uid', 'p-missing', 'p-mail', 'p-ambiguous', 'p-nothing']);

		self::assertSame(
			[
				[
					'alice', 'motion-uuid', 'co_sign_request',
					['motionTitle' => 'Test Motie', 'motionId' => 'motion-uuid'],
					'Decidiq: Could not send co-sign notification to alice: ',
				],
				[
					'bob', 'motion-uuid', 'co_sign_request',
					['motionTitle' => 'Test Motie', 'motionId' => 'motion-uuid'],
					'Decidiq: Could not send co-sign notification to bob: ',
				],
			],
			$notified
		);
		self::assertCount(1, $this->saves);
		self::assertSame('motion-uuid', $this->saves[0]['uuid']);
		self::assertSame('decidiq', $this->saves[0]['register']);
		self::assertSame('decision', $this->saves[0]['schema']);
		self::assertSame(['earlier', 'alice', 'bob'], $this->saves[0]['object']['pendingCoSignerUids']);
		self::assertSame('Test Motie', $this->saves[0]['object']['title']);

	}//end testRequestCoSignatureNotifiesResolvedParticipantsAndPersistsPendingUids()

	/**
	 * A failing participant lookup is logged and skipped; with nobody resolved nothing is saved.
	 *
	 * @return void
	 */
	public function testRequestCoSignatureLogsFailuresAndSavesNothingWhenNoneResolved(): void {
		$service = $this->buildService(
			['motion-uuid' => self::motion(['title' => null])],
			['p-broken' => new RuntimeException('db down')]
		);
		$this->notifier->expects($this->never())->method('notify');
		$this->logger->expects($this->once())->method('warning')
			->with('Decidiq: Could not send co-sign request to participant p-broken: db down');

		$service->requestCoSignature('motion-uuid', ['p-broken']);

		self::assertCount(0, $this->saves);

	}//end testRequestCoSignatureLogsFailuresAndSavesNothingWhenNoneResolved()

	/**
	 * Without a stored title the notification falls back to "Motie".
	 *
	 * @return void
	 */
	public function testRequestCoSignatureFallsBackToDefaultTitle(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => ['id' => 'motion-uuid', 'decisionType' => 'motion'],
				'p-uid' => ['nextcloudUserId' => 42],
			]
		);
		$this->notifier->expects($this->once())->method('notify')->with(
			'42',
			'motion-uuid',
			'co_sign_request',
			['motionTitle' => 'Motie', 'motionId' => 'motion-uuid'],
			'Decidiq: Could not send co-sign notification to 42: '
		);

		$service->requestCoSignature('motion-uuid', ['p-uid']);

		self::assertSame(['42'], $this->saves[0]['object']['pendingCoSignerUids']);

	}//end testRequestCoSignatureFallsBackToDefaultTitle()

	/**
	 * Inviting co-signers on something that is not a motion is refused.
	 *
	 * @return void
	 */
	public function testRequestCoSignatureRefusesNonMotion(): void {
		$service = $this->buildService(['amend-1' => ['id' => 'amend-1', 'decisionType' => 'amendment']]);
		$this->notifier->expects($this->never())->method('notify');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion amend-1 not found');

		$service->requestCoSignature('amend-1', ['p-1']);

	}//end testRequestCoSignatureRefusesNonMotion()

	/**
	 * isPendingCoSigner is true only for an invited UID on an existing motion.
	 *
	 * @return void
	 */
	public function testIsPendingCoSigner(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(['pendingCoSignerUids' => ['alice']]),
				'bare-motion' => ['decisionType' => 'motion'],
				'amend-1' => ['decisionType' => 'amendment', 'pendingCoSignerUids' => ['alice']],
			]
		);

		self::assertTrue($service->isPendingCoSigner('motion-uuid', 'alice'));
		self::assertFalse($service->isPendingCoSigner('motion-uuid', 'bob'));
		self::assertFalse($service->isPendingCoSigner('bare-motion', 'alice'));
		self::assertFalse($service->isPendingCoSigner('amend-1', 'alice'));
		self::assertFalse($service->isPendingCoSigner('missing', 'alice'));

	}//end testIsPendingCoSigner()

	/**
	 * A first budget impact is appended as a JSON-bodied note after existing notes.
	 *
	 * @return void
	 */
	public function testSaveBudgetImpactAppendsNote(): void {
		$service = $this->buildService(['motion-uuid' => self::motion(['notes' => [['title' => 'Other', 'body' => 'x']]])]);

		$service->saveBudgetImpact('motion-uuid', 'Programma 4', -1250.5, 'Bezuiniging');

		self::assertCount(1, $this->saves);
		self::assertSame('motion-uuid', $this->saves[0]['uuid']);
		self::assertSame(
			[
				['title' => 'Other', 'body' => 'x'],
				[
					'title' => 'Budget impact',
					'body' => '{"budgetLine":"Programma 4","amountDelta":-1250.5,"rationale":"Bezuiniging"}',
				],
			],
			$this->saves[0]['object']['notes']
		);

	}//end testSaveBudgetImpactAppendsNote()

	/**
	 * An existing budget impact note is replaced in place, not duplicated.
	 *
	 * @return void
	 */
	public function testSaveBudgetImpactReplacesExistingNote(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(
					['notes' => [['body' => 'untitled'], ['title' => 'Budget impact', 'body' => 'old'], ['title' => 'Tail']]]
				),
			]
		);

		$service->saveBudgetImpact('motion-uuid', 'L1', 10.25, 'R');

		self::assertSame(
			[
				['body' => 'untitled'],
				['title' => 'Budget impact', 'body' => '{"budgetLine":"L1","amountDelta":10.25,"rationale":"R"}'],
				['title' => 'Tail'],
			],
			$this->saves[0]['object']['notes']
		);

	}//end testSaveBudgetImpactReplacesExistingNote()

	/**
	 * A budget impact on a missing motion is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testSaveBudgetImpactRefusesMissingMotion(): void {
		$service = $this->buildService([]);

		try {
			$service->saveBudgetImpact('gone', 'L', 1.0, 'R');
			self::fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			self::assertSame('Motion gone not found', $e->getMessage());
		}

		self::assertCount(0, $this->saves);

	}//end testSaveBudgetImpactRefusesMissingMotion()

	/**
	 * An amount JSON cannot encode (NAN) is refused with the encoder's reason, and nothing is saved.
	 *
	 * @return void
	 */
	public function testSaveBudgetImpactRefusesUnencodableAmount(): void {
		$service = $this->buildService(['motion-uuid' => self::motion()]);

		try {
			$service->saveBudgetImpact('motion-uuid', 'L', NAN, 'R');
			self::fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			self::assertSame('JSON encoding of budget impact failed: Inf and NaN cannot be JSON encoded', $e->getMessage());
		}

		self::assertCount(0, $this->saves);

	}//end testSaveBudgetImpactRefusesUnencodableAmount()

	/**
	 * resolveMeetingId delegates to the link resolver and returns its answer.
	 *
	 * @return void
	 */
	public function testResolveMeetingIdDelegatesToLinkResolver(): void {
		$service = $this->buildService([]);
		$this->linkResolver->expects($this->once())->method('resolveMeetingId')
			->with('motion-uuid')->willReturn('meeting-7');

		self::assertSame('meeting-7', $service->resolveMeetingId('motion-uuid'));

	}//end testResolveMeetingIdDelegatesToLinkResolver()

	/**
	 * A motion without amendments yields an empty list.
	 *
	 * @return void
	 */
	public function testGetAmendmentsForMotionWithoutAmendments(): void {
		$service = $this->buildService(['motion-uuid' => self::motion()]);

		self::assertSame([], $service->getAmendmentsForMotion('motion-uuid'));

	}//end testGetAmendmentsForMotionWithoutAmendments()

	/**
	 * Forwarding by an unknown actor is refused before the motion is touched.
	 *
	 * @return void
	 */
	public function testForwardMotionRefusesUnknownActor(): void {
		$service = $this->buildService(['motion-uuid' => self::motion()]);
		$this->userManager->expects($this->once())->method('get')->with('ghost')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Actor ghost not found');

		$service->forwardMotion('motion-uuid', 'body-2', 'ghost', 'Ter info');

	}//end testForwardMotionRefusesUnknownActor()
}//end class
