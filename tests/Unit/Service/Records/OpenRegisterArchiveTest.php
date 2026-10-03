<?php

/**
 * Unit tests for OpenRegisterArchive's fail-closed paths: an OpenRegister
 * without a service, with a service that lacks the method, or with one that
 * answers something unexpected is "unavailable", never an error and never a
 * guess.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Service\Records\OpenRegisterArchive;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the OpenRegister archival facade when OpenRegister cannot help.
 *
 * @covers \OCA\Decidiq\Service\Records\OpenRegisterArchive
 */
class OpenRegisterArchiveTest extends TestCase {

	private const TRANSFER_LISTS = 'OCA\OpenRegister\Service\Edepot\TransferListService';

	private const TRANSFER_RECORDS = 'OCA\OpenRegister\Service\Edepot\TransferRecordService';

	private const DESTRUCTION_LISTS = 'OCA\OpenRegister\Service\Archival\DestructionListRepository';

	private const REGISTERS = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * The archive over a container holding exactly these services.
	 *
	 * @param array<string, object> $services Services by class name
	 *
	 * @return OpenRegisterArchive
	 */
	private static function archive(array $services): OpenRegisterArchive {
		$container = new class($services) implements ContainerInterface {
			/**
			 * @param array<string, object> $services Services by class name
			 */
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				if (isset($this->services[$id]) === false) {
					throw new class('not here') extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $this->services[$id];
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};

		return new OpenRegisterArchive(container: $container, logger: new NullLogger());
	}//end archive()

	/**
	 * Without OpenRegister's archival services nothing is available, and
	 * every call answers "none" rather than failing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegistersServicesEverythingIsUnavailable(): void {
		$archive = self::archive(services: []);

		self::assertFalse($archive->transferAvailable());
		self::assertNull($archive->createTransferList(objects: [new \stdClass()]));
		self::assertNull($archive->transferListStatus(uuid: 'list-1'));
		self::assertNull($archive->createDestructionList(uuids: ['a']));
		self::assertNull($archive->destructionList(uuid: 'list-1'));
		self::assertSame(['configured' => false, 'results' => [], 'missing' => []], $archive->certificates(listUuid: ''));
		self::assertFalse($archive->tmloEnabled(register: 'decidiq'));
	}//end testWithoutOpenRegistersServicesEverythingIsUnavailable()

	/**
	 * A service of an older OpenRegister that lacks the method is treated as absent.
	 *
	 * @return void
	 */
	public function testAServiceWithoutTheMethodIsAbsent(): void {
		$archive = self::archive(services: [self::TRANSFER_LISTS => new \stdClass(), self::DESTRUCTION_LISTS => new \stdClass()]);

		self::assertNull($archive->createTransferList(objects: [new \stdClass()]));
		self::assertNull($archive->destructionList(uuid: 'list-1'));
	}//end testAServiceWithoutTheMethodIsAbsent()

	/**
	 * An empty transfer list is never asked for, and an unknown list has no status.
	 *
	 * @return void
	 */
	public function testNoRecordsMakeNoTransferListAndAnUnknownListHasNoStatus(): void {
		$lists = new class {
			public int $calls = 0;

			/**
			 * @param list<object> $objects The records
			 *
			 * @return array<string, mixed>
			 */
			public function createTransferList(array $objects): array {
				$this->calls++;
				return ['uuid' => 'list-1'];
			}
		};
		$records = new class {
			public function loadTransferList(string $uuid): ?array {
				return null;
			}
		};
		$destruction = new class {
			public function find(string $uuid): ?object {
				return null;
			}
		};
		$archive = self::archive(services: [self::TRANSFER_LISTS => $lists, self::TRANSFER_RECORDS => $records, self::DESTRUCTION_LISTS => $destruction]);

		self::assertNull($archive->createTransferList(objects: []));
		self::assertSame(0, $lists->calls);
		self::assertNull($archive->transferListStatus(uuid: 'gone'));
		self::assertNull($archive->destructionList(uuid: 'gone'));
	}//end testNoRecordsMakeNoTransferListAndAnUnknownListHasNoStatus()

	/**
	 * TMLO counts as enabled only when the register says so: a register that
	 * cannot be read, or an entity without a configuration, is not enabled.
	 *
	 * @return void
	 */
	public function testTmloIsEnabledOnlyWhenTheRegisterSaysSo(): void {
		$throwing = new class {
			public function find(string $id): object {
				throw new RuntimeException('no such register');
			}
		};
		self::assertFalse(self::archive(services: [self::REGISTERS => $throwing])->tmloEnabled(register: 'decidiq'));

		$bare = new class {
			public function find(string $id): object {
				return new \stdClass();
			}
		};
		self::assertFalse(self::archive(services: [self::REGISTERS => $bare])->tmloEnabled(register: 'decidiq'));

		$enabled = new class {
			public function find(string $id): object {
				return new class {
					/**
					 * @return array<string, mixed>
					 */
					public function getConfiguration(): array {
						return ['tmloEnabled' => true];
					}
				};
			}
		};
		self::assertTrue(self::archive(services: [self::REGISTERS => $enabled])->tmloEnabled(register: 'decidiq'));
	}//end testTmloIsEnabledOnlyWhenTheRegisterSaysSo()
}//end class
