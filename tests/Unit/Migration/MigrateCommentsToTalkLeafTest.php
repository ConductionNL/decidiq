<?php

/**
 * Unit tests for MigrateCommentsToTalkLeaf repair step.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-4.3
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\MigrateCommentsToTalkLeaf;
use OCA\Decidiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the legacy-Comment migration repair step.
 *
 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-4.3
 */
class MigrateCommentsToTalkLeafTest extends TestCase {

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IAppManager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Mock IOutput.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * The repair step under test.
	 *
	 * @var MigrateCommentsToTalkLeaf
	 */
	private MigrateCommentsToTalkLeaf $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->appManager = $this->createMock(originalClassName: IAppManager::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateCommentsToTalkLeaf(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $this->logger,
			appManager: $this->appManager,
		);

	}//end setUp()

	/**
	 * The step name mentions Comment and Talk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
	 */
	public function testGetNameReturnsDescription(): void {
		$name = $this->migration->getName();
		self::assertStringContainsString(needle: 'Comment', haystack: $name);
		self::assertStringContainsString(needle: 'Talk', haystack: $name);

	}//end testGetNameReturnsDescription()

	/**
	 * When OpenRegister is unavailable the migration exits without touching OR.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->expects($this->once())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(false);

		$this->container->expects($this->never())->method(constraint: 'get');
		$this->output->expects($this->atLeastOnce())->method(constraint: 'warning');

		$this->migration->run(output: $this->output);

	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * When Comment objects have never been created (schema absent), findAll()
	 * throwing is a graceful no-op.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.4
	 */
	public function testRunNoOpWhenLegacySchemaAbsent(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$this->appManager->expects($this->any())
			->method(constraint: 'isEnabledForUser')
			->willReturn(false);

		$objectService = $this->makeThrowingObjectService();

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->output->expects($this->atLeastOnce())->method(constraint: 'info');

		$this->migration->run(output: $this->output);

	}//end testRunNoOpWhenLegacySchemaAbsent()

	/**
	 * An already-migrated Comment is skipped — idempotent / resume-safe.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.4
	 */
	public function testRunSkipsAlreadyMigratedComment(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$this->appManager->expects($this->any())
			->method(constraint: 'isEnabledForUser')
			->willReturn(false);

		$objectService = $this->makeRecordingObjectService(
			legacyComments: [
				[
					'id' => 'comment-1',
					'text' => 'Already done.',
					'target' => 'decidesk:motion:motion-1',
					'_migratedToTalkLeaf' => true,
				],
			]
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertSame(expected: 0, actual: $objectService->saveCalls);
		self::assertSame(expected: 0, actual: $objectService->deleteCalls);

	}//end testRunSkipsAlreadyMigratedComment()

	/**
	 * A fresh Comment is stamped, archived, and its UUID appears in the delete
	 * list (no Talk access — Talk app absent on this instance).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.3
	 */
	public function testRunArchivesCommentWhenTalkAbsent(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		// Talk app not installed — skip Talk-related container gets.
		$this->appManager->expects($this->any())
			->method(constraint: 'isEnabledForUser')
			->willReturn(false);

		$objectService = $this->makeRecordingObjectService(
			legacyComments: [
				[
					'id' => 'comment-99',
					'text' => 'This is a legacy comment.',
					'author' => 'user1',
					'createdAt' => '2026-01-10T14:00:00+00:00',
					'target' => 'decidesk:motion:motion-abc',
				],
			]
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		// The comment must be stamped with the migration marker.
		self::assertTrue(condition: $objectService->saved[0]['_migratedToTalkLeaf']);

		// The legacy object must be archived.
		self::assertContains(needle: 'comment-99', haystack: $objectService->deleted);

	}//end testRunArchivesCommentWhenTalkAbsent()

	/**
	 * When OR ObjectService cannot be resolved, the migration exits cleanly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1
	 */
	public function testRunExitsGracefullyWhenObjectServiceUnavailable(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$this->appManager->expects($this->any())
			->method(constraint: 'isEnabledForUser')
			->willReturn(false);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willThrowException(new \RuntimeException('Service not found.'));

		$this->output->expects($this->atLeastOnce())->method(constraint: 'warning');

		$this->migration->run(output: $this->output);

	}//end testRunExitsGracefullyWhenObjectServiceUnavailable()

	/**
	 * Build a stub ObjectService whose findAll() throws — emulating a
	 * never-instantiated legacy schema.
	 *
	 * @return object
	 */
	private function makeThrowingObjectService(): object {
		return new class {
			/**
			 * Stub setRegister.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Stub setSchema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * FindAll throws — emulates a never-instantiated schema.
			 *
			 * Mirrors OpenRegister's real ObjectService::findAll(array $config)
			 * signature — a single config array, not the long-gone named-argument
			 * form (limit:).
			 *
			 * @param array $config Find-all config (filters/limit/offset).
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = []): array {
				throw new \RuntimeException('schema not found');
			}//end findAll()
		};

	}//end makeThrowingObjectService()

	/**
	 * Build a stub ObjectService that records saves/deletes and returns the
	 * supplied legacy comments from findAll().
	 *
	 * @param array<int,array<string,mixed>> $legacyComments Legacy comment rows.
	 *
	 * @return object
	 */
	private function makeRecordingObjectService(array $legacyComments): object {
		return new class($legacyComments) {
			/**
			 * Saved objects in call order.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Deleted UUIDs.
			 *
			 * @var array<int,string>
			 */
			public array $deleted = [];

			/**
			 * Number of saveObject calls.
			 *
			 * @var integer
			 */
			public int $saveCalls = 0;

			/**
			 * Number of deleteObject calls.
			 *
			 * @var integer
			 */
			public int $deleteCalls = 0;

			/**
			 * Legacy comment rows returned by findAll.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $legacyComments;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $legacyComments Legacy comment rows.
			 */
			public function __construct(array $legacyComments) {
				$this->legacyComments = $legacyComments;

			}//end __construct()

			/**
			 * Stub setRegister.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Stub setSchema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * Return the configured legacy comments.
			 *
			 * Mirrors OpenRegister's real ObjectService::findAll(array $config)
			 * signature — a single config array, not the long-gone named-argument
			 * form (limit:).
			 *
			 * @param array $config Find-all config (filters/limit/offset).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				return $this->legacyComments;
			}//end findAll()

			/**
			 * Capture a save.
			 *
			 * @param array<string,mixed> $object Object to save.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				$this->saveCalls++;
				$this->saved[] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Capture a delete.
			 *
			 * @param string $uuid Object UUID.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid, ?string $register = null, ?string $schema = null): bool {
				$this->deleteCalls++;
				$this->deleted[] = $uuid;
				return true;
			}//end deleteObject()
		};

	}//end makeRecordingObjectService()
}//end class
