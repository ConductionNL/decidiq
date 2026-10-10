<?php

/**
 * Unit tests for DestructionCertificateRenderer: OpenRegister's destruction
 * certificate is fetched, rendered (PDF through filinq, markdown without it),
 * filed with the meeting and kept on the dossier, with the records
 * OpenRegister skipped in view.
 *
 * The OpenRegister fake follows openregister#4228 (feat/archival-for-apps
 * @42995c06de): DestructionListRepository::isConfigured(), find(uuid) whose
 * object carries status, skippedHolds, skippedErrors and skippedDecisions
 * (DestructionExecutionJob), and findCertificates(?listUuid) answering
 * {results, missing} with each certificate as RetentionService::
 * generateDestructionCertificate() writes it plus uuid and destructionListUuid.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\Records\ArchivistGuard;
use OCA\Decidiq\Service\Records\DestructionCertificateRenderer;
use OCA\Decidiq\Service\Records\OpenRegisterArchive;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for rendering OpenRegister's verklaring van vernietiging.
 *
 * @covers \OCA\Decidiq\Service\Records\DestructionCertificateRenderer
 * @covers \OCA\Decidiq\Service\Records\OpenRegisterArchive
 * @covers \OCA\Decidiq\Service\Records\ArchivistGuard
 * @uses   \OCA\Decidiq\Support\FilinqPdf
 * @uses   \OCA\Decidiq\Support\FleetAppId
 * @uses   \OCA\Decidiq\Exception\DossierRefusedException
 */
class DestructionCertificateRendererTest extends TestCase {

	private const DOSSIER = '7a1f0a10-0000-4000-8000-0000000000d1';

	private const MEETING = '7a1f0a10-0000-4000-8000-000000000001';

	private const LIST = '7a1f0a10-0000-4000-8000-0000000000e0';

	private const CERT = '7a1f0a10-0000-4000-8000-0000000000c0';

	/**
	 * Objects by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every dossier write.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Files written to the meeting folder: [subfolder, name, content].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $files = [];

	/**
	 * Destruction lists in OpenRegister, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $lists = [];

	/**
	 * Certificates in OpenRegister, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $certificates = [];

	/**
	 * Whether OpenRegister has a destruction-list register.
	 *
	 * @var bool
	 */
	public bool $configured = true;

	/**
	 * Whether filinq renders PDFs on this instance.
	 *
	 * @var bool
	 */
	private bool $filinq = false;

	/**
	 * Whether the caller is an archivist.
	 *
	 * @var bool
	 */
	private bool $archivist = true;

