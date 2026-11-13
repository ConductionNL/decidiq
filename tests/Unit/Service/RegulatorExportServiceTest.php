<?php

/**
 * Unit tests for RegulatorExportService.
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
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\RegulatorExportService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RegulatorExportService.
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportServiceTest extends TestCase {

	/**
	 * Build a service over an in-memory rowset keyed by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> &$rowsBySchema Map schema => rows
	 * @param array<int, array<string, mixed>> &$saved Captured saves
	 * @param AuditLogService|null $auditMock Audit mock
	 *
	 * @return RegulatorExportService
	 */
	private function makeService(
		array &$rowsBySchema,
		array &$saved,
		?AuditLogService $auditMock = null,
	): RegulatorExportService {
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
				$savedRef[] = $object + ['_schema' => $schemaKey];
				$id = ((string)($uuid ?? '') !== '' ? (string)$uuid : ($schemaKey . '-' . (count($savedRef))));
				$row = array_merge(['id' => $id], $object);

				$rowsRef[$schemaKey] ??= [];
				$rowsRef[$schemaKey][] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$audit = ($auditMock ?? $this->createMock(AuditLogService::class));

		return new RegulatorExportService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			auditLogService: $audit,
		);

	}//end makeService()

	/**
	 * generate rejects empty boardId.
	 *
	 * @return void
	 */
	public function testGenerateRejectsEmptyBoardId(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generate('', 'resolutions', 'pdf', 'alice');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('boardId', $result['message']);

	}//end testGenerateRejectsEmptyBoardId()

	/**
	 * generate rejects unsupported scope.
	 *
	 * @return void
	 */
	public function testGenerateRejectsUnknownScope(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generate('b-1', 'expenses', 'pdf', 'alice');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('scope', $result['message']);

	}//end testGenerateRejectsUnknownScope()

	/**
	 * generate rejects unsupported format.
	 *
	 * @return void
	 */
	public function testGenerateRejectsUnknownFormat(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generate('b-1', 'resolutions', 'docx', 'alice');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('format', $result['message']);

	}//end testGenerateRejectsUnknownFormat()

	/**
	 * generate produces a PDF body, persists a record and audits.
	 *
	 * @return void
	 */
	public function testGenerateResolutionsPdf(): void {
		$rows = [
			'meeting' => [
				['id' => 'm-1', 'boardIntegration' => 'b-1', 'meetingDate' => '2026-03-15T10:00:00Z'],
			],
			'decision' => [
				[
					'id' => 'r-1',
					'meetingIntegration' => 'm-1',
					'resolutionNumber' => 'R-2026-001',
					'title' => 'Approve annual budget',
					'type' => 'approval',
					'status' => 'adopted',
				],
				[
					'id' => 'r-2',
					'meetingIntegration' => 'm-9',
					'title' => 'Out of scope (other meeting)',
				],
			],
		];
		$saved = [];

		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->once())->method('append');

		$svc = $this->makeService($rows, $saved, $audit);

		$result = $svc->generate('b-1', 'resolutions', 'pdf', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('application/pdf', $result['contentType']);
		$this->assertStringStartsWith('%PDF-1.4', $result['body']);
		$this->assertStringContainsString('%%EOF', $result['body']);
		$this->assertStringContainsString('decidiq-resolutions-b-1-', $result['filename']);
		$this->assertStringEndsWith('.pdf', $result['filename']);

		$recordSaves = array_values(
			array_filter($saved, static fn (array $s): bool => ($s['_schema'] ?? '') === RegulatorExportService::SCHEMA)
		);
		$this->assertCount(1, $recordSaves);
		$this->assertSame(1, $recordSaves[0]['recordCount']);
		$this->assertSame('resolutions', $recordSaves[0]['scope']);

	}//end testGenerateResolutionsPdf()

	/**
	 * generate produces a CSV body with the expected header line.
	 *
	 * @return void
	 */
	public function testGenerateResolutionsCsv(): void {
		$rows = [
			'meeting' => [
				['id' => 'm-1', 'boardIntegration' => 'b-1'],
			],
			'decision' => [
				[
					'id' => 'r-1',
					'meetingIntegration' => 'm-1',
					'resolutionNumber' => 'R-2026-001',
					'title' => 'Approve, budget',
					'type' => 'approval',
					'status' => 'adopted',
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generate('b-1', 'resolutions', 'csv', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('text/csv', $result['contentType']);
		$this->assertStringStartsWith('id,meetingIntegration,resolutionNumber,title,type,status,voteThreshold,adoptionDate', $result['body']);
		// Comma in the title field must be quoted.
		$this->assertStringContainsString('"Approve, budget"', $result['body']);

	}//end testGenerateResolutionsCsv()

	/**
	 * generate keeps user-written CSV cells inert: a formula is prefixed, a
	 * lone carriage return and a semicolon are quoted.
	 *
	 * @return void
	 */
	public function testGenerateCsvKeepsHostileCellsInert(): void {
		$rows = [
			'meeting' => [
				['id' => 'm-1', 'boardIntegration' => 'b-1'],
			],
			'decision' => [
				['id' => 'r-1', 'meetingIntegration' => 'm-1', 'title' => '=HYPERLINK("https://evil.example")', 'type' => "a\rb", 'status' => 'x;=1+1'],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$lines = explode("\n", $svc->generate('b-1', 'resolutions', 'csv', 'alice')['body']);

		$this->assertSame("r-1,m-1,,\"'=HYPERLINK(\"\"https://evil.example\"\")\",\"a\rb\",\"x;'=1+1\",,", $lines[1]);

	}//end testGenerateCsvKeepsHostileCellsInert()

	/**
	 * generate scopes audit-log to all entries.
	 *
	 * @return void
	 */
	public function testGenerateAuditLogCsv(): void {
		$rows = [
			'meeting' => [],
			'audit-trail' => [
				[
					'id' => 'a-1',
					'timestamp' => '2026-04-01T10:00:00Z',
					'actorUuid' => 'alice',
					'action' => 'vote',
					'previousHash' => 'GENESIS',
					'currentHash' => 'h1',
				],
				[
					'id' => 'a-2',
					'timestamp' => '2026-04-01T11:00:00Z',
					'actorUuid' => 'bob',
					'action' => 'signature',
					'previousHash' => 'h1',
					'currentHash' => 'h2',
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->generate('b-1', 'audit-log', 'csv', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('text/csv', $result['contentType']);
		$this->assertStringContainsString('vote', $result['body']);
		$this->assertStringContainsString('signature', $result['body']);

		$recordSaves = array_values(
			array_filter($saved, static fn (array $s): bool => ($s['_schema'] ?? '') === RegulatorExportService::SCHEMA)
		);
		$this->assertCount(1, $recordSaves);
		$this->assertSame(2, $recordSaves[0]['recordCount']);

	}//end testGenerateAuditLogCsv()

	/**
	 * download regenerates a persisted export and returns its body.
	 *
	 * @return void
	 */
	public function testDownloadRegeneratesPersistedExport(): void {
		$rows = [
			'meeting' => [
				['id' => 'm-1', 'boardIntegration' => 'b-1'],
			],
			'decision' => [
				['id' => 'r-1', 'meetingIntegration' => 'm-1', 'title' => 'R'],
			],
			RegulatorExportService::SCHEMA => [
				[
					'id' => 'exp-1',
					'boardIntegration' => 'b-1',
					'scope' => 'resolutions',
					'format' => 'csv',
					'filename' => 'persisted.csv',
				],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->download('exp-1', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('text/csv', $result['contentType']);
		$this->assertSame('persisted.csv', $result['filename']);
		$this->assertStringContainsString('id,meetingIntegration', $result['body']);

	}//end testDownloadRegeneratesPersistedExport()

	/**
	 * download returns failure when export id is unknown.
	 *
	 * @return void
	 */
	public function testDownloadFailsForMissingExport(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->download('missing', 'alice');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not found', $result['message']);

	}//end testDownloadFailsForMissingExport()

	/**
	 * listExports filters by board.
	 *
	 * @return void
	 */
	public function testListExportsFiltersByBoard(): void {
		$rows = [
			RegulatorExportService::SCHEMA => [
				['id' => 'e-1', 'boardIntegration' => 'b-1'],
				['id' => 'e-2', 'boardIntegration' => 'b-2'],
				['id' => 'e-3', 'boardIntegration' => 'b-1'],
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->listExports('b-1');

		$this->assertTrue($result['success']);
		$this->assertSame(2, $result['count']);

	}//end testListExportsFiltersByBoard()

	/**
	 * listExports rejects an empty board id.
	 *
	 * @return void
	 */
	public function testListExportsRejectsEmptyBoardId(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->listExports('');
		$this->assertFalse($result['success']);

	}//end testListExportsRejectsEmptyBoardId()

	/**
	 * generate reports OpenRegister unavailable when the ObjectService cannot be resolved.
	 *
	 * @return void
	 */
	public function testGenerateFailsWhenObjectServiceIsUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no OpenRegister'));
		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->never())->method('append');

		$svc = new RegulatorExportService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			auditLogService: $audit,
		);

		$this->assertSame(
			[
				'success' => false,
				'export' => null,
				'body' => '',
				'contentType' => 'text/plain',
				'filename' => '',
				'message' => 'OpenRegister is unavailable.',
			],
			$svc->generate('b-1', 'resolutions', 'csv', 'alice')
		);

	}//end testGenerateFailsWhenObjectServiceIsUnavailable()

	/**
	 * generate reports the scope when collecting the rows throws.
	 *
	 * @return void
	 */
	public function testGenerateFailsWhenCollectingThrows(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$objectService->expects($this->never())->method('saveObject');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$svc = new RegulatorExportService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			auditLogService: $this->createMock(AuditLogService::class),
		);

		$result = $svc->generate('b-1', 'RESOLUTIONS', 'CSV', 'alice');

		$this->assertFalse($result['success']);
		$this->assertSame('Failed to collect data for scope: resolutions', $result['message']);

	}//end testGenerateFailsWhenCollectingThrows()

	/**
	 * A failed record save is non-fatal: the body is returned and the audit
	 * entry falls back to the checksum as the export id.
	 *
	 * @return void
	 */
	public function testGeneratePersistFailureStillReturnsTheExportAndAudits(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('saveObject')->willThrowException(new \RuntimeException('write refused'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$captured = [];
		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->once())->method('append')->willReturnCallback(
			static function (...$args) use (&$captured) {
				$captured = $args;
				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Decidiq: RegulatorExportService failed to persist export record', ['exception' => 'write refused']);

		$svc = new RegulatorExportService(container: $container, logger: $logger, auditLogService: $audit);
		$result = $svc->generate('b-1', 'resolutions', 'csv', 'alice');

		$checksum = hash('sha256', $result['body']);
		$this->assertTrue($result['success']);
		$this->assertSame('Export generated.', $result['message']);
		$this->assertSame('text/csv', $result['contentType']);
		$this->assertArrayNotHasKey('id', $result['export']);
		$this->assertSame($checksum, $result['export']['sha256']);
		$this->assertSame(0, $result['export']['recordCount']);
		$this->assertSame('alice', $result['export']['generatedBy']);
		$this->assertSame($result['filename'], $result['export']['filename']);
		$this->assertSame(
			[
				'alice',
				'material-access',
				['b-1', $checksum],
				[
					'kind' => 'regulator-export',
					'scope' => 'resolutions',
					'format' => 'csv',
					'recordCount' => 0,
					'sha256' => $checksum,
				],
			],
			array_values($captured)
		);

	}//end testGeneratePersistFailureStillReturnsTheExportAndAudits()

	/**
	 * A saved record replaces the draft and its id is what the audit names.
	 *
	 * @return void
	 */
	public function testGenerateAuditsTheSavedRecordId(): void {
		$rows = [];
		$saved = [];
		$captured = [];
		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->once())->method('append')->willReturnCallback(
			static function (...$args) use (&$captured) {
				$captured = $args;
				return [];
			}
		);

		$svc = $this->makeService($rows, $saved, $audit);
		$result = $svc->generate('b-1', 'resolutions', 'csv', 'alice');

		$this->assertSame(RegulatorExportService::SCHEMA . '-1', $result['export']['id']);
		$this->assertSame(['b-1', RegulatorExportService::SCHEMA . '-1'], array_values($captured)[2]);

	}//end testGenerateAuditsTheSavedRecordId()
}//end class
