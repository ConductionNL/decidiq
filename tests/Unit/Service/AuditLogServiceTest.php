<?php

/**
 * Unit tests for AuditLogService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AuditLogService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
class AuditLogServiceTest extends TestCase {

	/**
	 * Build a service wired against a captured ObjectService double.
	 *
	 * Both arrays are passed by reference so callers can observe state
	 * mutations made by saveObject() callbacks and tamper with rows between
	 * verify() runs.
	 *
	 * @param array<int, array<string, mixed>> &$existing Rows the stub returns from findAll()
	 * @param array<int, array<string, mixed>> &$saved Captured saveObject() arguments
	 *
	 * @return AuditLogService
	 */
	private function makeService(array &$existing, array &$saved): AuditLogService {
		$logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$existing): array {
				// Honour 'order' (timestamp ASC/DESC, stable for ties per PHP 8
				// sort stability) and 'limit' so tests can assert on the bounded
				// "last row only" query path introduced by
				// audit-log-chain-tail-hash, without disturbing the existing
				// whole-chain ASC callers (verify/export/query).
				$rows = $existing;
				usort(
					$rows,
					static function (array $a, array $b): int {
						return strcmp((string)($a['timestamp'] ?? ''), (string)($b['timestamp'] ?? ''));
					}
				);

				if (strtoupper((string)($config['order']['timestamp'] ?? 'ASC')) === 'DESC') {
					$rows = array_reverse($rows);
				}

				if (($config['limit'] ?? null) !== null) {
					$rows = array_slice($rows, 0, (int)$config['limit']);
				}

				return $rows;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$saved, &$existing): ObjectEntity {
				$saved[] = $object;
				// Mirror persistence so subsequent verify() sees the new row.
				$row = array_merge(['id' => 'row-' . count($existing)], $object);
				$existing[] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new AuditLogService($container, $logger,
			objectService: $objectService,
		);
	}//end makeService()

	/**
	 * Append from an empty chain uses GENESIS as previousHash and produces a
	 * sha256 currentHash.
	 *
	 * @return void
	 */
	public function testAppendFromEmptyChainUsesGenesisAndHashesCorrectly(): void {
		$existing = [];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->append(
			actor: 'alice',
			action: 'vote',
			objectUids: ['resolution-1']
		);

		$this->assertTrue($result['success']);
		$this->assertSame(AuditLogService::GENESIS_HASH, $saved[0]['previousHash']);
		$this->assertSame(64, strlen($saved[0]['currentHash']));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $saved[0]['currentHash']);

	}//end testAppendFromEmptyChainUsesGenesisAndHashesCorrectly()

	/**
	 * Unknown action is rejected.
	 *
	 * @return void
	 */
	public function testAppendRejectsUnknownAction(): void {
		$existing = [];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->append(actor: 'alice', action: 'unknown', objectUids: []);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Unknown action', $result['message']);
		$this->assertSame([], $saved);

	}//end testAppendRejectsUnknownAction()

	/**
	 * Append chains the new previousHash to the last currentHash on the chain.
	 *
	 * @return void
	 */
	public function testAppendChainsToPreviousEntry(): void {
		$existing = [
			[
				'id' => 'row-0',
				'timestamp' => '2026-01-01T00:00:00Z',
				'actorUuid' => 'alice',
				'action' => 'vote',
				'objectUids' => ['resolution-1'],
				'previousHash' => AuditLogService::GENESIS_HASH,
				'currentHash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			],
		];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->append(actor: 'bob', action: 'signature', objectUids: ['minutes-1']);

		$this->assertTrue($result['success']);
		$this->assertSame(
			'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			$saved[0]['previousHash']
		);

	}//end testAppendChainsToPreviousEntry()

	/**
	 * Verify a clean chain succeeds; tampering one row marks it.
	 *
	 * @return void
	 */
	public function testVerifyDetectsTamperedRow(): void {
		$existing = [];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		// Append two entries.
		$service->append(actor: 'alice', action: 'vote', objectUids: ['res-1']);
		$service->append(actor: 'bob', action: 'signature', objectUids: ['min-1']);

		$verifyClean = $service->verify();
		$this->assertTrue($verifyClean['valid']);
		$this->assertSame(2, $verifyClean['checked']);

		// Tamper with the first row's currentHash.
		$existing[0]['currentHash'] = str_repeat('0', 64);

		$verifyDirty = $service->verify();
		$this->assertFalse($verifyDirty['valid']);
		$this->assertContains('row-0', $verifyDirty['tampered']);

	}//end testVerifyDetectsTamperedRow()

	/**
	 * Query filters by actor + action.
	 *
	 * @return void
	 */
	public function testQueryFiltersByActorAndAction(): void {
		$existing = [
			['id' => 'a', 'actorUuid' => 'alice', 'action' => 'vote',       'timestamp' => '2026-01-01T00:00:00Z', 'objectUids' => ['r1']],
			['id' => 'b', 'actorUuid' => 'bob',   'action' => 'vote',       'timestamp' => '2026-01-02T00:00:00Z', 'objectUids' => ['r1']],
			['id' => 'c', 'actorUuid' => 'alice', 'action' => 'signature',  'timestamp' => '2026-01-03T00:00:00Z', 'objectUids' => ['m1']],
		];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->query(['actor' => 'alice', 'action' => 'vote']);

		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['count']);
		$this->assertSame('a', $result['entries'][0]['id']);

	}//end testQueryFiltersByActorAndAction()

	/**
	 * Export produces CSV with the expected header.
	 *
	 * @return void
	 */
	public function testExportCsvIncludesHeader(): void {
		$existing = [
			['id' => 'a', 'actorUuid' => 'alice', 'action' => 'vote', 'timestamp' => '2026-01-01T00:00:00Z', 'objectUids' => ['r1'], 'previousHash' => 'GENESIS', 'currentHash' => 'h1'],
		];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->export('2026-01-01T00:00:00Z', '2026-12-31T23:59:59Z', 'csv');

		$this->assertTrue($result['success']);
		$this->assertSame('csv', $result['format']);
		$this->assertStringContainsString('id,timestamp,actor,action,objectUids,previousHash,currentHash', $result['body']);
		$this->assertSame(1, $result['count']);

	}//end testExportCsvIncludesHeader()

	/**
	 * append() produces an unbroken hash chain across 3+ sequential calls,
	 * using the bounded "last row only" query path (not a full-chain load).
	 *
	 * @spec openspec/changes/audit-log-chain-tail-hash/tasks.md#task-2
	 *
	 * @return void
	 */
	public function testAppendProducesUnbrokenChainAcrossSequentialCalls(): void {
		$existing = [];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$service->append(actor: 'alice', action: 'vote', objectUids: ['res-1']);
		$service->append(actor: 'bob', action: 'signature', objectUids: ['min-1']);
		$service->append(actor: 'carol', action: 'material-access', objectUids: ['doc-1']);

		$this->assertCount(3, $saved);
		$this->assertSame(AuditLogService::GENESIS_HASH, $saved[0]['previousHash']);
		$this->assertSame($saved[0]['currentHash'], $saved[1]['previousHash']);
		$this->assertSame($saved[1]['currentHash'], $saved[2]['previousHash']);

	}//end testAppendProducesUnbrokenChainAcrossSequentialCalls()

	/**
	 * Regression: with more than 1 row already on the chain, append() resolves
	 * the TRUE last row's hash (by timestamp) — not an arbitrary row and not a
	 * row selected by array position. Guards against a future re-introduction
	 * of an unbounded/truncated query.
	 *
	 * @spec openspec/changes/audit-log-chain-tail-hash/tasks.md#task-2
	 *
	 * @return void
	 */
	public function testAppendResolvesTrueLastRowFromMultiRowChain(): void {
		$existing = [
			['id' => 'row-0', 'timestamp' => '2026-01-01T00:00:00Z', 'actorUuid' => 'alice', 'action' => 'vote', 'objectUids' => ['r1'], 'previousHash' => AuditLogService::GENESIS_HASH, 'currentHash' => str_repeat('0', 64)],
			['id' => 'row-1', 'timestamp' => '2026-01-02T00:00:00Z', 'actorUuid' => 'bob', 'action' => 'vote', 'objectUids' => ['r1'], 'previousHash' => str_repeat('0', 64), 'currentHash' => str_repeat('1', 64)],
			['id' => 'row-2', 'timestamp' => '2026-01-03T00:00:00Z', 'actorUuid' => 'carol', 'action' => 'vote', 'objectUids' => ['r1'], 'previousHash' => str_repeat('1', 64), 'currentHash' => str_repeat('2', 64)],
			['id' => 'row-3', 'timestamp' => '2026-01-04T00:00:00Z', 'actorUuid' => 'dave', 'action' => 'vote', 'objectUids' => ['r1'], 'previousHash' => str_repeat('2', 64), 'currentHash' => str_repeat('3', 64)],
			['id' => 'row-4', 'timestamp' => '2026-01-05T00:00:00Z', 'actorUuid' => 'eve', 'action' => 'vote', 'objectUids' => ['r1'], 'previousHash' => str_repeat('3', 64), 'currentHash' => str_repeat('4', 64)],
		];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->append(actor: 'frank', action: 'vote', objectUids: ['r1']);

		$this->assertTrue($result['success']);
		$this->assertSame(
			str_repeat('4', 64),
			$saved[0]['previousHash'],
			'previousHash must be sourced from the true last row (row-4), not an arbitrary/first row'
		);

	}//end testAppendResolvesTrueLastRowFromMultiRowChain()

	/**
	 * append() resolving previousHash issues a bounded (limit: 1) findAll()
	 * query rather than an unbounded/whole-chain query.
	 *
	 * @spec openspec/changes/audit-log-chain-tail-hash/specs/audit-trail-integrity/spec.md#requirement-req-alci-001-resolving-the-previous-hash-must-not-load-the-whole-chain
	 *
	 * @return void
	 */
	public function testResolvePreviousHashIssuesBoundedQuery(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$existing = [
			['id' => 'row-0', 'timestamp' => '2026-01-01T00:00:00Z', 'actorUuid' => 'alice', 'action' => 'vote', 'objectUids' => ['r1'], 'previousHash' => AuditLogService::GENESIS_HASH, 'currentHash' => str_repeat('a', 64)],
		];
		$observedLimits = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$existing, &$observedLimits): array {
				$observedLimits[] = ($config['limit'] ?? null);
				if (($config['limit'] ?? null) === 1) {
					return array_slice($existing, -1);
				}

				return $existing;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$existing): ObjectEntity {
				$row = array_merge(['id' => 'row-' . count($existing)], $object);
				$existing[] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$service = new AuditLogService($container, $logger,
			objectService: $objectService,
		);
		$result = $service->append(actor: 'bob', action: 'vote', objectUids: ['r1']);

		$this->assertTrue($result['success']);
		$this->assertContains(1, $observedLimits, 'resolvePreviousHash() must issue a findAll() call bounded to limit: 1');
		$this->assertNotContains(10000, $observedLimits, 'resolvePreviousHash() must not issue the whole-chain (limit: 10000) query');

	}//end testResolvePreviousHashIssuesBoundedQuery()

	/**
	 * loadLastEntry() (exercised indirectly via resolvePreviousHash()) returns
	 * null for an empty chain, resolving to GENESIS_HASH.
	 *
	 * @spec openspec/changes/audit-log-chain-tail-hash/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testAppendFromEmptyChainStillUsesGenesisViaBoundedQuery(): void {
		$existing = [];
		$saved = [];
		$service = $this->makeService($existing, $saved);

		$result = $service->append(actor: 'alice', action: 'vote', objectUids: ['r1']);

		$this->assertTrue($result['success']);
		$this->assertSame(AuditLogService::GENESIS_HASH, $saved[0]['previousHash']);

	}//end testAppendFromEmptyChainStillUsesGenesisViaBoundedQuery()

}//end class
