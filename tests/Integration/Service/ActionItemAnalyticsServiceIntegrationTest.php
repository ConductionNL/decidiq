<?php

/**
 * Integration test for ActionItemAnalyticsService — verifies the DI container key
 * 'OCA\OpenRegister\Service\ObjectService' resolves correctly and getSummary()
 * returns a non-empty (non-zero) response when action-items exist.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Integration\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Integration\Service;

use OCA\Decidesk\Service\ActionItemAnalyticsService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ActionItemAnalyticsService.
 *
 * Verifies that the correct OR ObjectService DI key is used and that the service
 * produces non-zero analytics when at least one action-item exists in the register.
 * The suite skips gracefully when OpenRegister is not available.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class ActionItemAnalyticsServiceIntegrationTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ActionItemAnalyticsService|null
	 */
	private ?ActionItemAnalyticsService $service = null;

	/**
	 * Raw OpenRegister ObjectService (used to seed test data).
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * UUIDs of objects created during the test (for cleanup).
	 *
	 * @var list<string>
	 */
	private array $createdIds = [];

	/**
	 * Set up test fixtures; skip the suite when OR is unavailable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
			$this->markTestSkipped(
				message: 'OpenRegister ObjectService not available — integration environment not present.'
			);
		}

		try {
			$this->objectService = \OC::$server->get(\OCA\OpenRegister\Service\ObjectService::class);
			$container = \OC::$server->get(\Psr\Container\ContainerInterface::class);
			$logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
			$this->service = new ActionItemAnalyticsService($container, $logger);
		} catch (\Throwable $e) {
			$this->markTestSkipped(
				message: 'Could not resolve dependencies from DI container: ' . $e->getMessage()
			);
		}//end try

	}//end setUp()

	/**
	 * Remove any objects created during the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->objectService !== null) {
			foreach ($this->createdIds as $id) {
				try {
					$this->objectService->deleteObject(
						register: 'decidesk',
						schema: 'action-item',
						id: $id
					);
				} catch (\Throwable) {
					// Best-effort cleanup — ignore failures.
				}
			}
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * getSummary() returns totalOpen >= 1 when an open action-item exists.
	 *
	 * Creates one open action-item with a past due date, calls getSummary(), asserts
	 * totalOpen is at least 1 and totalOverdue is at least 1. This confirms that the
	 * container key 'OCA\OpenRegister\Service\ObjectService' resolves and the service
	 * does not silently swallow all results.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testGetSummaryReturnsNonZeroWhenActionItemsExist(): void {
		$this->assertNotNull(actual: $this->service, message: 'Service must be initialised.');
		$this->assertNotNull(actual: $this->objectService, message: 'ObjectService must be available.');

		// Seed one overdue open action-item.
		try {
			$entity = $this->objectService->saveObject(
				register: 'decidesk',
				schema: 'action-item',
				object: [
					'title' => 'Integration test action item (wave-9 C1)',
					'taskStatus' => 'open',
					'dueDate' => date('Y-m-d', strtotime('-3 days')),
					'createdAt' => date('Y-m-d', strtotime('-10 days')),
				]
			);
		} catch (\Throwable $e) {
			$this->markTestSkipped(
				message: 'Could not seed action-item into OR: ' . $e->getMessage()
			);
		}

		$data = $entity->jsonSerialize();
		$id = $data['id'] ?? ($data['@self']['id'] ?? null);
		if ($id !== null) {
			$this->createdIds[] = $id;
		}

		$result = $this->service->getSummary(
			dateFrom: date('Y-m-d', strtotime('-30 days')),
			dateTo: date('Y-m-d')
		);

		self::assertIsArray(actual: $result, message: 'getSummary() must return an array.');
		self::assertGreaterThanOrEqual(
			expected: 1,
			actual: $result['totalOpen'],
			message: 'totalOpen must be >= 1 when at least one open action-item exists.'
		);
		self::assertGreaterThanOrEqual(
			expected: 1,
			actual: $result['totalOverdue'],
			message: 'totalOverdue must be >= 1 when at least one overdue action-item exists.'
		);

	}//end testGetSummaryReturnsNonZeroWhenActionItemsExist()

}//end class
