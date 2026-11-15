<?php

/**
 * Unit tests for OpenRegisterArchive when OpenRegister's archival services
 * are present: which e-depot transport counts as configured, how transfer
 * and destruction lists are created and read back, and how OpenRegister's
 * answers are normalised before decidiq routes on them.
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

use InvalidArgumentException;
use OCA\Decidiq\Service\Records\OpenRegisterArchive;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the OpenRegister archival facade with OpenRegister present.
 *
 * @covers \OCA\Decidiq\Service\Records\OpenRegisterArchive
 */
class OpenRegisterArchiveBehaviourTest extends TestCase {

	private const EDEPOT = 'OCA\OpenRegister\Service\Edepot\EdepotTransferService';

	private const TRANSFER_LISTS = 'OCA\OpenRegister\Service\Edepot\TransferListService';

	private const TRANSFER_RECORDS = 'OCA\OpenRegister\Service\Edepot\TransferRecordService';

	private const DESTRUCTION_CREATOR = 'OCA\OpenRegister\Service\Archival\DestructionListCreator';

	private const DESTRUCTION_LISTS = 'OCA\OpenRegister\Service\Archival\DestructionListRepository';

	private const REGISTERS = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * The archive over a container holding exactly these services.
	 *
	 * @param array<string, object> $services Services by class name
	 * @param LoggerInterface|null  $logger   The logger, or a silent one
	 *
	 * @return OpenRegisterArchive
	 */
	private static function archive(array $services, ?LoggerInterface $logger = null): OpenRegisterArchive {
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

		return new OpenRegisterArchive(container: $container, logger: ($logger ?? new NullLogger()));
	}//end archive()

	/**
	 * An e-depot service answering this transport configuration.
	 *
	 * @param mixed $config The configuration
	 *
	 * @return object
	 */
	private static function edepot(mixed $config): object {
		return new class($config) {
			/**
			 * @param mixed $config The configuration
			 */
			public function __construct(private mixed $config) {
			}

			public function getTransportConfig(): mixed {
				return $this->config;
			}
		};
	}//end edepot()

	/**
	 * A transfer-list service recording what it was asked for.
	 *
	 * @return object
	 */
	private static function transferLists(): object {
		return new class {
			/**
			 * @var list<list<object>>
			 */
			public array $asked = [];

			/**
			 * @param list<object> $objects The records
			 *
			 * @return array<string, mixed>
			 */
			public function createTransferList(array $objects): array {
				$this->asked[] = $objects;
				return ['uuid' => 'transfer-1', 'status' => 'review', 'count' => count($objects)];
			}
		};
	}//end transferLists()

	/**
	 * Each transport counts as configured by its own key: REST needs both an
	 * endpoint and an authentication type, SFTP a host, OpenConnector a source.
	 *
	 * @param mixed $config   The transport configuration
	 * @param bool  $expected Whether a transfer is available
	 *
	 * @return void
	 *
	 * @dataProvider transports
	 */
	public function testEachTransportIsJudgedByItsOwnSetting(mixed $config, bool $expected): void {
		$archive = self::archive(services: [self::EDEPOT => self::edepot(config: $config), self::TRANSFER_LISTS => self::transferLists()]);

		self::assertSame($expected, $archive->transferAvailable());
	}//end testEachTransportIsJudgedByItsOwnSetting()

	/**
	 * Transport configurations and whether each can send.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function transports(): array {
		return [
			'rest by default, complete' => [['endpointUrl' => 'https://edepot.example', 'authenticationType' => 'oauth2'], true],
			'rest, no authentication type' => [['transport' => 'rest_api', 'endpointUrl' => 'https://edepot.example'], false],
			'rest, empty authentication type' => [['endpointUrl' => 'https://edepot.example', 'authenticationType' => ''], false],
			'rest, no endpoint' => [['authenticationType' => 'basic'], false],
			'sftp with a host' => [['transport' => 'sftp', 'host' => 'sftp.example'], true],
			'sftp without a host' => [['transport' => 'sftp', 'endpointUrl' => 'https://x', 'authenticationType' => 'basic'], false],
			'openconnector with a source' => [['transport' => 'openconnector', 'sourceId' => '12'], true],
			'openconnector without a source' => [['transport' => 'openconnector', 'sourceId' => ''], false],
			'an unknown transport falls to the rest keys' => [['transport' => 'ftp', 'endpointUrl' => 'https://x', 'authenticationType' => 'basic'], true],
			'no configuration' => [[], false],
		];
	}//end transports()

	/**
	 * A configured e-depot without a transfer-list service cannot transfer.
	 *
	 * @return void
	 */
	public function testAConfiguredEdepotWithoutTransferListsIsUnavailable(): void {
		$archive = self::archive(services: [self::EDEPOT => self::edepot(config: ['transport' => 'sftp', 'host' => 'sftp.example'])]);

		self::assertFalse($archive->transferAvailable());
	}//end testAConfiguredEdepotWithoutTransferListsIsUnavailable()

