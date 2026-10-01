<?php

/**
 * Unit tests for ArchivalDossierService and DossierMemberCollector: forming a
 * meeting's archival dossier, gathering its records, and closing it.
 *
 * The OpenRegister fake follows the store's contract: find() by uuid, findAll()
 * with property filters, saveObject() with the system-context flags. The role
 * check runs through the real ParticipantResolver, and every dossier the
 * service writes is validated with Opis against the real register fragment.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\ArchivalDossierService;
use OCA\Decidiq\Service\DossierMemberCollector;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the archival dossier of a meeting.
 *
 * @covers \OCA\Decidiq\Service\ArchivalDossierService
 * @covers \OCA\Decidiq\Service\DossierMemberCollector
 * @covers \OCA\Decidiq\Exception\DossierRefusedException
 * @uses   \OCA\Decidiq\Service\ParticipantResolver
 */
class ArchivalDossierServiceTest extends TestCase {

	private const MEETING = '5d1f0a10-0000-4000-8000-000000000001';

	private const BODY = '5d1f0a10-0000-4000-8000-0000000000b0';

	/**
	 * Objects by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every write: [schema, object, uuid, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: ?string, 3: bool, 4: bool}>
	 */
	private array $saves = [];

	/**
	 * The schema the last setSchema() call chose (ParticipantResolver's read).
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The signed-in account.
	 *
	 * @var string
	 */
	private string $uid = 'griffier';

	/**
	 * Whether the signed-in account is an administrator.
	 *
	 * @var bool
	 */
	private bool $admin = false;

