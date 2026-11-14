<?php

/**
 * The annotation routes, wired to the real annotation and visibility
 * services over an in-memory register: a private note never reaches anyone
 * but its author, a faction note reaches the author's faction only, a body
 * note the meeting's participants only, and only the author changes one
 * (document-annotations tasks 2 to 4; the guard is tested through the
 * controller, not only in isolation).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use DateTime;
use OCA\Decidiq\Controller\AnnotationController;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Service\AnnotationService;
use OCA\Decidiq\Service\AnnotationVisibilityService;
use OCA\Decidiq\Service\CsvCell;
use OCA\Decidiq\Service\DocumentVersionReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Controller\AnnotationController
 * @uses   \OCA\Decidiq\Service\AnnotationService
 * @uses   \OCA\Decidiq\Service\AnnotationVisibilityService
 * @uses   \OCA\Decidiq\Service\DocumentVersionReader
 * @uses   \OCA\Decidiq\Service\CsvCell
 */
class AnnotationControllerTest extends TestCase {

	private const FILE = 4711;
	private const MEETING = '7b2c0000-0000-4000-8000-000000000001';
	private const ITEM = '7b2c0000-0000-4000-8000-000000000002';
	private const OTHER_ITEM = '7b2c0000-0000-4000-8000-000000000003';
	private const GREEN = '7b2c0000-0000-4000-8000-000000000010';
	private const BLUE = '7b2c0000-0000-4000-8000-000000000011';
	private const CURRENT = '1760000000';

	/**
	 * Objects by schema slug and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Whether OpenRegister fails.
	 *
	 * @var boolean
	 */
	private bool $broken = false;

	/**
	 * How many writes reached OpenRegister.
	 *
	 * @var integer
	 */
	private int $writes = 0;

	/**
	 * Seed the people, their factions, the meeting and the annotations.
	 *
	 * Alice and Bob sit in Groen Perspectief, Dave in another faction whose
	 * membership has ended, Carol in none. Alice, Bob and Carol take part in
	 * the meeting; Dave does not.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->broken = false;
		$this->writes = 0;
		$this->store = [
			'person' => [
				'p-alice' => ['name' => 'Alice', 'nextcloudUserId' => 'alice'],
				'p-bob' => ['name' => 'Bob', 'nextcloudUserId' => 'bob'],
				'p-carol' => ['name' => 'Carol', 'nextcloudUserId' => 'carol'],
				'p-dave' => ['name' => 'Dave', 'nextcloudUserId' => 'dave'],
			],
			'membership' => [
				'm-alice' => ['person' => 'p-alice', 'faction' => self::GREEN],
				'm-bob' => ['person' => 'p-bob', 'faction' => self::GREEN],
				'm-carol' => ['person' => 'p-carol'],
				'm-dave' => ['person' => 'p-dave', 'faction' => self::GREEN, 'endDate' => '2026-01-01'],
				'm-dave-now' => ['person' => 'p-dave', 'faction' => self::BLUE],
			],
			'meeting' => [self::MEETING => ['title' => 'Raadsvergadering']],
			'agenda-item' => [self::ITEM => ['title' => 'Bestemmingsplan', 'meeting' => self::MEETING]],
			'annotation' => [
				'a-alice-private' => $this->annotation(author: 'alice', visibility: 'private'),
				'a-alice-faction' => $this->annotation(author: 'alice', visibility: 'faction', page: 2),
				'a-alice-body' => $this->annotation(author: 'alice', visibility: 'body', page: 4),
				'a-bob-private' => $this->annotation(author: 'bob', visibility: 'private'),
				'a-alice-v1' => $this->annotation(author: 'alice', visibility: 'private', version: '1750000000', label: 'v1'),
				'a-other-file' => ['fileId' => 99] + $this->annotation(author: 'alice', visibility: 'private'),
			],
		];
	}//end setUp()

	/**
	 * A stored annotation on the test file.
	 *
	 * @param string $author     The author
	 * @param string $visibility The visibility
	 * @param int    $page       The page
	 * @param string $version    The file version
	 * @param string $label      The version label
	 *
	 * @return array<string, mixed>
	 */
	private function annotation(string $author, string $visibility, int $page = 3, string $version = self::CURRENT, string $label = 'v2'): array {
		return [
			'annotationType' => 'sticky-note',
			'fileId' => self::FILE,
			'fileVersion' => $version,
			'versionLabel' => $label,
			'documentName' => 'Raadsvoorstel.pdf',
			'page' => $page,
			'point' => ['x' => 0.5, 'y' => 0.5],
			'bodyText' => 'Note by ' . $author,
			'color' => 'yellow',
			'visibility' => $visibility,
			'author' => $author,
			'agendaItem' => self::ITEM,
			'meeting' => self::MEETING,
			'createdAt' => '2026-11-0' . $page . 'T10:00:00+00:00',
		];
	}//end annotation()

