<?php

/**
 * Unit tests for McpMeetingScopeResolver.
 *
 * The resolver decides which meetings a non-admin may see through the MCP
 * `scope=all` tools: participant -> governance body -> meetings. It must
 * answer null (no restriction) only for an admin, and fail CLOSED (no
 * meetings) when the store cannot be read.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Mcp;

use OCA\Decidiq\Mcp\McpMeetingScopeResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for McpMeetingScopeResolver.
 *
 * @covers \OCA\Decidiq\Mcp\McpMeetingScopeResolver
 * @uses \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class McpMeetingScopeResolverTest extends TestCase {

	/**
	 * The mocked object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The mocked group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The resolver under test.
	 *
	 * @var McpMeetingScopeResolver
	 */
	private McpMeetingScopeResolver $resolver;

	/**
	 * Set up the resolver with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new McpMeetingScopeResolver(
			objectService: $this->objectService,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}

	/**
	 * An admin is not restricted, and the store is not read.
	 *
	 * @return void
	 */
	public function testAnAdminIsNotRestricted(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertNull($this->resolver->callerMeetingUuids(userId: 'admin'));
	}

	/**
	 * A participant sees the meetings of their governance bodies, once each;
	 * a participant row without a body, or with a body relation that has no
	 * id, contributes nothing, and a meeting without an id is skipped.
	 *
	 * @return void
	 */
	public function testAParticipantSeesTheMeetingsOfTheirBodies(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$asked = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$asked): array {
				$filters = $config['filters'];
				$asked[] = $filters;
				if ($filters['schema'] === 'participant') {
					return [
						['relations' => [['schema' => 'person', 'id' => 'p-1'], ['schema' => 'governance-body', 'id' => 'body-1']]],
						['relations' => [['schema' => 'governance-body', 'id' => 'body-2']]],
						['relations' => [['schema' => 'governance-body']]],
						['relations' => ['not-a-relation']],
						[],
					];
				}

				return match ($filters['_relations.governance-body']) {
					'body-1' => [['id' => 'meet-1'], ['uuid' => 'meet-2'], ['title' => 'no id']],
					'body-2' => [['id' => 'meet-2'], ['id' => 'meet-3']],
				};
			}
		);

		$uuids = $this->resolver->callerMeetingUuids(userId: 'ruben');

		$this->assertSame(['meet-1', 'meet-2', 'meet-3'], array_values($uuids));
		$this->assertSame(
			['register' => 'decidiq', 'schema' => 'participant', 'nextcloudUserId' => 'ruben'],
			$asked[0]
		);
		$this->assertSame(
			['register' => 'decidiq', 'schema' => 'meeting', '_relations.governance-body' => 'body-1'],
			$asked[1]
		);
		$this->assertCount(3, $asked);
	}

	/**
	 * A user who participates nowhere sees no meetings.
	 *
	 * @return void
	 */
	public function testANonParticipantSeesNothing(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->objectService->method('findAll')->willReturn([]);

		$this->assertSame([], $this->resolver->callerMeetingUuids(userId: 'guest'));
	}

	/**
	 * A store that throws is logged and fails closed.
	 *
	 * @return void
	 */
	public function testAFailingStoreFailsClosed(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Decidiq MCP: could not resolve caller meeting UUIDs, defaulting to empty',
				['userId' => 'ruben', 'error' => 'db down']
			);

		$this->assertSame([], $this->resolver->callerMeetingUuids(userId: 'ruben'));
	}
}
