<?php

/**
 * Golden test for AuditLogService over the exact output.
 *
 * Runs append, verify, export and query over the OpenRegister audit trail:
 * unknown and known actions, uid lists that are empty, unsorted, blank or
 * partly unresolvable, a mapper that throws; chain verification of the whole
 * chain or up to an entry that exists or not, results with and without a
 * break, a verifier that throws; JSON and CSV exports with inclusive date
 * bounds and an unknown format; queries by actor, action, date range, object
 * uuid, limit and offset; and audit rows in every shape (namespaced or not,
 * context missing or not an array, object uids from the context or the row,
 * no created date, no uuid, no hash). It records every mapper, verifier and
 * object-service call by parameter name, every log line, and what each call
 * returns, byte for byte. The expected values live in
 * tests/fixtures/audit-log-golden.json; run with RECORD_AUDIT_LOG_GOLDEN=1
 * to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\Decidiq\Service\AuditLogService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuditHashService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for the audit log.
 *
 * @covers \OCA\Decidiq\Service\AuditLogService
 * @uses   \OCA\Decidiq\Service\AuditRowFormat
 */
class AuditLogGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/audit-log-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The case being run.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * Every audit call answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryAuditCallMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log  = [];
			$this->case = $case;
			$service    = $this->service();
			$returns    = match ($case['call'][0]) {
				'append' => $service->append(...array_slice($case['call'], 1)),
				'verify' => $service->verify(...array_slice($case['call'], 1)),
				'export' => $service->export(...array_slice($case['call'], 1)),
				'query'  => $service->query(...array_slice($case['call'], 1)),
			};
			$actual[$name] = ['returns' => $returns, 'calls' => $this->log];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_AUDIT_LOG_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryAuditCallMatchesTheRecording()

	/**
	 * The audit rows the trail holds, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(): array {
		return [
			['id' => 1, 'uuid' => 'e-1', 'action' => 'decidiq.audit.vote', 'changed' => ['actorUuid' => 'anna', 'objectUids' => ['o-2', 'o-1'], 'payload' => ['choice' => 'for', 'url' => 'https://x/y']], 'created' => '2026-01-01 00:00:00', 'hash' => 'h1', 'previousHash' => 'h0', 'user' => 'nc-anna'],
			['id' => 2, 'uuid' => 'e-2', 'action' => 'decidiq.audit.signature', 'changed' => ['objectUids' => [], 'payload' => ['link' => 'https://x/y']], 'objectUuid' => 'o-3', 'created' => '2026-01-15 12:30:00', 'user' => 'bob, jr'],
			['id' => 3, 'uuid' => null, 'action' => 'decidiq.audit.vote', 'changed' => null, 'objectUuid' => null, 'created' => '2026-02-01 00:00:00', 'hash' => null],
			['id' => 4, 'uuid' => 'e-4', 'action' => 'other.action', 'changed' => ['actorUuid' => 'carl', 'objectUids' => [7, 'o-1'], 'payload' => 'not a list'], 'created' => null],
			['id' => 5, 'uuid' => 'e-"5"', 'action' => 'decidiq.audit.notice-sent', 'changed' => ['actorUuid' => 'anna', 'objectUids' => 'o-9'], 'objectUuid' => 'o-5', 'created' => '2026-03-01 23:59:59'],
			['id' => 6, 'uuid' => '=HYPERLINK("https://evil.example","open")', 'action' => 'decidiq.audit.vote', 'changed' => ['actorUuid' => "dora, \"the\"\r\nchair", 'objectUids' => ['+o-3', 'o-1,o-2', 'say "hi"'], 'payload' => ['choice' => 'against']], 'created' => '2026-03-02 08:00:00', 'hash' => '@h6', 'previousHash' => '-h5', 'user' => 'nc-dora'],
			['id' => 7, 'uuid' => "\re-7", 'action' => 'decidiq.audit.signature', 'changed' => ['actorUuid' => "\tsum\nline", 'objectUids' => ["=1+1", "o-4\nnext"]], 'created' => '2026-03-03 09:15:00', 'hash' => 'h7', 'previousHash' => '@h6', 'user' => 'nc-erik'],
		];
	}//end rows()

	/**
	 * The cases: the call and how the doubles behave.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'append-unknown-action'   => ['call' => ['append', 'anna', 'dance', ['o-1']]],
			'append-no-uids'          => ['call' => ['append', 'anna', 'vote', ['', '']]],
			'append-resolves-third'   => ['call' => ['append', 'anna', 'vote', ['o-3', '', 'o-1', 'o-2', 'o-0', 'i-1'], ['choice' => 'for']]],
			'append-zero-uid'         => ['call' => ['append', 'system', 'signature', [0, 'o-1']]],
			'append-nothing-resolves' => ['call' => ['append', 'anna', 'vote', ['o-0', 'x-1']]],
			'append-mapper-throws'    => ['call' => ['append', 'anna', 'proxy-created', ['o-1']], 'create' => 'throw'],
			'verify-all'              => ['call' => ['verify'], 'verifyChain' => ['valid' => true, 'entriesVerified' => 5]],
			'verify-broken'           => ['call' => ['verify'], 'verifyChain' => ['valid' => false, 'entriesVerified' => 3, 'brokenAt' => 4]],
			'verify-empty-result'     => ['call' => ['verify'], 'verifyChain' => []],
			'verify-to-entry'         => ['call' => ['verify', 'e-2'], 'verifyChain' => ['valid' => true, 'entriesVerified' => 2]],
			'verify-unknown-entry'    => ['call' => ['verify', 'e-404']],
			'verify-throws'           => ['call' => ['verify'], 'verifyChain' => 'throw'],
			'export-json'             => ['call' => ['export', '2026-01-15T11:30:00Z', '2026-03-01T22:59:59Z']],
			'export-csv-upper'        => ['call' => ['export', '2026-01-01T00:00:01Z', '2026-12-31T00:00:00Z', 'CSV']],
			'export-csv-hashed'       => ['call' => ['export', '2025-12-31T23:00:00Z', '2026-12-31T00:00:00Z', 'csv']],
			'export-unknown-format'   => ['call' => ['export', '2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', 'xml']],
			'export-load-throws'      => ['call' => ['export', '2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', 'csv'], 'findAll' => 'throw'],
			'export-none'             => ['call' => ['export', '2027-01-01T00:00:00Z', '2027-12-31T00:00:00Z']],
			'query-all'               => ['call' => ['query']],
			'query-actor'             => ['call' => ['query', ['actor' => 'anna']]],
			'query-action'            => ['call' => ['query', ['action' => 'vote']]],
			'query-unknown-action'    => ['call' => ['query', ['action' => 'dance']]],
			'query-dates'             => ['call' => ['query', ['startDate' => '2026-01-15T11:30:00Z', 'endDate' => '2026-01-31T23:00:00Z']]],
			'query-int-uid'           => ['call' => ['query', ['objectUuid' => '7']]],
			'query-default-limit'     => ['call' => ['query', []], 'many' => true],
			'query-object'            => ['call' => ['query', ['objectUuid' => 'o-1']]],
			'query-object-from-row'   => ['call' => ['query', ['objectUuid' => 'o-3']]],
			'query-page'              => ['call' => ['query', ['limit' => 2, 'offset' => 1]]],
			'query-limit-zero'        => ['call' => ['query', ['limit' => 0, 'offset' => '3']]],
			'query-null-filters'      => ['call' => ['query', ['actor' => null, 'action' => null, 'startDate' => null]]],
			'query-load-throws'       => ['call' => ['query', ['actor' => 'anna']], 'findAll' => 'throw'],
		];
	}//end cases()

	/**
	 * The service over the current case.
	 *
	 * @return AuditLogService
	 */
	private function service(): AuditLogService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args): ?ObjectEntityInterface {
				$this->log[] = ['find' => $this->named(class: ObjectServiceInterface::class, method: 'find', args: $args)];
				if ($args[0] === 'o-0') {
					throw new RuntimeException('register down');
				}

				if ($args[0] === 'i-1') {
					return $this->createMock(ObjectEntityInterface::class);
				}

				if (str_starts_with((string)$args[0], 'o-') === false || $args[0] === 'o-3') {
					return null;
				}

				$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['getObject'])->getMock();
				$entity->method('getObject')->willReturn(['id' => $args[0]]);
				return $entity;
			}
		);

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->log[] = ['createAuditTrailEntry' => [$object->getObject(), $action, $context]];
				if (($this->case['create'] ?? null) === 'throw') {
					throw new RuntimeException('trail down');
				}

				return $this->trail(row: ['id' => 9, 'uuid' => 'e-new', 'action' => $action, 'changed' => $context, 'created' => '2026-04-01 08:00:00', 'hash' => '', 'previousHash' => 'h5']);
			}
		);
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args): array {
				$named       = $this->named(class: AuditTrailMapper::class, method: 'findAll', args: $args);
				$this->log[] = ['findAll' => $named];
				if (($this->case['findAll'] ?? null) === 'throw') {
					throw new RuntimeException('trail unreadable');
				}

				return $this->findRows(filters: (array)($named['filters'] ?? []));
			}
		);

		$hash = $this->createMock(AuditHashService::class);
		$hash->method('verifyChain')->willReturnCallback(
			function (mixed ...$args): array {
				$this->log[] = ['verifyChain' => $this->named(class: AuditHashService::class, method: 'verifyChain', args: $args)];
				if (($this->case['verifyChain'] ?? null) === 'throw') {
					throw new RuntimeException('chain unreadable');
				}

				return ($this->case['verifyChain'] ?? ['valid' => true]);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new AuditLogService(logger: $logger, objectService: $objects, auditTrailMapper: $mapper, auditHashService: $hash);
	}//end service()

	/**
	 * The rows the mapper answers for a filter: by uuid, or by a comma-separated action list.
	 *
	 * @param array<string, mixed> $filters The filters
	 *
	 * @return list<mixed>
	 */
	private function findRows(array $filters): array {
		if (isset($filters['uuid']) === true) {
			$found = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['uuid'] === $filters['uuid']));
			return array_map(fn (array $row): AuditTrail => $this->trail(row: $row), $found);
		}

		$actions = explode(',', (string)($filters['action'] ?? ''));
		$rows    = array_values(array_filter($this->rows(), static fn (array $r): bool => in_array($r['action'], $actions, true) || $r['action'] === 'other.action'));
		if (($this->case['many'] ?? false) === true) {
			$rows = [...$rows, ...$rows, ...$rows];
		}

		return [...array_map(fn (array $row): AuditTrail => $this->trail(row: $row), $rows), 'not a row', (object)['id' => 1]];
	}//end findRows()

	/**
	 * An audit trail entity.
	 *
	 * @param array<string, mixed> $row The fields
	 *
	 * @return AuditTrail
	 */
	private function trail(array $row): AuditTrail {
		$trail = new AuditTrail();
		$trail->setId($row['id']);
		$trail->setUuid($row['uuid'] ?? null);
		$trail->setAction($row['action'] ?? null);
		$trail->setChanged($row['changed'] ?? null);
		$trail->setUser($row['user'] ?? null);
		$trail->setHash($row['hash'] ?? null);
		$trail->setPreviousHash($row['previousHash'] ?? null);
		$trail->setObjectUuid($row['objectUuid'] ?? null);
		if (($row['created'] ?? null) !== null) {
			$trail->setCreated(new DateTime($row['created'], new DateTimeZone('Europe/Amsterdam')));
		}

		return $trail;
	}//end trail()

	/**
	 * Name positional mock arguments by the loaded method's parameters, leaving out defaults.
	 *
	 * @param string       $class  The class or interface
	 * @param string       $method The method
	 * @param array<mixed> $args   The positional arguments
	 *
	 * @return array<string, mixed> The named arguments.
	 */
	private function named(string $class, string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$out[$param->getName()] = $args[$i];
		}

		return $out;
	}//end named()
}//end class
