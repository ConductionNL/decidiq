<?php

/**
 * Unit tests for ProxyDelegationService — proxy (volmacht) grant/revoke,
 * extracted from VotingService.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the delegation rules: no self-delegation, non-voting roles may not
 * receive a proxy, and a revoke is refused once the round has opened.
 *
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
 */
class ProxyDelegationServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ProxyDelegationService
	 */
	private ProxyDelegationService $service;

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
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$container->method('get')->willReturn($this->objectService);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnArgument(2);

		$this->service = new ProxyDelegationService(
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $this->objectService,
			appConfig: $this->appConfig,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A participant may not delegate a proxy to themselves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function testGrantProxyRejectsSelfDelegation(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Een deelnemer kan geen volmacht aan zichzelf verlenen');

		$this->service->grantProxy('round-uuid', 'same-uuid', 'same-uuid');

	}//end testGrantProxyRejectsSelfDelegation()

	/**
	 * A delegate holding a non-voting role may not receive a proxy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.5
	 */
	public function testGrantProxyRejectsObserverRole(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(data: ['displayName' => 'Observer X', 'role' => 'observer'])
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessage(message: "Deelnemer met rol 'observer' kan geen volmacht ontvangen");

		$this->service->grantProxy('round-uuid', 'granter-uuid', 'delegate-uuid');

	}//end testGrantProxyRejectsObserverRole()

	/**
	 * A proxy may not be revoked once the round has opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function testRevokeProxyRefusedOnOpenedRound(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(data: ['id' => 'round-uuid', 'openedAt' => '2026-06-15T10:00:00+00:00', 'notes' => []])
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \RuntimeException::class);
		$this->expectExceptionMessage(message: 'Stemronde is al geopend');

		$this->service->revokeProxy('round-uuid', 'granter-uuid');

	}//end testRevokeProxyRefusedOnOpenedRound()

	/**
	 * Build a voting-round entity carrying a proxy note per grantor, all held by the holder.
	 *
	 * @param string      $holder   Receiving participant UUID.
	 * @param string[]    $grantors Delegating participant UUIDs.
	 * @param string      $id       Voting round UUID.
	 * @param string|null $meeting  Meeting UUID the round was opened in, or null for none.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function roundWithProxies(string $holder, array $grantors, string $id='round-uuid', ?string $meeting=null): ObjectEntity&MockObject {
		$notes = [['title' => 'Agenda', 'body' => 'not a proxy']];
		foreach ($grantors as $grantor) {
			$notes[] = [
				'title' => 'Proxy',
				'body' => json_encode(['fromParticipantId' => $grantor, 'toParticipantId' => $holder, 'votingRoundId' => 'round-uuid']),
			];
		}

		$notes[] = [
			'title' => 'Proxy',
			'body' => json_encode(['fromParticipantId' => 'other-grantor', 'toParticipantId' => 'other-holder', 'votingRoundId' => 'round-uuid']),
		];

		$data = ['id' => $id, 'notes' => $notes];
		if ($meeting !== null) {
			$data['meeting'] = $meeting;
		}

		return $this->entity(data: $data);
	}//end roundWithProxies()

	/**
	 * Route find() to the delegate participant or the round by schema.
	 *
	 * @param ObjectEntity&MockObject $round The voting-round entity.
	 *
	 * @return void
	 */
	private function stubFind(ObjectEntity&MockObject $round): void {
		$delegate = $this->entity(data: ['role' => 'member']);
		$this->objectService->method('find')->willReturnCallback(
			static function (mixed $id, mixed $extend, mixed $files, mixed $register, mixed $schema) use ($round, $delegate): ObjectEntity {
				if ($schema === 'voting-round') {
					return $round;
				}

				return $delegate;
			}
		);
	}//end stubFind()

	/**
	 * A holder at the default cap of 2 may not receive a third proxy, and nothing is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyRefusedWhenHolderReachedDefaultCap(): void {
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a', 'grantor-b']));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Maximaal aantal volmachten bereikt: deze deelnemer heeft al 2 van de 2 toegestane volmachten');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyRefusedWhenHolderReachedDefaultCap()

	/**
	 * A holder below the cap receives the proxy; proxies held by others do not count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyStoredWhenHolderBelowCap(): void {
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a']));
		$saved = null;
		$stored = $this->entity(data: []);
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $stored): ObjectEntity {
				$saved = $object;
				return $stored;
			}
		);

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

		$this->assertCount(expectedCount: 4, haystack: $saved['notes']);
		$this->assertSame(expected: 'grantor-c', actual: json_decode($saved['notes'][3]['body'], true)['fromParticipantId']);

	}//end testGrantProxyStoredWhenHolderBelowCap()

	/**
	 * The configured cap replaces the default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyUsesConfiguredCap(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueInt')
			->with('decidiq', 'max_proxies_per_holder', 2)
			->willReturn(1);
		$service = new ProxyDelegationService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $this->objectService,
			appConfig: $appConfig,
		);
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a']));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessage(message: '1 van de 1 toegestane volmachten');

		$service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyUsesConfiguredCap()

	/**
	 * A cap below 1 falls back to the default instead of disabling the limit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyFallsBackToDefaultForInvalidCap(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(0);
		$service = new ProxyDelegationService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $this->objectService,
			appConfig: $appConfig,
		);
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a', 'grantor-b']));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessage(message: '2 van de 2 toegestane volmachten');

		$service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyFallsBackToDefaultForInvalidCap()

	/**
	 * A grantor repeating a grant to the same holder is not counted against the cap.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyRepeatedGrantorNotCountedTwice(): void {
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a', 'grantor-b']));
		$this->objectService->expects($this->once())->method('saveObject');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-b', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyRepeatedGrantorNotCountedTwice()

	/**
	 * Without the round the held proxies cannot be counted, so the grant is refused (fail closed).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyRefusedWhenRoundMissing(): void {
		$delegate = $this->entity(data: ['role' => 'member']);
		$this->objectService->method('find')->willReturnCallback(
			static function (mixed $id, mixed $extend, mixed $files, mixed $register, mixed $schema) use ($delegate): ?ObjectEntity {
				if ($schema === 'voting-round') {
					return null;
				}

				return $delegate;
			}
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \RuntimeException::class);
		$this->expectExceptionMessage(message: 'VotingRound round-uuid not found');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyRefusedWhenRoundMissing()

	/**
	 * Proxies the holder holds in other rounds of the same meeting count towards the cap.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyCountsProxiesAcrossTheMeeting(): void {
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a'], meeting: 'meeting-1'));
		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'decidiq', 'schema' => 'voting-round', 'meeting' => 'meeting-1'], 'limit' => 1000], false, false)
			->willReturn([$this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-b'], id: 'round-2', meeting: 'meeting-1')]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessage(message: '2 van de 2 toegestane volmachten');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyCountsProxiesAcrossTheMeeting()

	/**
	 * A grantor who gave the holder a proxy in an earlier round of the meeting counts once.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxySameGrantorAcrossRoundsCountedOnce(): void {
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a'], meeting: 'meeting-1'));
		$this->objectService->method('findAll')->willReturn(
			[$this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a'], id: 'round-2', meeting: 'meeting-1')]
		);
		$this->objectService->expects($this->once())->method('saveObject');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxySameGrantorAcrossRoundsCountedOnce()

	/**
	 * The round itself and rounds of another meeting in the result are not counted again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxySkipsTheRoundItselfAndOtherMeetings(): void {
		$round = $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a'], meeting: 'meeting-1');
		$this->stubFind(round: $round);
		$this->objectService->method('findAll')->willReturn(
			[
				$round,
				$this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-b'], id: 'round-9', meeting: 'meeting-9'),
			]
		);
		$this->objectService->expects($this->once())->method('saveObject');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxySkipsTheRoundItselfAndOtherMeetings()

	/**
	 * A round without a meeting counts its own proxies only and reads no other rounds.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function testGrantProxyWithoutMeetingCountsTheRoundOnly(): void {
		$this->stubFind(round: $this->roundWithProxies(holder: 'delegate-uuid', grantors: ['grantor-a']));
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->once())->method('saveObject');

		$this->service->grantProxy(votingRoundId: 'round-uuid', fromParticipantId: 'grantor-c', toParticipantId: 'delegate-uuid');

	}//end testGrantProxyWithoutMeetingCountsTheRoundOnly()

}//end class
