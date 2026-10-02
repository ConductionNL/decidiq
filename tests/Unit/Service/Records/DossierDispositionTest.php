<?php

/**
 * Unit tests for DossierDisposition: a closed dossier goes to OpenRegister's
 * transfer list or destruction list according to its Selectielijst category,
 * and reflects what OpenRegister carried out.
 *
 * The OpenRegister fakes follow openregister#4228 (branch feat/archival-for-apps
 * @42995c06de) exactly: TransferListService::createTransferList(ObjectEntity[]),
 * TransferRecordService::loadTransferList(uuid), EdepotTransferService::
 * getTransportConfig(), DestructionListCreator::createFor(uuids) answering
 * {list, refused} and throwing InvalidArgumentException when no destruction-list
 * register is configured, DestructionListRepository::find(uuid). The category
 * is read by the real SelectionCategoryReader from the real merged register;
 * every dossier written is validated with Opis against the real fragment.
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
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\Records\ArchivistGuard;
use OCA\Decidiq\Service\Records\DossierDisposition;
use OCA\Decidiq\Service\Records\OpenRegisterArchive;
use OCA\Decidiq\Service\Records\SelectionCategoryReader;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for routing a closed dossier to OpenRegister.
 *
 * @covers \OCA\Decidiq\Service\Records\DossierDisposition
 * @covers \OCA\Decidiq\Service\Records\OpenRegisterArchive
 * @covers \OCA\Decidiq\Service\Records\SelectionCategoryReader
 * @covers \OCA\Decidiq\Service\Records\ArchivistGuard
 * @covers \OCA\Decidiq\Exception\DossierRefusedException
 */
class DossierDispositionTest extends TestCase {

	private const DOSSIER = '7a1f0a10-0000-4000-8000-0000000000d1';

	private const MEETING = '7a1f0a10-0000-4000-8000-000000000001';

	private const MINUTES = '7a1f0a10-0000-4000-8000-0000000000a1';

	private const DECISION = '7a1f0a10-0000-4000-8000-0000000000a2';

	private const ROUND = '7a1f0a10-0000-4000-8000-0000000000a3';

	private const DOCUMENT = '7a1f0a10-0000-4000-8000-0000000000a4';

	/**
	 * Objects by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every dossier write: [object, uuid, rbac, multitenancy].
	 *
	 * @var list<array{0: array<string, mixed>, 1: ?string, 2: bool, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * The OpenRegister services the container answers, by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * The archive block the dossier schema declares in this test.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $archive = null;

	/**
	 * Groups of the signed-in account.
	 *
	 * @var list<string>
	 */
	private array $groups = ['archivaris'];

	/**
	 * Whether the signed-in account is an administrator.
	 *
	 * @var bool
	 */
	private bool $admin = false;

	/**
	 * Transfer lists OpenRegister holds, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $transferLists = [];

	/**
	 * Destruction lists OpenRegister holds, by uuid (the list object).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $destructionLists = [];

	/**
	 * What DestructionListCreator::createFor() received.
	 *
	 * @var list<list<string>>
	 */
	public array $destructionCalls = [];

	/**
	 * Refusals DestructionListCreator answers, by uuid.
	 *
	 * @var array<string, string>
	 */
	public array $refusals = [];

	/**
	 * Whether a destruction-list register is configured in OpenRegister.
	 *
	 * @var bool
	 */
	public bool $destructionConfigured = true;

