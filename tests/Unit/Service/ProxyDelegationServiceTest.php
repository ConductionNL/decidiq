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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the delegation rules: no self-delegation, non-voting roles may not
 * receive a proxy, and a revoke is refused once the round has opened.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
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
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->service = new ProxyDelegationService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
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
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A participant may not delegate a proxy to themselves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function testGrantProxyRejectsSelfDelegation(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Een deelnemer kan geen volmacht aan zichzelf verlenen');

		$this->service->grantProxy('round-uuid', 'same-uuid', 'same-uuid');

	}//end testGrantProxyRejectsSelfDelegation()

	/**
	 * A delegate holding a non-voting role may not receive a proxy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 */
	public function testGrantProxyRejectsObserverRole(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['displayName' => 'Observer X', 'role' => 'observer'])
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("Deelnemer met rol 'observer' kan geen volmacht ontvangen");

		$this->service->grantProxy('round-uuid', 'granter-uuid', 'delegate-uuid');

	}//end testGrantProxyRejectsObserverRole()

	/**
	 * A proxy may not be revoked once the round has opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function testRevokeProxyRefusedOnOpenedRound(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => 'round-uuid', 'openedAt' => '2026-06-15T10:00:00+00:00', 'notes' => []])
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Stemronde is al geopend');

		$this->service->revokeProxy('round-uuid', 'granter-uuid');

	}//end testRevokeProxyRefusedOnOpenedRound()

	/**
	 * Serialise a grant as the Proxy note grantProxy() writes.
	 *
	 * @param string $from Grantor participant UUID.
	 * @param string $to Holder participant UUID.
	 *
	 * @return array<string, string>
	 */
	private static function grantNote(string $from, string $to): array {
		return [
			'title' => 'Proxy',
			'body' => json_encode(['fromParticipantId' => $from, 'toParticipantId' => $to, 'votingRoundId' => 'round-uuid']),
		];
	}//end grantNote()

	/**
	 * Wire find() to answer a voting member for participants and the given round.
	 *
	 * @param array<int, array<string, string>> $notes The round's notes.
	 *
	 * @return void
	 */
	private function seedRound(array $notes): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($notes): ObjectEntity {
				if ($schema === 'voting-round') {
					return $this->entity(['id' => 'round-uuid', 'notes' => $notes]);
				}

				return $this->entity(['displayName' => 'Name of ' . $id, 'role' => 'member']);
			}
		);
	}//end seedRound()

	/**
	 * A holder who already holds two proxies on the round cannot receive a third (vot-11, #1377).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testGrantProxyRefusesThirdProxyForHolder(): void {
		$this->seedRound([self::grantNote('a-uuid', 'holder-uuid'), self::grantNote('b-uuid', 'holder-uuid')]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('maximale aantal volmachten (2 van 2)');

		$this->service->grantProxy('round-uuid', 'c-uuid', 'holder-uuid');

	}//end testGrantProxyRefusesThirdProxyForHolder()

	/**
	 * Under the cap the grant is stored; a re-grant replaces the grantor's earlier grant.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testGrantProxyUnderCapReplacesGrantorsEarlierGrant(): void {
		$this->seedRound([self::grantNote('a-uuid', 'holder-uuid'), self::grantNote('b-uuid', 'other-uuid')]);

		$saved = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return $this->entity($object);
			}
		);

		// b moves their proxy from other-uuid to holder-uuid: holder then holds 2, which is allowed.
		$this->service->grantProxy('round-uuid', 'b-uuid', 'holder-uuid');

		$grants = array_map(
			static fn (array $note): array => json_decode($note['body'], true),
			$saved['notes']
		);
		self::assertCount(2, $grants);
		self::assertSame(['a-uuid', 'b-uuid'], array_column($grants, 'fromParticipantId'));
		self::assertSame(['holder-uuid', 'holder-uuid'], array_column($grants, 'toParticipantId'));

	}//end testGrantProxyUnderCapReplacesGrantorsEarlierGrant()

	/**
	 * proxiesFor() names the proxies a participant holds and the one they gave (vot-10, #1377).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testProxiesForListsHeldAndGranted(): void {
		$this->seedRound(
			[
				self::grantNote('a-uuid', 'me-uuid'),
				self::grantNote('me-uuid', 'x-uuid'),
				self::grantNote('b-uuid', 'other-uuid'),
			]
		);

		$result = $this->service->proxiesFor('round-uuid', 'me-uuid');

		self::assertSame('me-uuid', $result['participantId']);
		self::assertSame([['participantId' => 'a-uuid', 'displayName' => 'Name of a-uuid']], $result['held']);
		self::assertSame('x-uuid', $result['granted']);

	}//end testProxiesForListsHeldAndGranted()

}//end class
