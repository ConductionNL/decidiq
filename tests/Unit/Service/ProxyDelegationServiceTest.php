<?php

/**
 * Unit tests for ProxyDelegationService — proxy (volmacht) grant/revoke,
 * extracted from VotingService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
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

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ProxyDelegationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
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

}//end class