	/**
	 * OpenRegister's e-depot transport settings.
	 *
	 * @var array<string, string>
	 */
	public array $transport = ['transport' => 'rest_api', 'endpointUrl' => 'https://edepot.example.nl/api', 'authenticationType' => 'api_key', 'host' => '', 'sourceId' => ''];

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The uuid
	 * @param array<string, mixed> $data   The object
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $schema, string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema($schema);
		$entity->setRegister('decidiq');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The OpenRegister object fake.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return self::entity(schema: (string)$schema, id: (string)$id, data: $data);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saves[] = [$object, $uuid, $_rbac, $_multitenancy];
				unset($object['@self']);
				$this->store[(string)$schema][(string)$uuid] = $object;
				return self::entity(schema: (string)$schema, id: (string)$uuid, data: $object);
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * OpenRegister's archival services as feat/archival-for-apps declares them.
	 *
	 * @return array<string, object>
	 */
	private function openRegisterServices(): array {
		$test = $this;
		return [
			'OCA\OpenRegister\Service\Edepot\EdepotTransferService' => new class($test) {
				public function __construct(private DossierDispositionTest $test) {
				}

				public function getTransportConfig(): array {
					return $this->test->transport;
				}
			},
			'OCA\OpenRegister\Service\Edepot\TransferListService' => new class($test) {
				public function __construct(private DossierDispositionTest $test) {
				}

				public function createTransferList(array $objects): array {
					if (empty($objects) === true) {
						throw new InvalidArgumentException('No objects provided for transfer list');
					}

					$references = [];
					foreach ($objects as $object) {
						$references[] = ['uuid' => $object->getUuid(), 'schema' => $object->getSchema(), 'register' => $object->getRegister()];
					}

					$uuid = '7a1f0a10-0000-4000-8000-0000000000f' . count($this->test->transferLists);
					$this->test->transferLists[$uuid] = ['uuid' => $uuid, 'status' => 'in_review', 'objectReferences' => $references, 'objectCount' => count($references)];
					return $this->test->transferLists[$uuid];
				}
			},
			'OCA\OpenRegister\Service\Edepot\TransferRecordService' => new class($test) {
				public function __construct(private DossierDispositionTest $test) {
				}

				public function loadTransferList(string $uuid): ?array {
					return ($this->test->transferLists[$uuid] ?? null);
				}
			},
			'OCA\OpenRegister\Service\Archival\DestructionListCreator' => new class($test) {
				public function __construct(private DossierDispositionTest $test) {
				}

				public function createFor(array $uuids): array {
					$this->test->destructionCalls[] = $uuids;
					if ($this->test->destructionConfigured === false) {
						throw new InvalidArgumentException('No destruction list register and schema are configured, so there is nowhere to keep a list. Set them under Retention settings first.');
					}

					$eligible = [];
					$refused = [];
					foreach ($uuids as $uuid) {
						if (isset($this->test->refusals[$uuid]) === true) {
							$refused[] = ['uuid' => $uuid, 'reason' => $this->test->refusals[$uuid]];
							continue;
						}

						$eligible[] = ['objectUuid' => $uuid];
					}

					if ($eligible === []) {
						return ['list' => null, 'refused' => $refused];
					}

					$uuid = '7a1f0a10-0000-4000-8000-0000000000e' . count($this->test->destructionLists);
					$this->test->destructionLists[$uuid] = ['status' => 'in_review', 'objects' => $eligible];
					return ['list' => $this->test->destructionLists[$uuid] + ['uuid' => $uuid], 'refused' => $refused];
				}
			},
			'OCA\OpenRegister\Service\Archival\DestructionListRepository' => new class($test) {
				public function __construct(private DossierDispositionTest $test) {
				}

				public function isConfigured(): bool {
					return $this->test->destructionConfigured;
				}

				public function find(string $uuid): ?ObjectEntity {
					$list = ($this->test->destructionLists[$uuid] ?? null);
					if ($list === null) {
						return null;
					}

					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$entity->setObject($list);
					return $entity;
				}
			},
		];
	}//end openRegisterServices()

