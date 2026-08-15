<?php

/**
 * Unit tests for MinutesGenerationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MinutesDraftRenderer;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MinutesGenerationService.
 *
 * The service uses ObjectService::find() and ObjectService::findAll() from
 * OpenRegister. All tests mock the ObjectService via the DI container.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
 */
class MinutesGenerationServiceTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The service under test.
	 *
	 * @var MinutesGenerationService
	 */
	private MinutesGenerationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock the (stubbed) OpenRegister ObjectService class itself so that
		// named-argument calls (saveObject(object: ..., register: ...)) bind
		// correctly — an stdClass addMethods() mock has no parameter names.
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		// The renderer is a pure, dependency-free collaborator, so the real one
		// is used here: these tests assert on the rendered text, and a mock
		// would only assert that a mock was called.
		$this->service = new MinutesGenerationService(
			container: $this->container,
			logger: $this->logger,
			renderer: new MinutesDraftRenderer(),
		);

	}//end setUp()

	/**
	 * Happy path: generates a Dutch template with meeting title and agenda items.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
	 *
	 * @return void
	 */
	public function testGenerateDraftHappyPathProducesStructuredDutchTemplate(): void {
		$minutesData = [
			'id' => 'minutes-001',
			'title' => 'Notulen Testgemeente 1 april 2025',
			'lifecycle' => 'draft',
			'meeting' => 'meeting-001',
		];

		$meetingData = [
			'id' => 'meeting-001',
			'title' => 'Raadsvergadering 1 april 2025',
			'scheduledDate' => '2025-04-01T19:00:00Z',
			'location' => 'Raadzaal',
		];

		$agendaItemData = [
			'id' => 'ai-001',
			'title' => 'Woningbouwplan Oost',
			'orderNumber' => 1,
		];

		$minutesEntity = $this->createEntityMock($minutesData);
		$meetingEntity = $this->createEntityMock($meetingData);
		$agendaEntity = $this->createEntityMock($agendaItemData);

		$this->objectService->method('find')
			->willReturnCallback(static function (string $id) use ($minutesEntity, $meetingEntity): ?object {
				if ($id === 'minutes-001') {
					return $minutesEntity;
				}

				if ($id === 'meeting-001') {
					return $meetingEntity;
				}

				return null;
			});

		$this->objectService->method('findAll')
			->willReturnCallback(static function (array $config) use ($agendaEntity): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'agenda-item') {
					return [$agendaEntity];
				}

				return [];
			});

		$result = $this->service->generateDraft(minutesId: 'minutes-001');

		self::assertStringContainsString('Notulen Testgemeente 1 april 2025', $result);
		self::assertStringContainsString('Raadsvergadering 1 april 2025', $result);
		self::assertStringContainsString('Woningbouwplan Oost', $result);
		self::assertStringContainsString('automatisch gegenereerd concept', $result);

	}//end testGenerateDraftHappyPathProducesStructuredDutchTemplate()

	/**
	 * Meeting with no agenda items returns a minimal valid template.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
	 *
	 * @return void
	 */
	public function testGenerateDraftWithNoAgendaItemsReturnsMinimalTemplate(): void {
		$minutesData = [
			'id' => 'minutes-002',
			'title' => 'Concept notulen zonder agenda',
			'lifecycle' => 'draft',
			'meeting' => 'meeting-002',
		];

		$meetingData = [
			'id' => 'meeting-002',
			'title' => 'Lege vergadering',
		];

		$minutesEntity = $this->createEntityMock($minutesData);
		$meetingEntity = $this->createEntityMock($meetingData);

		$this->objectService->method('find')
			->willReturnCallback(static function (string $id) use ($minutesEntity, $meetingEntity): ?object {
				if ($id === 'minutes-002') {
					return $minutesEntity;
				}

				if ($id === 'meeting-002') {
					return $meetingEntity;
				}

				return null;
			});

		$this->objectService->method('findAll')->willReturn([]);

		$result = $this->service->generateDraft(minutesId: 'minutes-002');

		self::assertStringContainsString('Concept notulen zonder agenda', $result);
		self::assertStringContainsString('Opening', $result);
		self::assertStringContainsString('Sluiting', $result);
		// No agenda section when no items.
		self::assertStringNotContainsString('## 2. Agenda', $result);

	}//end testGenerateDraftWithNoAgendaItemsReturnsMinimalTemplate()

	/**
	 * Minutes not found throws InvalidArgumentException.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
	 *
	 * @return void
	 */
	public function testGenerateDraftMissingMinutesThrowsInvalidArgumentException(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('minutes-999');

		$this->service->generateDraft(minutesId: 'minutes-999');

	}//end testGenerateDraftMissingMinutesThrowsInvalidArgumentException()

	/**
	 * No linked meeting throws RuntimeException.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
	 *
	 * @return void
	 */
	public function testGenerateDraftMissingMeetingThrowsRuntimeException(): void {
		$minutesData = ['id' => 'minutes-003', 'title' => 'No meeting', 'lifecycle' => 'draft'];
		$minutesEntity = $this->createEntityMock($minutesData);

		$this->objectService->method('find')
			->willReturnCallback(static function (string $id) use ($minutesEntity): ?object {
				if ($id === 'minutes-003') {
					return $minutesEntity;
				}

				return null;
			});

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No linked Meeting');

		$this->service->generateDraft(minutesId: 'minutes-003');

	}//end testGenerateDraftMissingMeetingThrowsRuntimeException()

	/**
	 * OpenRegister unavailable throws RuntimeException.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-9
	 *
	 * @return void
	 */
	public function testGenerateDraftThrowsRuntimeExceptionWhenOpenRegisterUnavailable(): void {
		$containerNoOR = $this->createMock(ContainerInterface::class);
		$containerNoOR->method('get')
			->willThrowException(new \Exception('Service not found'));

		$service = new MinutesGenerationService(
			container: $containerNoOR,
			logger: $this->logger,
			renderer: new MinutesDraftRenderer(),
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister ObjectService is not available');

		$service->generateDraft(minutesId: 'any-id');

	}//end testGenerateDraftThrowsRuntimeExceptionWhenOpenRegisterUnavailable()

	/**
	 * reject() moves review → draft and appends the server-attributed
	 * rejection to reviewComments.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testRejectMovesReviewBackToDraftWithComment(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-010',
				'lifecycle' => 'review',
				'title' => 'Notulen',
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);

		$savedObject = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			// saveObject() is typed `: ObjectEntity` in production and can never
			// return the payload array it was handed (#399).
			->willReturnCallback(function (array $object) use (&$savedObject): ObjectEntity {
				$savedObject = $object;
				return $this->createEntityMock($object);
			});

		$result = $this->service->reject(
			minutesId: 'minutes-010',
			comment: 'Attendance list incomplete',
			userId: 'chair-user',
		);

		self::assertSame('draft', $savedObject['lifecycle']);
		self::assertCount(1, $savedObject['reviewComments']);
		self::assertSame('rejected', $savedObject['reviewComments'][0]['action']);
		self::assertSame('Attendance list incomplete', $savedObject['reviewComments'][0]['comment']);
		self::assertSame('chair-user', $savedObject['reviewComments'][0]['author']);
		self::assertNotEmpty($savedObject['reviewComments'][0]['createdAt']);
		self::assertSame('draft', $result['lifecycle']);

	}//end testRejectMovesReviewBackToDraftWithComment()

	/**
	 * reject() preserves earlier review comments (append, not replace).
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testRejectAppendsToExistingReviewComments(): void {
		$existing = [
			'action' => 'rejected',
			'comment' => 'First round',
			'author' => 'chair-user',
			'createdAt' => '2026-06-01T10:00:00+00:00',
		];

		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-011',
				'lifecycle' => 'review',
				'reviewComments' => [$existing],
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);

		$savedObject = null;
		$this->objectService->method('saveObject')
			// saveObject() is typed `: ObjectEntity` in production and can never
			// return the payload array it was handed (#399).
			->willReturnCallback(function (array $object) use (&$savedObject): ObjectEntity {
				$savedObject = $object;
				return $this->createEntityMock($object);
			});

		$this->service->reject(minutesId: 'minutes-011', comment: 'Second round', userId: 'chair-user');

		self::assertCount(2, $savedObject['reviewComments']);
		self::assertSame('First round', $savedObject['reviewComments'][0]['comment']);
		self::assertSame('Second round', $savedObject['reviewComments'][1]['comment']);

	}//end testRejectAppendsToExistingReviewComments()

	/**
	 * reject() without a comment is refused (mandatory comment).
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testRejectWithEmptyCommentThrowsInvalidArgumentException(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('rejection comment is required');

		$this->service->reject(minutesId: 'minutes-010', comment: '   ', userId: 'chair-user');

	}//end testRejectWithEmptyCommentThrowsInvalidArgumentException()

	/**
	 * reject() from a non-review lifecycle is refused — the forward
	 * transition map stays intact, only review → draft is possible.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testRejectFromDraftThrowsInvalidArgumentException(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-012',
				'lifecycle' => 'draft',
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('review');

		$this->service->reject(minutesId: 'minutes-012', comment: 'Nope', userId: 'chair-user');

	}//end testRejectFromDraftThrowsInvalidArgumentException()

	/**
	 * reject() on unknown minutes throws MissingObjectException.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testRejectUnknownMinutesThrowsMissingObjectException(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(\OCA\Decidesk\Exception\MissingObjectException::class);

		$this->service->reject(minutesId: 'minutes-404', comment: 'Hello', userId: 'chair-user');

	}//end testRejectUnknownMinutesThrowsMissingObjectException()

	/**
	 * Helper: create an ObjectEntity double with getObject().
	 *
	 * Must be an ObjectEntity double, not a stdClass one: ObjectService::find()
	 * is typed `?ObjectEntity` in production, so a stdClass mock is a value the
	 * service can never hand the code under test (#399).
	 *
	 * @param array<string,mixed> $data Object data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function createEntityMock(array $data): ObjectEntity&MockObject {
		$mock = $this->createMock(ObjectEntity::class);
		$mock->method('getObject')->willReturn($data);
		return $mock;
	}//end createEntityMock()

}//end class
