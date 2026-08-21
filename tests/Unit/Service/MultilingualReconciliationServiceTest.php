<?php

/**
 * Unit tests for MultilingualReconciliationService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\ITranslationAdapter;
use OCA\Decidesk\Service\MultilingualReconciliationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MultilingualReconciliationService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationServiceTest extends TestCase {

	/**
	 * Build a service over an in-memory rowset.
	 *
	 * @param array<string, array<int, array<string, mixed>>> &$rowsBySchema Map schema => rows
	 * @param array<int, array<string, mixed>> &$saved Captured saves
	 *
	 * @return MultilingualReconciliationService
	 */
	private function makeService(array &$rowsBySchema, array &$saved): MultilingualReconciliationService {
		$rowsRef = &$rowsBySchema;
		$savedRef = &$saved;
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$rowsRef): array {
				$schema = (string)($config['schema'] ?? '');
				$filters = ($config['filters'] ?? []);
				$rows = ($rowsRef[$schema] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $k => $v) {
								if (($row[$k] ?? null) !== $v) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use (&$rowsRef) {
				$schemaKey = (string)$schema;
				foreach (($rowsRef[$schemaKey] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === (string)$id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$savedRef, &$rowsRef): ObjectEntity {
				$schemaKey = (string)$schema;
				$savedRef[] = $object + ['_schema' => $schemaKey, '_uuid' => (string)($uuid ?? '')];
				$id = ((string)($uuid ?? '') !== '' ? (string)$uuid : ($schemaKey . '-' . (count($savedRef))));
				$row = array_merge(['id' => $id], $object);

				$rowsRef[$schemaKey] ??= [];

				// Replace existing row when uuid matches (saveObject as update).
				if ((string)($uuid ?? '') !== '') {
					$replaced = false;
					foreach ($rowsRef[$schemaKey] as $index => $existing) {
						if ((string)($existing['id'] ?? '') === (string)$uuid) {
							$rowsRef[$schemaKey][$index] = $row;
							$replaced = true;
							break;
						}
					}

					if ($replaced === false) {
						$rowsRef[$schemaKey][] = $row;
					}
				} else {
					$rowsRef[$schemaKey][] = $row;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new MultilingualReconciliationService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * Build a translation adapter that returns a fixed translation string.
	 *
	 * @param string $text Returned text
	 * @param bool $success Reported success
	 * @param string $provider Reported provider
	 *
	 * @return ITranslationAdapter
	 */
	private function makeAdapter(string $text = '[translated]', bool $success = true, string $provider = 'stub'): ITranslationAdapter {
		return new class($text, $success, $provider) implements ITranslationAdapter {
			/**
			 * Constructor.
			 */
			public function __construct(
				private readonly string $text,
				private readonly bool $success,
				private readonly string $provider,
			) {
			}

			/**
			 * Translate.
			 *
			 * @param string $text Source
			 * @param string $sourceLocale Source locale
			 * @param string $targetLocale Target locale
			 *
			 * @return array{success: bool, text: string, provider: string, message: string}
			 */
			public function translate(string $text, string $sourceLocale, string $targetLocale): array {
				return [
					'success' => $this->success,
					'text' => $this->text,
					'provider' => $this->provider,
					'message' => 'stub',
				];
			}
		};

	}//end makeAdapter()

	/**
	 * queue rejects an empty minutesId.
	 *
	 * @return void
	 */
	public function testQueueRejectsEmptyMinutesId(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->queue('', 'nl', ['en']);
		$this->assertFalse($result['success']);

	}//end testQueueRejectsEmptyMinutesId()

	/**
	 * queue rejects an unsupported source locale.
	 *
	 * @return void
	 */
	public function testQueueRejectsUnsupportedSourceLocale(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->queue('min-1', 'xx', ['en']);
		$this->assertFalse($result['success']);

	}//end testQueueRejectsUnsupportedSourceLocale()

	/**
	 * queue rejects when no valid target locales remain.
	 *
	 * @return void
	 */
	public function testQueueRejectsWhenNoTargets(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->queue('min-1', 'nl', ['nl', 'xx']);
		$this->assertFalse($result['success']);

	}//end testQueueRejectsWhenNoTargets()

	/**
	 * queue persists one entry per valid target locale.
	 *
	 * @return void
	 */
	public function testQueuePersistsEntries(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->queue('min-1', 'nl', ['en', 'de', 'nl', 'xx']);
		$this->assertTrue($result['success']);
		$this->assertCount(2, $result['entries']);

		$entries = $result['entries'];
		$targets = array_column($entries, 'targetLocale');
		sort($targets);
		$this->assertSame(['de', 'en'], $targets);

	}//end testQueuePersistsEntries()

	/**
	 * status counts queue entries by status.
	 *
	 * @return void
	 */
	public function testStatusGroupsByStatusValue(): void {
		$rows = [
			MultilingualReconciliationService::SCHEMA => [
				['id' => 'q-1', 'status' => 'queued'],
				['id' => 'q-2', 'status' => 'queued'],
				['id' => 'q-3', 'status' => 'completed'],
				['id' => 'q-4', 'status' => 'failed'],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->status();
		$this->assertTrue($result['success']);
		$this->assertSame(2, $result['summary']['queued']);
		$this->assertSame(1, $result['summary']['completed']);
		$this->assertSame(1, $result['summary']['failed']);
		$this->assertCount(4, $result['entries']);

	}//end testStatusGroupsByStatusValue()

	/**
	 * processQueue marks an entry as failed when the source minutes are missing.
	 *
	 * @return void
	 */
	public function testProcessQueueFailsOnMissingMinutes(): void {
		$rows = [
			MultilingualReconciliationService::SCHEMA => [
				[
					'id' => 'q-1',
					'minutesKoppeling' => 'missing',
					'sourceLocale' => 'nl',
					'targetLocale' => 'en',
					'status' => 'queued',
					'attempts' => 0,
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);
		$svc->setAdapter($this->makeAdapter());

		$result = $svc->processQueue();
		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['processed']);
		$this->assertSame(0, $result['completed']);
		$this->assertSame(1, $result['failed']);

	}//end testProcessQueueFailsOnMissingMinutes()

	/**
	 * processQueue translates and writes a linked target-language minutes
	 * record + flips the entry to completed.
	 *
	 * @return void
	 */
	public function testProcessQueueCompletesHappyPath(): void {
		$rows = [
			'minutes' => [
				[
					'id' => 'min-1',
					'meetingIntegration' => 'meet-1',
					'language' => 'nl',
					'version' => 'final',
					'content' => 'Vergaderingsnotulen.',
				],
			],
			MultilingualReconciliationService::SCHEMA => [
				[
					'id' => 'q-1',
					'minutesKoppeling' => 'min-1',
					'sourceLocale' => 'nl',
					'targetLocale' => 'en',
					'status' => 'queued',
					'attempts' => 0,
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);
		$svc->setAdapter($this->makeAdapter('Meeting minutes.', true, 'stub'));

		$result = $svc->processQueue();
		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['processed']);
		$this->assertSame(1, $result['completed']);
		$this->assertSame(0, $result['failed']);

		$translatedMinutes = array_values(
			array_filter(
				$saved,
				static fn (array $s): bool => ($s['_schema'] ?? '') === 'minutes'
			)
		);
		$this->assertCount(1, $translatedMinutes);
		$this->assertSame('Meeting minutes.', $translatedMinutes[0]['content']);
		$this->assertSame('en', $translatedMinutes[0]['language']);
		$this->assertSame('min-1', $translatedMinutes[0]['sourceMinutesKoppeling']);

		$queueUpdates = array_values(
			array_filter(
				$saved,
				static fn (array $s): bool => ($s['_schema'] ?? '') === MultilingualReconciliationService::SCHEMA
					&& ($s['_uuid'] ?? '') === 'q-1'
			)
		);
		$this->assertNotEmpty($queueUpdates);
		$finalEntry = end($queueUpdates);
		$this->assertSame('completed', $finalEntry['status']);
		$this->assertSame('stub', $finalEntry['provider']);

	}//end testProcessQueueCompletesHappyPath()

	/**
	 * processQueue marks the entry failed when the adapter reports failure.
	 *
	 * @return void
	 */
	public function testProcessQueueRecordsAdapterFailure(): void {
		$rows = [
			'minutes' => [
				[
					'id' => 'min-1',
					'meetingIntegration' => 'meet-1',
					'language' => 'nl',
					'content' => 'Body.',
				],
			],
			MultilingualReconciliationService::SCHEMA => [
				[
					'id' => 'q-1',
					'minutesKoppeling' => 'min-1',
					'sourceLocale' => 'nl',
					'targetLocale' => 'en',
					'status' => 'queued',
					'attempts' => 0,
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);
		$svc->setAdapter($this->makeAdapter('', false, 'broken'));

		$result = $svc->processQueue();
		$this->assertSame(1, $result['failed']);
		$this->assertSame(0, $result['completed']);

	}//end testProcessQueueRecordsAdapterFailure()

}//end class
