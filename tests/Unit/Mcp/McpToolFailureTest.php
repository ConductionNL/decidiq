<?php

/**
 * The MCP tools' failure envelopes.
 *
 * When the register throws behind a tool, the tool must log the cause and
 * answer a generic `internal_error` envelope: the exception text goes to the
 * server log and never into the LLM's context.
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

use OCA\Decidiq\Mcp\McpActionItemTools;
use OCA\Decidiq\Mcp\McpArgumentValidator;
use OCA\Decidiq\Mcp\McpMeetingGate;
use OCA\Decidiq\Mcp\McpMeetingTools;
use OCA\Decidiq\Mcp\McpSourceFormatter;
use OCA\Decidiq\Service\ActionItemWriter;
use OCA\Decidiq\Service\MeetingService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the internal-error paths of the MCP tool classes.
 *
 * @covers \OCA\Decidiq\Mcp\McpActionItemTools
 * @covers \OCA\Decidiq\Mcp\McpMeetingTools
 * @uses \OCA\Decidiq\Mcp\McpMeetingGate
 * @uses \OCA\Decidiq\Mcp\McpMeetingScopeResolver
 * @uses \OCA\Decidiq\Mcp\McpArgumentValidator
 * @uses \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class McpToolFailureTest extends TestCase {

	/**
	 * A syntactically valid meeting UUID.
	 *
	 * @var string
	 */
	private const UUID = '0b6f8a52-3c1d-4e2f-9a7b-1c2d3e4f5a6b';

	/**
	 * The mocked object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The mocked container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The action-item tools under test.
	 *
	 * @var McpActionItemTools
	 */
	private McpActionItemTools $actionItems;

	/**
	 * The meeting tools under test.
	 *
	 * @var McpMeetingTools
	 */
	private McpMeetingTools $meetings;

	/**
	 * Wire both tool classes to one signed-in admin and mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$formatter = new McpSourceFormatter();
		$validator = new McpArgumentValidator(formatter: $formatter);
		$gate = new McpMeetingGate(
			userSession: $session,
			groupManager: $groups,
			logger: $this->logger,
			participantResolver: $this->createMock(ParticipantResolver::class),
			formatter: $formatter,
			validator: $validator,
			objectService: $this->objectService,
		);

		$this->actionItems = new McpActionItemTools(
			container: $this->container,
			logger: $this->logger,
			objectService: $this->objectService,
			gate: $gate,
			formatter: $formatter,
			validator: $validator,
		);
		$this->meetings = new McpMeetingTools(
			meetingService: $this->createMock(MeetingService::class),
			logger: $this->logger,
			objectService: $this->objectService,
			gate: $gate,
			formatter: $formatter,
			validator: $validator,
		);
	}

	/**
	 * The generic internal-error envelope.
	 *
	 * @param string $message The message.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function internal(string $message): array {
		return ['isError' => true, 'error' => 'internal_error', 'message' => $message];
	}

	/**
	 * Make find() answer the meeting.
	 *
	 * @return void
	 */
	private function meetingExists(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => self::UUID, 'title' => 'Raad']);
		$this->objectService->method('find')->willReturn($entity);
	}

	/**
	 * A failing action-item list is logged and answered generically.
	 *
	 * @return void
	 */
	public function testListOpenActionItemsHidesTheFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Decidiq MCP: listOpenActionItems failed', ['exception' => 'secret detail']);

		$this->assertSame(
			$this->internal('Failed to retrieve action items. See server log for details.'),
			$this->actionItems->listOpenActionItems(args: [])
		);
	}

	/**
	 * A failing writer is logged with the meeting and answered generically.
	 *
	 * @return void
	 */
	public function testAddActionItemHidesTheFailure(): void {
		$this->meetingExists();
		$this->container->method('get')
			->with(ActionItemWriter::class)
			->willThrowException(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Decidiq MCP: addActionItem failed', ['meetingUuid' => self::UUID, 'exception' => 'secret detail']);

		$this->assertSame(
			$this->internal('Failed to create action item. See server log for details.'),
			$this->actionItems->addActionItem(args: ['meetingUuid' => self::UUID, 'title' => 'Notulen rondsturen'])
		);
	}

	/**
	 * A failing meeting list is logged and answered generically.
	 *
	 * @return void
	 */
	public function testListRecentMeetingsHidesTheFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Decidiq MCP: listRecentMeetings failed', ['exception' => 'secret detail']);

		$this->assertSame(
			$this->internal('Failed to retrieve meetings. See server log for details.'),
			$this->meetings->listRecentMeetings(args: [])
		);
	}

	/**
	 * A failing detail read is logged with the meeting and answered generically.
	 *
	 * @return void
	 */
	public function testGetMeetingDetailsHidesTheFailure(): void {
		$this->meetingExists();
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Decidiq MCP: getMeetingDetails failed', ['meetingUuid' => self::UUID, 'exception' => 'secret detail']);

		$this->assertSame(
			$this->internal('Failed to retrieve meeting details. See server log for details.'),
			$this->meetings->getMeetingDetails(args: ['meetingUuid' => self::UUID])
		);
	}
}