	/**
	 * The renderer under test, with its real siblings.
	 *
	 * @return DestructionCertificateRenderer
	 */
	private function renderer(): DestructionCertificateRenderer {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($data);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = $object;
				$this->store[(string)$schema][(string)$uuid] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid((string)$uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$test = $this;
		$repository = new class($test) {
			public function __construct(private DestructionCertificateRendererTest $test) {
			}

			public function isConfigured(): bool {
				return $this->test->configured;
			}

			public function find(string $uuid): ?ObjectEntity {
				$data = ($this->test->lists[$uuid] ?? $this->test->certificates[$uuid] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($data);
				return $entity;
			}

			public function findCertificates(?string $listUuid = null): array {
				$results = [];
				$missing = [];
				foreach ($this->test->lists as $uuid => $list) {
					if (($list['status'] ?? '') !== 'executed' || ($listUuid !== null && $uuid !== $listUuid)) {
						continue;
					}

					$certUuid = ($list['certificateUuid'] ?? null);
					if (isset($this->test->certificates[$certUuid]) === false) {
						$missing[] = $uuid;
						continue;
					}

					$results[] = array_merge($this->test->certificates[$certUuid], ['uuid' => $certUuid, 'destructionListUuid' => $uuid]);
				}

				return ['results' => $results, 'missing' => $missing];
			}
		};
		$pdf = new class {
			public function generatePdfFromHtml(string $html, array $options): string {
				return '%PDF-1.7 ' . $options['title'] . ' ' . strlen($html);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($repository, $pdf): object {
				if ($id === 'OCA\OpenRegister\Service\Archival\DestructionListRepository') {
					return $repository;
				}

				if ($this->filinq === true && str_ends_with($id, '\Service\PdfService') === true) {
					return $pdf;
				}

				throw new class('not here') extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$folders = $this->createMock(MeetingFolderService::class);
		$folders->method('writeMeetingFile')->willReturnCallback(
			function (array $meeting, string $subfolder, string $fileName, string $content): string {
				$this->files[] = [$subfolder, $fileName, $content];
				return '/Decidiq/Raadsvergadering/' . $subfolder . '/' . $fileName;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(fn (): bool => $this->archivist);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new DestructionCertificateRenderer(
			objectService: $objectService,
			archive: new OpenRegisterArchive(container: $container, logger: new NullLogger()),
			guard: new ArchivistGuard(userSession: $session, groupManager: $groups),
			pdf: new FilinqPdf(container: $container, logger: new NullLogger()),
			folders: $folders,
			l10n: $l10n,
		);
	}//end renderer()

	/**
	 * A destroyed dossier whose destruction list OpenRegister has executed,
	 * with one record kept back under a legal hold.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->saves = [];
		$this->files = [];
		$this->configured = true;
		$this->filinq = false;
		$this->archivist = true;
		$this->store['meeting'][self::MEETING] = ['title' => 'MT-overleg Q1 2016'];
		$this->store['archival-dossier'][self::DOSSIER] = [
			'title' => 'MT-overleg Q1 2016',
			'meeting' => self::MEETING,
			'lifecycle' => 'destroyed',
			'disposition' => 'destruction',
			'destructionList' => self::LIST,
			'securityClassification' => 'intern',
		];
		$this->lists[self::LIST] = ['status' => 'executed', 'destroyedCount' => 3, 'skippedHolds' => 1, 'skippedErrors' => 0, 'skippedDecisions' => 0, 'certificateUuid' => self::CERT];
		$this->certificates[self::CERT] = [
			'type' => 'verklaring_van_vernietiging',
			'destructionDate' => '2026-10-01T10:00:00+02:00',
			'approvedBy' => ['archivaris1', 'archivaris2'],
			'destructionListUuid' => self::LIST,
			'totalDestroyed' => 3,
			'groupedBySchema' => [['schema' => 'minutes', 'classification' => '11.1', 'count' => 3]],
			'selectielijstBron' => ['https://selectielijst.openzaak.nl/api/v1/resultaten/11.1'],
			'complianceStatement' => 'Vernietiging conform Archiefwet 1995 en Archiefbesluit 1995',
			'immutable' => true,
		];
	}//end setUp()

	/**
	 * Without filinq the certificate is filed as markdown: every field
	 * OpenRegister wrote is in it, unchanged, and the skipped record is named.
	 *
	 * @return void
	 */
	public function testTheCertificateIsRenderedAsMarkdownWithTheSkippedRecords(): void {
		$dossier = $this->renderer()->render(dossierId: self::DOSSIER);

		self::assertCount(1, $this->files);
		[$subfolder, $name, $content] = $this->files[0];
		self::assertSame('Archive', $subfolder);
		self::assertSame('vernietigingsverklaring-' . self::LIST . '.md', $name);
		self::assertStringContainsString('Vernietiging conform Archiefwet 1995 en Archiefbesluit 1995', $content);
		self::assertStringContainsString('archivaris1, archivaris2', $content);
		self::assertStringContainsString('2026-10-01T10:00:00+02:00', $content);
		self::assertStringContainsString('minutes', $content);
		self::assertMatchesRegularExpression('/skippedHolds\W+1/', $content, 'the record kept back under a legal hold is in view');

		$kept = $dossier['destructionCertificate'];
		self::assertSame(self::CERT, $kept['certificate']);
		self::assertSame(self::LIST, $kept['destructionList']);
		self::assertSame('markdown', $kept['format']);
		self::assertSame('/Decidiq/Raadsvergadering/Archive/' . $name, $kept['path']);
		self::assertSame(['skippedHolds' => 1, 'skippedErrors' => 0, 'skippedDecisions' => 0], $kept['skipped']);
		self::assertValidDossier(dossier: $this->saves[0]);
	}//end testTheCertificateIsRenderedAsMarkdownWithTheSkippedRecords()

	/**
	 * With filinq the certificate is filed as a PDF.
	 *
	 * @return void
	 */
	public function testWithFilinqTheCertificateIsAPdf(): void {
		$this->filinq = true;

		$dossier = $this->renderer()->render(dossierId: self::DOSSIER);

		self::assertSame('vernietigingsverklaring-' . self::LIST . '.pdf', $this->files[0][1]);
		self::assertStringStartsWith('%PDF', $this->files[0][2]);
		self::assertSame('pdf', $dossier['destructionCertificate']['format']);
	}//end testWithFilinqTheCertificateIsAPdf()

	/**
	 * No certificate yet, no destruction register, or no destruction list on
	 * the dossier: refused, nothing filed, nothing written.
	 *
	 * @return void
	 */
	public function testNoCertificateIsRefusedAndNothingIsFiled(): void {
		$this->lists[self::LIST]['status'] = 'approved';
		$this->assertRefused(reason: DossierRefusedException::CERTIFICATE_MISSING);

		$this->lists[self::LIST]['status'] = 'executed';
		unset($this->certificates[self::CERT]);
		$this->assertRefused(reason: DossierRefusedException::CERTIFICATE_MISSING);

		$this->configured = false;
		$this->assertRefused(reason: DossierRefusedException::DESTRUCTION_UNAVAILABLE);

		$this->configured = true;
		unset($this->store['archival-dossier'][self::DOSSIER]['destructionList']);
		$this->assertRefused(reason: DossierRefusedException::CERTIFICATE_MISSING);

		self::assertSame([], $this->files);
		self::assertSame([], $this->saves);
	}//end testNoCertificateIsRefusedAndNothingIsFiled()

	/**
	 * Only an archivist or an administrator renders it.
	 *
	 * @return void
	 */
	public function testOnlyAnArchivistRendersIt(): void {
		$this->archivist = false;

		$this->expectException(AccessDeniedException::class);
		$this->renderer()->render(dossierId: self::DOSSIER);
	}//end testOnlyAnArchivistRendersIt()

	/**
	 * Rendering is refused with the given reason.
	 *
	 * @param string $reason The expected reason
	 *
	 * @return void
	 */
	private function assertRefused(string $reason): void {
		try {
			$this->renderer()->render(dossierId: self::DOSSIER);
			self::fail('refused: ' . $reason);
		} catch (DossierRefusedException $e) {
			self::assertSame($reason, $e->getReason());
			self::assertTrue($e->isConflict());
		}
	}//end assertRefused()

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

		$result = (new Validator())->validate(
			json_decode((string)json_encode($dossier)),
			json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), 'The dossier written validates: ' . json_encode($dossier));
	}//end assertValidDossier()
}//end class
