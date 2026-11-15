<?php

/**
 * Edge-case unit tests for ProxyDelegationService: revoking a proxy, notifying
 * the delegate, and the fail-soft paths around both.
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
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the revoke flow and the delegate notification the base test never reaches.
 *
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
 */
#[CoversClass(ProxyDelegationService::class)]
class ProxyDelegationServiceEdgesTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Mock notification manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $notificationManager;

	/**
	 * Container ids requested, in order.
	 *
	 * @var string[]
	 */
	private array $resolved = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationManager = $this->createMock(IManager::class);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return ProxyDelegationService
	 */
	private function makeService(): ProxyDelegationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->resolved[] = $id;
				return match ($id) {
					IUserManager::class => $this->userManager,
					IManager::class => $this->notificationManager,
				};
			}
		);

		return new ProxyDelegationService(
			container: $container,
			logger: $this->logger,
			objectService: $this->objectService,
			appConfig: $this->appConfig,
		);

	}//end makeService()

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;

	}//end entity()

	/**
	 * Route find() to the delegate participant or the round by schema.
	 *
	 * @param array<string, mixed>|null $delegate The participant payload, or null when absent.
	 * @param array<string, mixed>      $round    The round payload.
	 *
	 * @return void
	 */
	private function stubFind(?array $delegate, array $round = ['id' => 'round-uuid', 'notes' => []]): void {
		$roundEntity = $this->entity($round);
		$delegateEntity = null;
		if ($delegate !== null) {
			$delegateEntity = $this->entity($delegate);
		}

		$this->objectService->method('find')->willReturnCallback(
			static function (mixed $id, mixed $extend, mixed $files, mixed $register, mixed $schema) use ($roundEntity, $delegateEntity): ?ObjectEntity {
				if ($schema === 'voting-round') {
					return $roundEntity;
				}

				return $delegateEntity;
			}
		);

	}//end stubFind()

	/**
	 * A proxy note body.
	 *
	 * @param string $from Grantor.
	 * @param string $to   Holder.
	 *
	 * @return array<string, string>
	 */
	private function proxyNote(string $from, string $to): array {
		return [
			'title' => 'Proxy',
			'body' => json_encode(['fromParticipantId' => $from, 'toParticipantId' => $to, 'votingRoundId' => 'round-uuid']),
		];

	}//end proxyNote()

	/**
	 * Expect exactly one proxy_granted notification for the given user.
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return INotification&MockObject
	 */
	private function expectNotificationFor(string $uid): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->expects($this->once())->method('setApp')->with('decidiq')->willReturnSelf();
		$notification->expects($this->once())->method('setUser')->with($uid)->willReturnSelf();
		$notification->expects($this->once())->method('setDateTime')->with($this->isInstanceOf(\DateTime::class))->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('voting-round', 'round-uuid')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with('proxy_granted', ['from' => 'granter-uuid', 'votingRoundId' => 'round-uuid'])
			->willReturnSelf();
		$this->notificationManager->expects($this->once())->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify')->with($notification);

		return $notification;

	}//end expectNotificationFor()

	/**
	 * A delegate stored with a Nextcloud user id is notified directly, without an email lookup.
	 *
	 * @return void
	 */
	public function testGrantNotifiesTheDelegateByStoredUserId(): void {
		$this->stubFind(delegate: ['role' => 'Member', 'nextcloudUserId' => 'nc-bob', 'email' => 'bob@example.org']);
		$this->objectService->expects($this->once())->method('saveObject');
		$this->userManager->expects($this->never())->method('getByEmail');
		$this->expectNotificationFor(uid: 'nc-bob');

		$this->makeService()->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid', toParticipantId: 'delegate-uuid');

		self::assertSame([IManager::class], $this->resolved);

	}//end testGrantNotifiesTheDelegateByStoredUserId()

	/**
	 * Without a stored user id, a unique account with the delegate's email is notified.
	 *
	 * @return void
	 */
	public function testGrantNotifiesTheDelegateFoundByEmail(): void {
		$this->stubFind(delegate: ['role' => 'member', 'email' => 'bob@example.org']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->userManager->expects($this->once())->method('getByEmail')->with('bob@example.org')->willReturn([$user]);
		$this->expectNotificationFor(uid: 'bob');

		$this->makeService()->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid', toParticipantId: 'delegate-uuid');

		self::assertSame([IUserManager::class, IManager::class], $this->resolved);

	}//end testGrantNotifiesTheDelegateFoundByEmail()

	/**
	 * An email shared by several accounts identifies nobody, so nobody is notified.
	 *
	 * @return void
	 */
	public function testAnAmbiguousEmailNotifiesNobody(): void {
		$this->stubFind(delegate: ['role' => 'member', 'email' => 'shared@example.org']);
		$this->objectService->expects($this->once())->method('saveObject');
		$this->userManager->expects($this->once())->method('getByEmail')->with('shared@example.org')
			->willReturn([$this->createMock(IUser::class), $this->createMock(IUser::class)]);
		$this->notificationManager->expects($this->never())->method('createNotification');

		$this->makeService()->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid', toParticipantId: 'delegate-uuid');

		self::assertSame([IUserManager::class], $this->resolved);

	}//end testAnAmbiguousEmailNotifiesNobody()

	/**
	 * A failing notification is logged and does not undo the stored proxy.
	 *
	 * @return void
	 */
	public function testAFailingNotificationIsLoggedNotThrown(): void {
		$this->stubFind(delegate: ['role' => 'member', 'nextcloudUserId' => 'nc-bob']);
		$this->objectService->expects($this->once())->method('saveObject');
		$this->notificationManager->method('createNotification')->willThrowException(new \RuntimeException('notifications app disabled'));
		$this->logger->expects($this->once())->method('warning')
			->with('Decidiq: proxy grant notification failed', ['error' => 'notifications app disabled']);

		$this->makeService()->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid', toParticipantId: 'delegate-uuid');

	}//end testAFailingNotificationIsLoggedNotThrown()

	/**
	 * A delegate that is not a stored participant still receives the proxy; there is nobody to notify.
	 *
	 * @return void
	 */
	public function testAnUnknownDelegateIsGrantedWithoutNotification(): void {
		$this->stubFind(delegate: null);
		$saved = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return $this->entity($object);
			}
		);

		$this->makeService()->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid', toParticipantId: 'external-uuid');

		self::assertSame([], $this->resolved);
		self::assertCount(1, $saved['notes']);
		self::assertSame('Proxy', $saved['notes'][0]['title']);
		$body = json_decode($saved['notes'][0]['body'], true);
		self::assertSame(['granter-uuid', 'external-uuid', 'round-uuid'], [$body['fromParticipantId'], $body['toParticipantId'], $body['votingRoundId']]);

	}//end testAnUnknownDelegateIsGrantedWithoutNotification()

	/**
	 * A failing cap lookup is logged and the default cap of 2 still applies.
	 *
	 * @return void
	 */
	public function testAFailingCapLookupFallsBackToTheDefault(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willThrowException(new \RuntimeException('appconfig table locked'));
		$this->appConfig = $appConfig;
		$this->stubFind(
			delegate: ['role' => 'member'],
			round: ['id' => 'round-uuid', 'notes' => [$this->proxyNote('a', 'delegate-uuid'), $this->proxyNote('b', 'delegate-uuid')]]
		);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('warning')
			->with('Decidiq: max_proxies_per_holder config lookup failed, using default', ['exception' => 'appconfig table locked']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Maximaal aantal volmachten bereikt: deze deelnemer heeft al 2 van de 2 toegestane volmachten');

		$this->makeService()->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid', toParticipantId: 'delegate-uuid');

	}//end testAFailingCapLookupFallsBackToTheDefault()

	/**
	 * Revoking removes only the revoker's proxy notes and keeps every other note.
	 *
	 * @return void
	 */
	public function testRevokeRemovesOnlyTheRevokersProxies(): void {
		$agenda = ['title' => 'Agenda', 'body' => 'not a proxy'];
		$bodyless = ['title' => 'Proxy'];
		$other = $this->proxyNote('other-uuid', 'delegate-uuid');
		$this->objectService->expects($this->once())->method('find')
			->with('round-uuid', [], false, 'decidiq', 'voting-round')
			->willReturn(
				$this->entity(
					[
						'id' => 'round-uuid',
						'openedAt' => null,
						'notes' => [
							$agenda,
							$this->proxyNote('granter-uuid', 'delegate-uuid'),
							$other,
							$bodyless,
							$this->proxyNote('granter-uuid', 'someone-else'),
						],
					]
				)
			);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['id' => 'round-uuid', 'openedAt' => null, 'notes' => [$agenda, $other, $bodyless]], [], 'decidiq', 'voting-round');

		$this->makeService()->revokeProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid');

	}//end testRevokeRemovesOnlyTheRevokersProxies()

	/**
	 * A round without notes is saved back with an empty list.
	 *
	 * @return void
	 */
	public function testRevokeOnARoundWithoutNotesSavesAnEmptyList(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'round-uuid']));
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['id' => 'round-uuid', 'notes' => []], [], 'decidiq', 'voting-round');

		$this->makeService()->revokeProxy(votingRoundId: 'round-uuid', fromParticipantId: 'granter-uuid');

	}//end testRevokeOnARoundWithoutNotesSavesAnEmptyList()

	/**
	 * Revoking on a round that does not exist is refused.
	 *
	 * @return void
	 */
	public function testRevokeRefusedWhenRoundMissing(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('VotingRound round-gone not found');

		$this->makeService()->revokeProxy(votingRoundId: 'round-gone', fromParticipantId: 'granter-uuid');

	}//end testRevokeRefusedWhenRoundMissing()
}//end class