	/**
	 * The records are handed to OpenRegister as given, and its list comes back.
	 *
	 * @return void
	 */
	public function testATransferListIsCreatedOverTheRecords(): void {
		$lists = self::transferLists();
		$records = [new \stdClass(), new \stdClass()];

		$list = self::archive(services: [self::TRANSFER_LISTS => $lists])->createTransferList(objects: $records);

		self::assertSame(['uuid' => 'transfer-1', 'status' => 'review', 'count' => 2], $list);
		self::assertSame([$records], $lists->asked);
	}//end testATransferListIsCreatedOverTheRecords()

	/**
	 * A known transfer list answers its status; one without a status answers
	 * an empty string, not null.
	 *
	 * @return void
	 */
	public function testAKnownTransferListAnswersItsStatus(): void {
		$records = new class {
			/**
			 * @var list<string>
			 */
			public array $asked = [];

			/**
			 * @return array<string, mixed>|null
			 */
			public function loadTransferList(string $uuid): ?array {
				$this->asked[] = $uuid;
				return match ($uuid) {
					'list-1' => ['uuid' => 'list-1', 'status' => 'completed'],
					default => ['uuid' => $uuid],
				};
			}
		};
		$archive = self::archive(services: [self::TRANSFER_RECORDS => $records]);

		self::assertSame('completed', $archive->transferListStatus(uuid: 'list-1'));
		self::assertSame('', $archive->transferListStatus(uuid: 'list-2'));
		self::assertSame(['list-1', 'list-2'], $records->asked);
	}//end testAKnownTransferListAnswersItsStatus()

	/**
	 * OpenRegister's destruction answer is passed on with every refusal
	 * reduced to a uuid and a reason, as strings.
	 *
	 * @return void
	 */
	public function testADestructionListComesBackWithItsRefusals(): void {
		$creator = new class {
			/**
			 * @var list<list<string>>
			 */
			public array $asked = [];

			/**
			 * @param list<string> $uuids The records
			 *
			 * @return array<string, mixed>
			 */
			public function createFor(array $uuids): array {
				$this->asked[] = $uuids;
				return [
					'list' => ['uuid' => 'destruction-1', 'status' => 'pending_review'],
					'refused' => [
						['uuid' => 'b', 'reason' => 'legal hold', 'extra' => 'dropped'],
						['uuid' => 7],
					],
				];
			}
		};

		$created = self::archive(services: [self::DESTRUCTION_CREATOR => $creator])->createDestructionList(uuids: ['a', 'b', '7']);

		self::assertSame(
			[
				'list' => ['uuid' => 'destruction-1', 'status' => 'pending_review'],
				'refused' => [['uuid' => 'b', 'reason' => 'legal hold'], ['uuid' => '7', 'reason' => '']],
			],
			$created
		);
		self::assertSame([['a', 'b', '7']], $creator->asked);
	}//end testADestructionListComesBackWithItsRefusals()

	/**
	 * When OpenRegister makes no list (nothing eligible) the list is null and
	 * the refusals still come back.
	 *
	 * @return void
	 */
	public function testNoListButRefusalsIsANullList(): void {
		$creator = new class {
			/**
			 * @param list<string> $uuids The records
			 *
			 * @return array<string, mixed>
			 */
			public function createFor(array $uuids): array {
				return ['list' => 'not a list', 'refused' => [['uuid' => 'a', 'reason' => 'retention not passed']]];
			}
		};

		self::assertSame(
			['list' => null, 'refused' => [['uuid' => 'a', 'reason' => 'retention not passed']]],
			self::archive(services: [self::DESTRUCTION_CREATOR => $creator])->createDestructionList(uuids: ['a'])
		);
	}//end testNoListButRefusalsIsANullList()