	/**
	 * A stable uuid for a fixture name, so the written dossier is checked
	 * with the schema's uuid format in force.
	 *
	 * @param string $name The fixture name
	 *
	 * @return string
	 */
	private static function u(string $name): string {
		$hash = md5($name);
		return sprintf('%s-%s-4%s-8%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
	}//end u()

	/**
	 * Store an object.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The uuid
	 * @param array<string, mixed> $data   The object
	 *
	 * @return void
	 */
	private function put(string $schema, string $id, array $data): void {
		$this->store[$schema][$id] = $data;
	}//end put()

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
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Whether a stored object meets every property filter.
	 *
	 * @param array<string, mixed> $data    The object
	 * @param array<string, mixed> $filters The filters
	 *
	 * @return bool
	 */
	private static function meets(array $data, array $filters): bool {
		foreach ($filters as $field => $value) {
			if (($data[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end meets()

	/**
	 * The OpenRegister fake.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($objectService) {
				$this->schema = (string)$schema;
				return $objectService;
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return self::entity(schema: (string)$schema, id: (string)$id, data: $data);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$schema = (string)($filters['schema'] ?? $this->schema);
				unset($filters['schema'], $filters['register']);
				$found = [];
				foreach (($this->store[$schema] ?? []) as $id => $data) {
					if (self::meets(data: $data, filters: $filters) === true) {
						$found[] = self::entity(schema: $schema, id: (string)$id, data: $data);
					}
				}

				return $found;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saves[] = [(string)$schema, $object, $uuid, $_rbac, $_multitenancy];
				$id = ($uuid ?? sprintf('5d1f0a10-0000-4000-8000-%012d', count($this->saves)));
				$this->store[(string)$schema][$id] = $object;
				return self::entity(schema: (string)$schema, id: $id, data: $object);
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * The service under test, with its real siblings.
	 *
	 * @return ArchivalDossierService
	 */
	private function service(): ArchivalDossierService {
		$objectService = $this->objectService();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->admin);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new ArchivalDossierService(
			objectService: $objectService,
			members: new DossierMemberCollector(objectService: $objectService),
			participants: new ParticipantResolver(logger: new NullLogger(), objectService: $objectService),
			userSession: $session,
			groupManager: $groups,
			l10n: $l10n,
		);
	}//end service()

	/**
	 * The archival-dossier schema as the merged register declares it.
	 *
	 * @return array<string, mixed>
	 */
	private static function dossierSchema(): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === 'archival-dossier') {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end dossierSchema()

	/**
	 * Assert a written dossier validates against the real fragment.
	 *
	 * @param array<string, mixed> $dossier The dossier as written
	 *
	 * @return void
	 */
	private static function assertValidDossier(array $dossier): void {
		$schema = self::dossierSchema();
		self::assertNotSame([], $schema, 'archival-dossier is declared');
		$properties = $schema['properties'];
		foreach (array_keys($properties) as $key) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['items']['$ref']);
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($dossier)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), 'The dossier the service writes validates: ' . json_encode($dossier));
	}//end assertValidDossier()

	/**
	 * A council meeting with its records: approved minutes, a decision taken
	 * in the meeting, a motion on one of its agenda items with a voting round
	 * through its decisive stage, and documents on the meeting and the item.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->saves = [];
		$this->uid = 'griffier';
		$this->admin = false;
		$this->put('meeting', self::MEETING, ['title' => 'Raadsvergadering 10 april 2025', 'lifecycle' => 'closed', 'governanceBody' => self::BODY, 'relations' => [['schema' => 'governance-body', 'id' => self::BODY]]]);
		$this->put('participant', 'p-griffier', ['displayName' => 'De griffier', 'role' => 'secretary', 'nextcloudUserId' => 'griffier', 'relations' => ['governanceBody' => self::BODY]]);
		$this->put('participant', 'p-lid', ['displayName' => 'Een raadslid', 'role' => 'member', 'nextcloudUserId' => 'raadslid', 'relations' => ['governanceBody' => self::BODY]]);
		$this->put('agenda-item', self::u('ai-begroting'), ['title' => 'Begroting', 'meeting' => self::MEETING]);
		$this->put('agenda-item', self::u('ai-other'), ['title' => 'Elders', 'meeting' => self::u('another-meeting')]);
		$this->put('minutes', self::u('min-approved'), ['title' => 'Notulen', 'lifecycle' => 'approved', 'meeting' => self::MEETING]);
		$this->put('minutes', self::u('min-draft'), ['title' => 'Concept', 'lifecycle' => 'draft', 'meeting' => self::MEETING]);
		$this->put('decision', self::u('dec-meeting'), ['title' => 'Vaststelling begroting', 'decisionType' => 'resolution', 'meeting' => self::MEETING]);
		$this->put('decision', self::u('dec-item'), ['title' => 'Motie groen dak', 'decisionType' => 'motion', 'agendaItem' => self::u('ai-begroting')]);
		$this->put('decision', self::u('dec-elsewhere'), ['title' => 'Elders besloten', 'decisionType' => 'motion', 'agendaItem' => self::u('ai-other')]);
		$this->put('decision-stage', self::u('st-item'), ['decision' => self::u('dec-item'), 'votingRound' => self::u('vr-item'), 'stageType' => 'decisive']);
		$this->put('decision-stage', self::u('st-meeting'), ['decision' => self::u('dec-meeting'), 'stageType' => 'decisive']);
		$this->put('voting-round', self::u('vr-item'), ['votingMethod' => 'for-against-abstain', 'isSecret' => false, 'decisionStage' => self::u('st-item')]);
		$this->put('voting-round', self::u('vr-meeting'), ['votingMethod' => 'for-against-abstain', 'isSecret' => false, 'decisionStage' => self::u('st-meeting')]);
		$this->put('digital-document', self::u('doc-meeting'), ['name' => 'Raadsvoorstel.pdf', 'documentType' => 'proposal', 'meeting' => self::MEETING]);
		$this->put('digital-document', self::u('doc-item'), ['name' => 'Bijlage.pdf', 'documentType' => 'annex', 'agendaItem' => self::u('ai-begroting')]);
		$this->put('digital-document', self::u('doc-elsewhere'), ['name' => 'Elders.pdf', 'documentType' => 'annex', 'agendaItem' => self::u('ai-other')]);
	}//end setUp()

	/**
	 * Forming a dossier gathers the meeting's records by uuid, decisions found
	 * through the meeting and through its agenda items, voting rounds through
	 * the decision stages, and writes it in system context as forming.
	 *
	 * @return void
	 */
	public function testFormingGathersTheMeetingsRecords(): void {
		$dossier = $this->service()->formForMeeting(meetingId: self::MEETING);

		self::assertSame('forming', $dossier['lifecycle']);
		self::assertSame(self::MEETING, $dossier['meeting']);
		self::assertSame(self::BODY, $dossier['governanceBody']);
		self::assertSame([self::u('min-approved')], $dossier['minutes']);
		self::assertEqualsCanonicalizing([self::u('dec-meeting'), self::u('dec-item')], $dossier['decisions']);
		self::assertEqualsCanonicalizing([self::u('vr-item'), self::u('vr-meeting')], $dossier['votingRounds']);
		self::assertEqualsCanonicalizing([self::u('doc-meeting'), self::u('doc-item')], $dossier['documents']);
		self::assertSame([], $dossier['gaps']);
		self::assertNotEmpty($dossier['id']);

		self::assertCount(1, $this->saves);
		[$schema, $written, $uuid, $rbac, $multitenancy] = $this->saves[0];
		self::assertSame('archival-dossier', $schema);
		self::assertNull($uuid);
		self::assertFalse($rbac, 'the dossier is written in system context, its writes are service-owned');
		self::assertFalse($multitenancy);
		self::assertValidDossier(dossier: $written);
	}//end testFormingGathersTheMeetingsRecords()

	/**
	 * A meeting has one dossier: forming it again answers the existing one.
	 *
	 * @return void
	 */
	public function testFormingTwiceAnswersTheSameDossier(): void {
		$service = $this->service();
		$first = $service->formForMeeting(meetingId: self::MEETING);
		$second = $service->formForMeeting(meetingId: self::MEETING);

		self::assertSame($first['id'], $second['id']);
		self::assertCount(1, $this->saves);
	}//end testFormingTwiceAnswersTheSameDossier()

	/**
	 * Closing a dossier whose minutes are not approved is refused naming the
	 * gap; with a reason it closes, freezes, and keeps the reason.
	 *
	 * @return void
	 */
	public function testClosingWithGapsNeedsAReason(): void {
		$this->put('minutes', self::u('min-approved'), ['title' => 'Notulen', 'lifecycle' => 'review', 'meeting' => self::MEETING]);
		$service = $this->service();
		$dossier = $service->formForMeeting(meetingId: self::MEETING);
		self::assertSame(['minutes-not-approved'], $dossier['gaps']);
		self::assertSame([], $dossier['minutes']);

		try {
			$service->close(dossierId: $dossier['id'], overrideReason: null);
			self::fail('closing with gaps and no reason must be refused');
		} catch (DossierRefusedException $refused) {
			self::assertSame(DossierRefusedException::GAPS, $refused->getReason());
			self::assertSame(['minutes-not-approved'], $refused->getGaps());
			self::assertStringContainsString('minutes are not approved', $refused->getMessage());
		}

		$closed = $service->close(dossierId: $dossier['id'], overrideReason: 'Notulen worden apart nagezonden');
		self::assertSame('closed', $closed['lifecycle']);
		self::assertSame('Notulen worden apart nagezonden', $closed['closeOverrideReason']);
		self::assertSame('griffier', $closed['closedBy']);
		self::assertNotEmpty($closed['closedAt']);
		[$schema, $written, $uuid, $rbac] = $this->saves[1];
		self::assertSame('archival-dossier', $schema);
		self::assertSame($dossier['id'], $uuid);
		self::assertFalse($rbac);
		self::assertValidDossier(dossier: $written);
	}//end testClosingWithGapsNeedsAReason()

	/**
	 * A complete dossier closes without a reason, and a closed dossier is
	 * frozen: gathering again or closing again is refused naming the state.
	 *
	 * @return void
	 */
	public function testAClosedDossierIsFrozen(): void {
		$service = $this->service();
		$dossier = $service->formForMeeting(meetingId: self::MEETING);
		$closed = $service->close(dossierId: $dossier['id'], overrideReason: null);
		self::assertSame('closed', $closed['lifecycle']);
		self::assertArrayNotHasKey('closeOverrideReason', $closed);

		$this->put('decision', self::u('dec-late'), ['title' => 'Later toegevoegd', 'decisionType' => 'motion', 'meeting' => self::MEETING]);
		foreach (['assemble', 'close'] as $action) {
			try {
				if ($action === 'assemble') {
					$service->assemble(dossierId: $dossier['id']);
				} else {
					$service->close(dossierId: $dossier['id'], overrideReason: 'nogmaals');
				}

				self::fail($action . ' on a closed dossier must be refused');
			} catch (DossierRefusedException $refused) {
				self::assertSame(DossierRefusedException::FROZEN, $refused->getReason());
				self::assertStringContainsString('closed', $refused->getMessage());
			}
		}

		self::assertNotContains(self::u('dec-late'), $this->store['archival-dossier'][$dossier['id']]['decisions']);
		self::assertCount(2, $this->saves);
	}//end testAClosedDossierIsFrozen()

	/**
	 * Gathering a forming dossier again picks up records added since.
	 *
	 * @return void
	 */
	public function testAssemblingAFormingDossierPicksUpNewRecords(): void {
		$service = $this->service();
		$dossier = $service->formForMeeting(meetingId: self::MEETING);
		$this->put('decision', self::u('dec-late'), ['title' => 'Later toegevoegd', 'decisionType' => 'amendment', 'meeting' => self::MEETING]);

		$again = $service->assemble(dossierId: $dossier['id']);

		self::assertContains(self::u('dec-late'), $again['decisions']);
		self::assertSame('forming', $again['lifecycle']);
		self::assertValidDossier(dossier: $this->saves[1][1]);
	}//end testAssemblingAFormingDossierPicksUpNewRecords()

	/**
	 * Only the meeting's chair or secretary, or an administrator, forms or
	 * closes its dossier.
	 *
	 * @return void
	 */
	public function testOnlyTheMeetingsStaffOrAnAdminActs(): void {
		$this->uid = 'raadslid';
		$service = $this->service();
		try {
			$service->formForMeeting(meetingId: self::MEETING);
			self::fail('a member who is not chair or secretary must be refused');
		} catch (AccessDeniedException) {
			self::assertSame([], $this->saves);
		}

		$this->admin = true;
		$dossier = $service->formForMeeting(meetingId: self::MEETING);
		$this->admin = false;
		$this->expectException(AccessDeniedException::class);
		$service->close(dossierId: $dossier['id'], overrideReason: null);
	}//end testOnlyTheMeetingsStaffOrAnAdminActs()

	/**
	 * An unknown meeting or dossier is a missing object, not an empty dossier.
	 *
	 * @return void
	 */
	public function testAnUnknownMeetingOrDossierIsMissing(): void {
		$service = $this->service();
		try {
			$service->formForMeeting(meetingId: 'no-such-meeting');
			self::fail('an unknown meeting must be missing');
		} catch (MissingObjectException) {
			self::assertSame([], $this->saves);
		}

		$this->expectException(MissingObjectException::class);
		$service->close(dossierId: 'no-such-dossier', overrideReason: null);
	}//end testAnUnknownMeetingOrDossierIsMissing()
}//end class