	/**
	 * The merged register, with this test's archive block on the dossier.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedRegister(): array {
		$settings = __DIR__ . '/../../../../lib/Settings/';
		$merged = json_decode((string)file_get_contents($settings . 'decidesk_register.json'), true);
		foreach ((glob($settings . 'register.d/*.json') ?: []) as $file) {
			$merged = array_replace_recursive($merged, json_decode((string)file_get_contents($file), true));
		}

		if ($this->archive !== null) {
			$merged['components']['schemas']['ArchivalDossier']['archive'] = $this->archive;
		}

		return $merged;
	}//end mergedRegister()

	/**
	 * The disposition under test, with its real siblings.
	 *
	 * @return DossierDisposition
	 */
	private function disposition(): DossierDisposition {
		$this->services = $this->openRegisterServices();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new class('not here') extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $this->services[$id];
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('mergedRegisterConfig')->willReturnCallback(fn (): array => $this->mergedRegister());
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->admin);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, $this->groups, true));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/settings/admin/openregister');

		return new DossierDisposition(
			objectService: $this->objectService(),
			categories: new SelectionCategoryReader(settings: $settings),
			archive: new OpenRegisterArchive(container: $container, logger: new NullLogger()),
			guard: new ArchivistGuard(userSession: $session, groupManager: $groups),
			urlGenerator: $urls,
			l10n: $l10n,
		);
	}//end disposition()

	/**
	 * Assert a written dossier validates against the real fragment.
	 *
	 * @param array<string, mixed> $dossier The dossier as written
	 *
	 * @return void
	 */
	private static function assertValidDossier(array $dossier): void {
		$settings = __DIR__ . '/../../../../lib/Settings/register.d/115-records-management-archiving.json';
		$schema = json_decode((string)file_get_contents($settings), true)['components']['schemas']['ArchivalDossier'];
		$properties = $schema['properties'];
		foreach (array_keys($properties) as $key) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['items']['$ref']);
		}

		unset($dossier['@self']);
		$result = (new Validator())->validate(
			json_decode((string)json_encode($dossier)),
			json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), 'The dossier written validates: ' . json_encode($dossier));
	}//end assertValidDossier()

	/**
	 * A closed dossier with one record of each kind, all present in the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->saves = [];
		$this->archive = null;
		$this->groups = ['archivaris'];
		$this->admin = false;
		$this->transferLists = [];
		$this->destructionLists = [];
		$this->destructionCalls = [];
		$this->refusals = [];
		$this->destructionConfigured = true;
		$this->store['archival-dossier'][self::DOSSIER] = [
			'title' => 'Raadsvergadering 10 april 2025',
			'meeting' => self::MEETING,
			'lifecycle' => 'closed',
			'minutes' => [self::MINUTES],
			'decisions' => [self::DECISION],
			'votingRounds' => [self::ROUND],
			'documents' => [self::DOCUMENT],
			'gaps' => [],
			'securityClassification' => 'openbaar',
		];
		$this->store['minutes'][self::MINUTES] = ['title' => 'Notulen'];
		$this->store['decision'][self::DECISION] = ['title' => 'Besluit'];
		$this->store['voting-round'][self::ROUND] = ['votingMethod' => 'for-against-abstain'];
		$this->store['digital-document'][self::DOCUMENT] = ['name' => 'Raadsvoorstel.pdf'];
	}//end setUp()

	/**
	 * The shipped category, 2.1, keeps: the dossier goes to a transfer list
	 * over its member records, and is not transferred until OpenRegister says so.
	 *
	 * @return void
	 */
	public function testAKeptCategoryProposesATransferListOverTheMembers(): void {
		$dossier = $this->disposition()->propose(dossierId: self::DOSSIER);

		self::assertCount(1, $this->transferLists);
		$list = array_values($this->transferLists)[0];
		self::assertSame([self::MINUTES, self::DECISION, self::ROUND, self::DOCUMENT], array_column($list['objectReferences'], 'uuid'));
		self::assertSame(['minutes', 'decision', 'voting-round', 'digital-document'], array_column($list['objectReferences'], 'schema'));
		self::assertSame([], $this->destructionCalls, 'a kept category never reaches destruction');
		self::assertSame('transfer', $dossier['disposition']);
		self::assertSame($list['uuid'], $dossier['transferList']);
		self::assertSame('closed', $dossier['lifecycle'], 'proposed, not transferred');
		self::assertSame([false, false], [$this->saves[0][2], $this->saves[0][3]], 'written in system context');
		self::assertValidDossier(dossier: $this->saves[0][0]);
	}//end testAKeptCategoryProposesATransferListOverTheMembers()

	/**
	 * The routing reads the category from the register, not from code: the
	 * same dossier under a destroy category goes to a destruction list, and
	 * OpenRegister's refusals are kept on the dossier.
	 *
	 * @return void
	 */
	public function testADestroyCategoryProposesADestructionListAndKeepsTheRefusals(): void {
		$this->archive = ['enabled' => true, 'classification' => '11.1'];
		$this->refusals = [self::DOCUMENT => 'legal_hold'];

		$dossier = $this->disposition()->propose(dossierId: self::DOSSIER);

		self::assertSame([[self::MINUTES, self::DECISION, self::ROUND, self::DOCUMENT]], $this->destructionCalls);
		self::assertSame([], $this->transferLists);
		self::assertSame('destruction', $dossier['disposition']);
		self::assertSame(array_key_first($this->destructionLists), $dossier['destructionList']);
		self::assertSame([['uuid' => self::DOCUMENT, 'reason' => 'legal_hold']], $dossier['dispositionRefused']);
		self::assertValidDossier(dossier: $this->saves[0][0]);
	}//end testADestroyCategoryProposesADestructionListAndKeepsTheRefusals()

	/**
	 * Nothing eligible: OpenRegister makes no list, the dossier is unchanged,
	 * and the refusal names each record's reason.
	 *
	 * @return void
	 */
	public function testNothingEligibleForDestructionIsRefusedWithOpenRegistersReasons(): void {
		$this->archive = ['enabled' => true, 'classification' => '19.1'];
		$this->refusals = [self::MINUTES => 'not_due', self::DECISION => 'not_due', self::ROUND => 'not_due', self::DOCUMENT => 'legal_hold'];

		try {
			$this->disposition()->propose(dossierId: self::DOSSIER);
			self::fail('nothing eligible is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::NOTHING_ELIGIBLE, $e->getReason());
			self::assertContains(self::DOCUMENT . ': legal_hold', $e->getGaps());
			self::assertFalse($e->isConflict());
		}

		self::assertSame([], $this->saves);
	}//end testNothingEligibleForDestructionIsRefusedWithOpenRegistersReasons()

	/**
	 * Without an e-depot transport the transfer is refused honestly: no list,
	 * no state change, and the description points to OpenRegister's settings.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredEdepotRefusesTheTransferAndSaysWhere(): void {
		$this->transport = ['transport' => 'sftp', 'endpointUrl' => '', 'authenticationType' => '', 'host' => '', 'sourceId' => ''];
		$disposition = $this->disposition();

		$described = $disposition->describe(dossierId: self::DOSSIER);
		self::assertSame('transfer', $described['route']);
		self::assertSame('2.1', $described['category']);
		self::assertFalse($described['transferAvailable']);
		self::assertSame('/index.php/settings/admin/openregister', $described['settingsUrl']);

		try {
			$disposition->propose(dossierId: self::DOSSIER);
			self::fail('transfer without a transport is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::TRANSFER_UNAVAILABLE, $e->getReason());
			self::assertTrue($e->isConflict());
		}

		self::assertSame([], $this->transferLists);
		self::assertSame([], $this->saves);
	}//end testAnUnconfiguredEdepotRefusesTheTransferAndSaysWhere()

	/**
	 * Each transport counts as configured by its own key.
	 *
	 * @return void
	 */
	public function testEachTransportIsConfiguredByItsOwnKey(): void {
		$archive = new OpenRegisterArchive(container: $this->containerWith(services: $this->openRegisterServices()), logger: new NullLogger());
		$cases = [
			[['transport' => 'rest_api', 'endpointUrl' => 'https://x', 'authenticationType' => ''], false],
			[['transport' => 'rest_api', 'endpointUrl' => 'https://x', 'authenticationType' => 'none'], true],
			[['transport' => 'sftp', 'host' => 'sftp.example.nl'], true],
			[['transport' => 'openconnector', 'sourceId' => ''], false],
			[['transport' => 'openconnector', 'sourceId' => '12'], true],
		];
		foreach ($cases as [$config, $expected]) {
			$this->transport = $config;
			self::assertSame($expected, $archive->transferAvailable(), json_encode($config));
		}
	}//end testEachTransportIsConfiguredByItsOwnKey()

	/**
	 * No destruction-list register in OpenRegister: a conflict naming it.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredDestructionRegisterIsAConflict(): void {
		$this->archive = ['enabled' => true, 'classification' => '11.1'];
		$this->destructionConfigured = false;

		try {
			$this->disposition()->propose(dossierId: self::DOSSIER);
			self::fail('no destruction register is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::DESTRUCTION_UNAVAILABLE, $e->getReason());
			self::assertTrue($e->isConflict());
		}

		self::assertSame([], $this->saves);
	}//end testAnUnconfiguredDestructionRegisterIsAConflict()

	/**
	 * The dossier becomes transferred or destroyed only when OpenRegister has
	 * carried the list out; before that, the outcome leaves it closed.
	 *
	 * @return void
	 */
	public function testTheOutcomeFollowsOpenRegister(): void {
		$disposition = $this->disposition();
		$disposition->propose(dossierId: self::DOSSIER);
		$listId = (string)array_key_first($this->transferLists);

		$before = $disposition->reflectOutcome(dossierId: self::DOSSIER);
		self::assertSame('closed', $before['lifecycle']);
		self::assertSame('in_review', $before['listStatus']);

		$this->transferLists[$listId]['status'] = 'completed';
		$after = $disposition->reflectOutcome(dossierId: self::DOSSIER);
		self::assertSame('transferred', $after['lifecycle']);
		self::assertValidDossier(dossier: end($this->saves)[0]);

		// Destroyed, through a destruction list OpenRegister has executed.
		$this->setUp();
		$this->archive = ['enabled' => true, 'classification' => '11.1'];
		$disposition = $this->disposition();
		$disposition->propose(dossierId: self::DOSSIER);
		$this->destructionLists[(string)array_key_first($this->destructionLists)]['status'] = 'executed';
		self::assertSame('destroyed', $disposition->reflectOutcome(dossierId: self::DOSSIER)['lifecycle']);
	}//end testTheOutcomeFollowsOpenRegister()

	/**
	 * Only a closed dossier is routed, only once, and only by an archivist or
	 * an administrator; an unknown dossier is missing.
	 *
	 * @return void
	 */
	public function testOnlyAClosedDossierOnceAndOnlyAnArchivist(): void {
		$this->store['archival-dossier'][self::DOSSIER]['lifecycle'] = 'forming';
		try {
			$this->disposition()->propose(dossierId: self::DOSSIER);
			self::fail('a forming dossier is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::NOT_CLOSED, $e->getReason());
		}

		$this->store['archival-dossier'][self::DOSSIER]['lifecycle'] = 'closed';
		$this->disposition()->propose(dossierId: self::DOSSIER);
		try {
			$this->disposition()->propose(dossierId: self::DOSSIER);
			self::fail('a second proposal is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::ALREADY_PROPOSED, $e->getReason());
		}

		$this->groups = [];
		try {
			$this->disposition()->describe(dossierId: self::DOSSIER);
			self::fail('a non-archivist is refused');
		} catch (AccessDeniedException $e) {
			self::assertStringContainsString('archivist', $e->getMessage());
		}

		$this->admin = true;
		self::assertSame('transfer', $this->disposition()->describe(dossierId: self::DOSSIER)['route']);

		$this->expectException(MissingObjectException::class);
		$this->disposition()->describe(dossierId: '7a1f0a10-0000-4000-8000-00000000dead');
	}//end testOnlyAClosedDossierOnceAndOnlyAnArchivist()

	/**
	 * A dossier schema without a category, or OpenRegister without its
	 * archival services, is refused rather than guessed.
	 *
	 * @return void
	 */
	public function testNoCategoryOrNoOpenRegisterIsRefusedNotGuessed(): void {
		$this->archive = ['enabled' => true, 'classification' => '99.9'];
		try {
			$this->disposition()->propose(dossierId: self::DOSSIER);
			self::fail('an unknown category is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::NO_CATEGORY, $e->getReason());
		}

		$this->archive = null;
		$disposition = $this->disposition();
		$this->services = [];
		self::assertFalse($disposition->describe(dossierId: self::DOSSIER)['transferAvailable']);
		try {
			$disposition->propose(dossierId: self::DOSSIER);
			self::fail('no OpenRegister archive is refused');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::TRANSFER_UNAVAILABLE, $e->getReason());
		}
	}//end testNoCategoryOrNoOpenRegisterIsRefusedNotGuessed()

	/**
	 * A container that answers the given services.
	 *
	 * @param array<string, object> $services By class name
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new class('not here') extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $services[$id];
			}
		);
		return $container;
	}//end containerWith()
}//end class