	/**
	 * OpenRegister refusing outright (no destruction-list register) answers
	 * null and says why in the log.
	 *
	 * @return void
	 */
	public function testARefusedDestructionListIsNullAndLogged(): void {
		$creator = new class {
			/**
			 * @param list<string> $uuids The records
			 *
			 * @return array<string, mixed>
			 */
			public function createFor(array $uuids): array {
				throw new InvalidArgumentException('no destruction register');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Decidiq records: OpenRegister made no destruction list: no destruction register');

		self::assertNull(self::archive(services: [self::DESTRUCTION_CREATOR => $creator], logger: $logger)->createDestructionList(uuids: ['a']));
	}//end testARefusedDestructionListIsNullAndLogged()

	/**
	 * A service the container cannot resolve is logged by name and treated as absent.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceIsLoggedByName(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Decidiq records: OpenRegister service ' . self::DESTRUCTION_CREATOR . ' unavailable: not here');

		self::assertNull(self::archive(services: [], logger: $logger)->createDestructionList(uuids: ['a']));
	}//end testAnUnresolvableServiceIsLoggedByName()

	/**
	 * A known destruction list answers the object OpenRegister keeps on it.
	 *
	 * @return void
	 */
	public function testAKnownDestructionListAnswersItsObject(): void {
		$lists = new class {
			public function find(string $uuid): ?object {
				if ($uuid !== 'destruction-1') {
					return null;
				}

				return new class {
					/**
					 * @return array<string, mixed>|null
					 */
					public function getObject(): ?array {
						return ['status' => 'executed', 'destroyedCount' => 3, 'certificateUuid' => 'cert-1'];
					}
				};
			}
		};

		self::assertSame(
			['status' => 'executed', 'destroyedCount' => 3, 'certificateUuid' => 'cert-1'],
			self::archive(services: [self::DESTRUCTION_LISTS => $lists])->destructionList(uuid: 'destruction-1')
		);
	}//end testAKnownDestructionListAnswersItsObject()

	/**
	 * A configured repository answers its certificates, dropping results that
	 * are not rows and stringifying the missing uuids.
	 *
	 * @return void
	 */
	public function testCertificatesAreNormalised(): void {
		$lists = new class {
			/**
			 * @var list<string>
			 */
			public array $asked = [];

			public function isConfigured(): bool {
				return true;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function findCertificates(string $listUuid): array {
				$this->asked[] = $listUuid;
				return ['results' => ['skip' => 'not a row', 'cert' => ['uuid' => 'cert-1']], 'missing' => [12, 'cert-2']];
			}
		};

		self::assertSame(
			['configured' => true, 'results' => [['uuid' => 'cert-1']], 'missing' => ['12', 'cert-2']],
			self::archive(services: [self::DESTRUCTION_LISTS => $lists])->certificates(listUuid: 'destruction-1')
		);
		self::assertSame(['destruction-1'], $lists->asked);
	}//end testCertificatesAreNormalised()

	/**
	 * An unconfigured repository is never asked for certificates.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRepositoryIsNotAsked(): void {
		$lists = new class {
			public int $calls = 0;

			public function isConfigured(): bool {
				return false;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function findCertificates(string $listUuid): array {
				$this->calls++;
				return ['results' => [['uuid' => 'cert-1']]];
			}
		};

		self::assertSame(['configured' => false, 'results' => [], 'missing' => []], self::archive(services: [self::DESTRUCTION_LISTS => $lists])->certificates(listUuid: 'x'));
		self::assertSame(0, $lists->calls);
	}//end testAnUnconfiguredRepositoryIsNotAsked()

	/**
	 * TMLO is enabled only by a literal true: a truthy string, a missing key
	 * or a null configuration is not enabled, and the register is looked up
	 * by the slug given.
	 *
	 * @param mixed $configuration The register's configuration
	 * @param bool  $expected      Whether TMLO counts as enabled
	 *
	 * @return void
	 *
	 * @dataProvider tmloConfigurations
	 */
	public function testTmloNeedsALiteralTrue(mixed $configuration, bool $expected): void {
		$registers = new class($configuration) {
			/**
			 * @var list<string>
			 */
			public array $asked = [];

			/**
			 * @param mixed $configuration The configuration
			 */
			public function __construct(private mixed $configuration) {
			}

			public function find(string $id): object {
				$this->asked[] = $id;
				return new class($this->configuration) {
					/**
					 * @param mixed $configuration The configuration
					 */
					public function __construct(private mixed $configuration) {
					}

					public function getConfiguration(): mixed {
						return $this->configuration;
					}
				};
			}
		};

		self::assertSame($expected, self::archive(services: [self::REGISTERS => $registers])->tmloEnabled(register: 'raad'));
		self::assertSame(['raad'], $registers->asked);
	}//end testTmloNeedsALiteralTrue()

	/**
	 * Register configurations and whether TMLO is enabled.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function tmloConfigurations(): array {
		return [
			'true' => [['tmloEnabled' => true], true],
			'a truthy string' => [['tmloEnabled' => 'true'], false],
			'false' => [['tmloEnabled' => false], false],
			'no key' => [['other' => true], false],
			'null configuration' => [null, false],
		];
	}//end tmloConfigurations()
}//end class