	/**
	 * The controller, wired to the real services.
	 *
	 * @param string|null          $uid      The signed-in account, null when signed out
	 * @param array<string, mixed> $params   The request parameters
	 * @param bool                 $readable Whether the user can read the file
	 *
	 * @return AnnotationController
	 */
	private function controller(?string $uid, array $params = [], bool $readable = true): AnnotationController {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				return $data === null ? null : $this->entity(id: (string)$id, data: $data);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				if ($this->broken === true) {
					throw new RuntimeException('database down');
				}

				$filters = $config['filters'];
				$schema = (string)$filters['schema'];
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->store[$schema] ?? []) as $id => $data) {
					foreach ($filters as $field => $value) {
						if (($data[$field] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = $this->entity(id: (string)$id, data: $data);
				}

				return $found;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->writes++;
				$id = $uuid ?? 'a-new-' . $this->writes;
				$this->store[(string)$schema][$id] = $object;
				return $this->entity(id: $id, data: $object);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null): bool {
				$this->writes++;
				unset($this->store[(string)$schema][$uuid]);
				return true;
			}
		);

		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('isParticipant')->willReturnCallback(
			static fn (string $meetingId, string $nextcloudUid): bool => $meetingId === self::MEETING && in_array($nextcloudUid, ['alice', 'bob', 'carol'], true)
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-11-14T09:00:00+00:00'));

		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$visibility = new AnnotationVisibilityService(objectService: $objects, participants: $participants, timeFactory: $clock);
		$service = new AnnotationService(
			objectService: $objects,
			visibility: $visibility,
			versions: new DocumentVersionReader(rootFolder: $this->rootFolder(readable: $readable)),
			timeFactory: $clock,
			csvCell: new CsvCell()
		);

		return new AnnotationController(request: $request, annotations: $service, userSession: $session, logger: new NullLogger());
	}//end controller()

	/**
	 * A file tree holding the test file, owned by the griffie, with one stored
	 * earlier version, so the current one is v2.
	 *
	 * @param bool $readable Whether the user can read the file
	 *
	 * @return IRootFolder
	 */
	private function rootFolder(bool $readable): IRootFolder {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('griffie');

		$file = $this->createMock(File::class);
		$file->method('getMTime')->willReturn((int)self::CURRENT);
		$file->method('getName')->willReturn('Raadsvoorstel.pdf');
		$file->method('isReadable')->willReturn($readable);
		$file->method('getOwner')->willReturn($owner);
		$file->method('getInternalPath')->willReturn('files/Raad/Raadsvoorstel.pdf');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => $id === self::FILE ? $file : null);

		$stored = $this->createMock(File::class);
		$stored->method('getName')->willReturn('Raadsvoorstel.pdf.v1750000000');
		$unrelated = $this->createMock(File::class);
		$unrelated->method('getName')->willReturn('Begroting.pdf.v1750000000');
		$versions = $this->createMock(Folder::class);
		$versions->method('getDirectoryListing')->willReturn([$stored, $unrelated]);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$root->method('get')->willReturnCallback(
			static function (string $path) use ($versions): Folder {
				if ($path !== '/griffie/files_versions/Raad') {
					throw new NotFoundException($path);
				}

				return $versions;
			}
		);

		return $root;
	}//end rootFolder()

	/**
	 * An OpenRegister entity.
	 *
	 * @param string               $id   The uuid
	 * @param array<string, mixed> $data The object
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The ids in a list response.
	 *
	 * @param array<string, mixed> $data The response data
	 *
	 * @return list<string>
	 */
	private static function ids(array $data): array {
		$ids = array_map(static fn (array $row): string => $row['id'], $data['results']);
		sort($ids);
		return $ids;
	}//end ids()

	/**
	 * The author sees all their own annotations on the file, by page, the
	 * earlier-version one flagged not current with its label.
	 *
	 * @return void
	 */
	public function testTheAuthorSeesTheirOwnWithThePriorVersionLabelled(): void {
		$response = $this->controller(uid: 'alice')->index(fileId: self::FILE);
		self::assertSame(200, $response->getStatus());

		$data = $response->getData();
		self::assertSame(['a-alice-body', 'a-alice-faction', 'a-alice-private', 'a-alice-v1'], self::ids(data: $data));
		self::assertSame([2, 3, 3, 4], array_map(static fn (array $row): int => $row['page'], $data['results']));
		self::assertSame(self::CURRENT, $data['fileVersion']);
		self::assertSame('v2', $data['versionLabel']);
		self::assertTrue($data['canShareWithFaction']);

		$byId = array_column($data['results'], null, 'id');
		self::assertFalse($byId['a-alice-v1']['current']);
		self::assertSame('v1', $byId['a-alice-v1']['versionLabel']);
		self::assertTrue($byId['a-alice-private']['current']);
	}//end testTheAuthorSeesTheirOwnWithThePriorVersionLabelled()

	/**
	 * A faction colleague gets the faction and body notes and their own, never
	 * Alice's private ones, whatever the query says.
	 *
	 * @return void
	 */
	public function testAFactionColleagueNeverGetsAnotherUsersPrivateNote(): void {
		$response = $this->controller(uid: 'bob', params: ['author' => 'alice', 'visibility' => 'private', '_rbac' => 'false'])->index(fileId: self::FILE);
		self::assertSame(['a-alice-body', 'a-alice-faction', 'a-bob-private'], self::ids(data: $response->getData()));
	}//end testAFactionColleagueNeverGetsAnotherUsersPrivateNote()

	/**
	 * A participant outside the faction gets the body note only; someone
	 * outside both, whose old membership in the faction has ended, gets
	 * nothing.
	 *
	 * @return void
	 */
	public function testFactionAndBodyNotesReachOnlyTheirAudience(): void {
		self::assertSame(['a-alice-body'], self::ids(data: $this->controller(uid: 'carol')->index(fileId: self::FILE)->getData()));
		self::assertSame([], self::ids(data: $this->controller(uid: 'dave')->index(fileId: self::FILE)->getData()));
		self::assertFalse($this->controller(uid: 'carol')->index(fileId: self::FILE)->getData()['canShareWithFaction']);
	}//end testFactionAndBodyNotesReachOnlyTheirAudience()

	/**
	 * A file the user cannot read answers 404, and so do its annotations.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsNotFound(): void {
		self::assertSame(404, $this->controller(uid: 'alice', readable: false)->index(fileId: self::FILE)->getStatus());
		self::assertSame(404, $this->controller(uid: 'alice')->index(fileId: 12345)->getStatus());
		self::assertSame(401, $this->controller(uid: null)->index(fileId: self::FILE)->getStatus());
	}//end testAnUnreadableFileIsNotFound()

	/**
	 * A new highlight takes its author from the session and its version from
	 * the file, ignoring what the request claims, and is private by default.
	 *
	 * @return void
	 */
	public function testCreateStampsAuthorAndVersionFromTheServer(): void {
		$params = [
			'annotationType' => 'highlight',
			'page' => 3,
			'quads' => [[0.1, 0.2, 0.4, 0.2, 0.1, 0.25, 0.4, 0.25]],
			'quote' => 'maximale bouwhoogte van 12 meter',
			'agendaItem' => self::ITEM,
			'author' => 'mallory',
			'fileVersion' => 'forged',
			'versionLabel' => 'v9',
			'fileId' => 1,
		];
		$response = $this->controller(uid: 'carol', params: $params)->create(fileId: self::FILE);
		self::assertSame(201, $response->getStatus());

		$saved = $response->getData()['annotation'];
		self::assertSame('carol', $saved['author']);
		self::assertSame('private', $saved['visibility']);
		self::assertSame(self::FILE, $saved['fileId']);
		self::assertSame(self::CURRENT, $saved['fileVersion']);
		self::assertSame('v2', $saved['versionLabel']);
		self::assertSame('Raadsvoorstel.pdf', $saved['documentName']);
		self::assertSame(self::MEETING, $saved['meeting']);
		self::assertSame('yellow', $saved['color']);
		self::assertSame('2026-11-14T09:00:00+00:00', $saved['createdAt']);
		self::assertSame([[0.1, 0.2, 0.4, 0.2, 0.1, 0.25, 0.4, 0.25]], $saved['quads']);
		self::assertSame('maximale bouwhoogte van 12 meter', $saved['quote']);
		self::assertTrue($saved['current']);
	}//end testCreateStampsAuthorAndVersionFromTheServer()

	/**
	 * A drawing stores its stroke; a sticky note its point.
	 *
	 * @return void
	 */
	public function testDrawingsAndNotesKeepTheirAnchor(): void {
		$drawing = $this->controller(uid: 'alice', params: ['annotationType' => 'drawing', 'page' => 5, 'path' => [[0.1, 0.1], [0.2, 0.3], [0.4, 0.3]]])->create(fileId: self::FILE);
		self::assertSame(201, $drawing->getStatus());
		self::assertSame([[0.1, 0.1], [0.2, 0.3], [0.4, 0.3]], $drawing->getData()['annotation']['path']);
		self::assertSame(5, $drawing->getData()['annotation']['page']);

		$note = $this->controller(uid: 'alice', params: ['annotationType' => 'sticky-note', 'page' => 2, 'point' => ['x' => 0, 'y' => 1], 'bodyText' => 'Navragen bij wethouder'])->create(fileId: self::FILE);
		self::assertSame(['x' => 0.0, 'y' => 1.0], $note->getData()['annotation']['point']);
		self::assertSame('Navragen bij wethouder', $note->getData()['annotation']['bodyText']);
	}//end testDrawingsAndNotesKeepTheirAnchor()

	/**
	 * Refused content answers 422 and writes nothing: a faction share by
	 * someone in no faction, a body share without a meeting, a position off
	 * the page, a page of zero, an unknown type.
	 *
	 * @return void
	 */
	public function testRefusedContentIs422(): void {
		$note = ['annotationType' => 'sticky-note', 'page' => 1, 'point' => ['x' => 0.5, 'y' => 0.5]];
		$cases = [
			'faction without faction' => ['uid' => 'carol', 'params' => $note + ['visibility' => 'faction', 'meeting' => self::MEETING]],
			'body without meeting' => ['uid' => 'alice', 'params' => $note + ['visibility' => 'body']],
			'unknown visibility' => ['uid' => 'alice', 'params' => $note + ['visibility' => 'public']],
			'off the page' => ['uid' => 'alice', 'params' => ['point' => ['x' => 1.5, 'y' => 0.5]] + $note],
			'page zero' => ['uid' => 'alice', 'params' => ['page' => 0] + $note],
			'unknown type' => ['uid' => 'alice', 'params' => ['annotationType' => 'stamp'] + $note],
			'short quad' => ['uid' => 'alice', 'params' => ['annotationType' => 'highlight', 'page' => 1, 'quads' => [[0.1, 0.2]]]],
			'one-point drawing' => ['uid' => 'alice', 'params' => ['annotationType' => 'drawing', 'page' => 1, 'path' => [[0.1, 0.2]]]],
			'unknown meeting' => ['uid' => 'alice', 'params' => $note + ['meeting' => '7b2c0000-0000-4000-8000-000000000099']],
			'unknown colour' => ['uid' => 'alice', 'params' => $note + ['color' => 'purple']],
		];
		foreach ($cases as $name => $case) {
			$response = $this->controller(uid: $case['uid'], params: $case['params'])->create(fileId: self::FILE);
			self::assertSame(422, $response->getStatus(), $name);
		}

		self::assertSame(0, $this->writes);

		$shared = $this->controller(uid: 'bob', params: $note + ['visibility' => 'faction'])->create(fileId: self::FILE);
		self::assertSame(201, $shared->getStatus());
		self::assertSame('faction', $shared->getData()['annotation']['visibility']);
	}//end testRefusedContentIs422()

	/**
	 * Only the author changes or deletes: a viewer of a shared note gets 403
	 * and the note is unchanged; a stranger's private note answers 404, the
	 * same as one that does not exist.
	 *
	 * @return void
	 */
	public function testOnlyTheAuthorChangesOrDeletes(): void {
		$before = $this->store['annotation']['a-alice-body'];

		self::assertSame(403, $this->controller(uid: 'carol', params: ['bodyText' => 'changed'])->update(id: 'a-alice-body')->getStatus());
		self::assertSame(403, $this->controller(uid: 'bob')->destroy(id: 'a-alice-faction')->getStatus());
		self::assertSame(404, $this->controller(uid: 'bob', params: ['bodyText' => 'x'])->update(id: 'a-alice-private')->getStatus());
		self::assertSame(404, $this->controller(uid: 'bob')->destroy(id: 'a-alice-private')->getStatus());
		self::assertSame(404, $this->controller(uid: 'bob')->destroy(id: 'a-missing')->getStatus());
		self::assertSame(0, $this->writes);
		self::assertSame($before, $this->store['annotation']['a-alice-body']);

		$edited = $this->controller(uid: 'alice', params: ['bodyText' => 'Aangepast', 'color' => 'green', 'visibility' => 'private', 'author' => 'bob', 'id' => 'a-alice-body'])->update(id: 'a-alice-body');
		self::assertSame(200, $edited->getStatus());
		self::assertSame('Aangepast', $this->store['annotation']['a-alice-body']['bodyText']);
		self::assertSame('green', $this->store['annotation']['a-alice-body']['color']);
		self::assertSame('private', $this->store['annotation']['a-alice-body']['visibility']);
		self::assertSame('alice', $this->store['annotation']['a-alice-body']['author']);
		self::assertSame(self::CURRENT, $this->store['annotation']['a-alice-body']['fileVersion']);

		self::assertSame(200, $this->controller(uid: 'alice')->destroy(id: 'a-alice-faction')->getStatus());
		self::assertArrayNotHasKey('a-alice-faction', $this->store['annotation']);
	}//end testOnlyTheAuthorChangesOrDeletes()

	/**
	 * The counts per agenda item count only what the caller may see: two own
	 * private notes, one faction note from a colleague and four private notes
	 * of others make three.
	 *
	 * @return void
	 */
	public function testCountsCountOnlyWhatTheCallerMaySee(): void {
		$this->store['annotation'] = [
			'b1' => $this->annotation(author: 'bob', visibility: 'private'),
			'b2' => $this->annotation(author: 'bob', visibility: 'private'),
			'a1' => $this->annotation(author: 'alice', visibility: 'faction'),
			'c1' => $this->annotation(author: 'carol', visibility: 'private'),
			'c2' => $this->annotation(author: 'carol', visibility: 'private'),
			'd1' => $this->annotation(author: 'dave', visibility: 'private'),
			'd2' => $this->annotation(author: 'dave', visibility: 'private'),
			'o1' => ['agendaItem' => self::OTHER_ITEM] + $this->annotation(author: 'dave', visibility: 'private'),
		];

		$response = $this->controller(uid: 'bob')->counts(meetingId: self::MEETING);
		self::assertSame(200, $response->getStatus());
		self::assertSame([self::ITEM => 3], (array)$response->getData()['counts']);

		self::assertSame([], (array)$this->controller(uid: 'alice')->counts(meetingId: '7b2c0000-0000-4000-8000-000000000099')->getData()['counts']);
	}//end testCountsCountOnlyWhatTheCallerMaySee()

	/**
	 * The export holds the caller's own annotations only, as CSV or JSON.
	 *
	 * @return void
	 */
	public function testTheExportHoldsOnlyTheCallersOwn(): void {
		$csv = $this->controller(uid: 'bob')->export(format: 'csv');
		self::assertInstanceOf(DataDisplayResponse::class, $csv);
		self::assertSame(
			"document,page,type,text,visibility,version,date\r\nRaadsvoorstel.pdf,3,sticky-note,Note by bob,private,v2,2026-11-03T10:00:00+00:00\r\n",
			$csv->render()
		);

		$json = $this->controller(uid: 'alice')->export(format: 'json');
		$rows = json_decode($json->render(), true)['annotations'];
		self::assertCount(5, $rows);
		self::assertSame(['Note by alice'], array_values(array_unique(array_column($rows, 'text'))));
		self::assertSame(['document', 'page', 'type', 'text', 'visibility', 'version', 'date'], array_keys($rows[0]));

		self::assertSame(400, $this->controller(uid: 'alice')->export(format: 'xml')->getStatus());
		self::assertSame(401, $this->controller(uid: null)->export(format: 'csv')->getStatus());
	}//end testTheExportHoldsOnlyTheCallersOwn()

	/**
	 * Deleting one's own annotations removes every one of them, shared ones
	 * included, and nobody else's; the counts follow.
	 *
	 * @return void
	 */
	public function testDeletingOwnRemovesAllOfThemAndOnlyThem(): void {
		$response = $this->controller(uid: 'alice')->destroyOwn();
		self::assertSame(200, $response->getStatus());
		self::assertSame(5, $response->getData()['deleted']);
		self::assertSame(['a-bob-private'], array_keys($this->store['annotation']));

		self::assertSame([self::ITEM => 1], (array)$this->controller(uid: 'bob')->counts(meetingId: self::MEETING)->getData()['counts']);
		self::assertSame([], self::ids(data: $this->controller(uid: 'carol')->index(fileId: self::FILE)->getData()));
	}//end testDeletingOwnRemovesAllOfThemAndOnlyThem()

	/**
	 * Signed out is 401 everywhere; an OpenRegister failure is 500, never a
	 * partial or unfiltered answer.
	 *
	 * @return void
	 */
	public function testSignedOutAndFailuresMapToTheirStatus(): void {
		self::assertSame(401, $this->controller(uid: null)->counts(meetingId: self::MEETING)->getStatus());
		self::assertSame(401, $this->controller(uid: null)->destroyOwn()->getStatus());
		self::assertSame(401, $this->controller(uid: null)->create(fileId: self::FILE)->getStatus());

		$this->broken = true;
		self::assertSame(500, $this->controller(uid: 'bob')->index(fileId: self::FILE)->getStatus());
		self::assertSame(500, $this->controller(uid: 'bob')->counts(meetingId: self::MEETING)->getStatus());
		self::assertSame(500, $this->controller(uid: 'bob')->export(format: 'csv')->getStatus());
	}//end testSignedOutAndFailuresMapToTheirStatus()
}//end class
