<?php

/**
 * Decidiq CaseSystemExchangeTest
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\BackgroundJob\SendMeetingFileJob;
use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Service\CaseDocumentService;
use OCA\Decidiq\Service\CaseExchangeRecords;
use OCA\Decidiq\Service\CaseSystemClient;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\DecisionListService;
use OCA\Decidiq\Service\MeetingFileService;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\MinutesDocumentService;
use OCA\Decidiq\Service\ProofPackageService;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemCallServiceFake;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemWorld;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The council meeting of 12 March, one item linked to case Z-2026-00412.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 *
 * @uses \OCA\Decidiq\Service\CaseSystemClient
 * @uses \OCA\Decidiq\Service\CaseExchangeRecords
 * @uses \OCA\Decidiq\Service\CaseDocumentService
 * @uses \OCA\Decidiq\Service\DecisionListService
 * @uses \OCA\Decidiq\Service\MeetingFileService
 * @uses \OCA\Decidiq\Service\CaseSystemExchangeService
 * @uses \OCA\Decidiq\Service\SigningAnswer
 * @uses \OCA\Decidiq\Support\FilinqPdf
 * @uses \OCA\Decidiq\Support\FleetAppId
 * @uses \OCA\Decidiq\Exception\CaseSystemException
 */
class CaseSystemExchangeTest extends TestCase {

	private const CASE_ITEM = 'https://zaken.example.org/api/v1/zaken/00000000-0000-0000-0000-000000000412';

	private const CASE_MEETING = 'https://zaken.example.org/api/v1/zaken/00000000-0000-0000-0000-000000000500';

	private CaseSystemWorld $world;

	/**
	 * Files per agenda item: [id, name, content].
	 *
	 * @var array<string, list<array{0:int,1:string,2:string}>>
	 */
	private array $itemFiles = [];

	/**
	 * Files added to items: [item, name, content].
	 *
	 * @var list<array{0:string,1:string,2:string}>
	 */
	private array $added = [];

	/**
	 * Meeting folder writes: name => content.
	 *
	 * @var array<string,string>
	 */
	private array $written = [];

	private bool $filinq = true;

	private IJobList $jobList;

	private string $meeting;

	private string $itemLinked;

	private string $itemPlain;

	private string $minutes;

	/**
	 * Set up an empty world with a connected case system.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world   = new CaseSystemWorld();
		$this->jobList = $this->createMock(IJobList::class);
		$source        = $this->world->put(schema: 'source', data: ['slug' => 'zgw-zaken'], register: 'integriq');
		$this->world->put(schema: 'app_connection', data: ['app' => 'decidiq', 'key' => 'case-system', 'source' => $source], register: 'integriq');
		$this->world->answers['add-document'] = static fn (array $body): array => ['url' => 'https://documenten.example.org/' . md5((string)$body['name'])];
		$this->world->answers['create-case']  = ['url' => self::CASE_MEETING, 'identification' => 'Z-2026-00500'];
	}//end setUp()

	/**
	 * The council meeting of 12 March with approved minutes, one decision
	 * under the linked item and a paper on each item.
	 *
	 * @param string $lifecycle The minutes lifecycle.
	 *
	 * @return void
	 */
	private function councilMeeting(string $lifecycle='approved'): void {
		$this->meeting    = $this->world->put(schema: 'meeting', data: ['title' => 'Raadsvergadering 12 maart', 'meetingType' => 'regular', 'scheduledDate' => '2026-03-12T19:30:00+01:00', 'meetingMode' => 'in-person', 'lifecycle' => 'closed']);
		$this->itemLinked = $this->world->put(schema: 'agenda-item', data: ['title' => 'Vaststelling omgevingsvisie', 'itemType' => 'decision', 'orderNumber' => 1, 'meeting' => $this->meeting, 'caseReference' => ['url' => self::CASE_ITEM, 'identification' => 'Z-2026-00412', 'title' => 'Omgevingsvisie 2040']]);
		$this->itemPlain  = $this->world->put(schema: 'agenda-item', data: ['title' => 'Mededelingen', 'itemType' => 'informational', 'orderNumber' => 2, 'meeting' => $this->meeting]);
		$this->world->put(schema: 'decision', data: ['title' => 'Omgevingsvisie 2040 vastgesteld', 'text' => 'De raad stelt de omgevingsvisie vast.', 'decisionType' => 'council-decision', 'outcome' => 'adopted', 'meeting' => $this->meeting, 'agendaItem' => $this->itemLinked]);
		$this->minutes = $this->world->put(schema: 'minutes', data: ['title' => 'Notulen 12 maart', 'lifecycle' => $lifecycle, 'meeting' => $this->meeting, 'approvedAt' => '2026-04-02T20:00:00+02:00', 'signedBy' => ['Voorzitter Jansen', 'Griffier De Vries'], 'generatedDocuments' => [['path' => 'Decidesk/Raad/2026-03-12 Raadsvergadering/Minutes/Notulen.pdf']]]);
		$this->itemFiles[$this->itemLinked] = [[501, 'Raadsvoorstel omgevingsvisie.pdf', '%PDF raadsvoorstel']];
		$this->itemFiles[$this->itemPlain]  = [[502, 'Bijlage mededelingen.pdf', '%PDF bijlage']];
	}//end councilMeeting()

	/**
	 * An entity over a world object.
	 *
	 * @param string $id The id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id): ObjectEntity {
		$data   = (['id' => $id] + $this->world->objects[$id]['data']);
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($id);
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * OpenRegister's object service over the world.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => (isset($this->world->objects[(string)$id]) === true ? $this->entity(id: (string)$id) : null));
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => array_map(fn (string $id): ObjectEntity => $this->entity(id: $id), $this->world->match(config: $config)));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				return $this->entity(id: $this->world->save(data: $object, register: (string)$register, schema: (string)$schema, id: $uuid));
			}
		);
		return $objects;
	}//end objects()

	/**
	 * The container: integriq's call service, OpenRegister's file service, filinq when present.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$files = $this->createMock(FileService::class);
		$files->method('getFiles')->willReturnCallback(
			function (mixed $object): array {
				return array_map(
					function (array $spec): File {
						$node = $this->createMock(File::class);
						$node->method('getId')->willReturn($spec[0]);
						$node->method('getName')->willReturn($spec[1]);
						$node->method('getContent')->willReturn($spec[2]);
						return $node;
					},
					($this->itemFiles[(string)$object] ?? [])
				);
			}
		);
		$files->method('addFile')->willReturnCallback(
			function (mixed $object, string $name, mixed $content): File {
				$this->added[] = [(string)$object, $name, (string)$content];
				$file          = $this->createMock(File::class);
				$file->method('getId')->willReturn(900 + count($this->added));
				return $file;
			}
		);
		$pdf      = new class {
			/**
			 * Render HTML to PDF bytes.
			 *
			 * @param string              $html    The HTML.
			 * @param array<string,mixed> $options Options.
			 *
			 * @return string
			 */
			public function generatePdfFromHtml(string $html, array $options=[]): string {
				return '%PDF ' . $html;
			}//end generatePdfFromHtml()
		};
		$services = [
			'OCA\Integriq\Service\CallService' => new CaseSystemCallServiceFake(world: $this->world),
			'OCA\OpenRegister\Service\FileService' => $files,
		];
		if ($this->filinq === true) {
			$services['OCA\Filinq\Service\PdfService'] = $pdf;
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * Translations that return the English source.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));
		return $l10n;
	}//end l10n()

	/**
	 * The records service.
	 *
	 * @return CaseExchangeRecords
	 */
	private function records(): CaseExchangeRecords {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-04-02T21:00:00+02:00'));
		return new CaseExchangeRecords(objectService: $this->objects(), timeFactory: $time);
	}//end records()

	/**
	 * The client.
	 *
	 * @return CaseSystemClient
	 */
	private function client(): CaseSystemClient {
		return new CaseSystemClient(objectService: $this->objects(), container: $this->container(), answers: new SigningAnswer());
	}//end client()

	/**
	 * The meeting folder: remembers writes, reads back any path.
	 *
	 * @return MeetingFolderService
	 */
	private function folders(): MeetingFolderService {
		$folders = $this->createMock(MeetingFolderService::class);
		$folders->method('writeMeetingFile')->willReturnCallback(
			function (array $meeting, string $subfolder, string $fileName, string $content): string {
				$this->written[$fileName] = $content;
				return 'Decidesk/Raad/2026-03-12 Raadsvergadering/' . $subfolder . '/' . $fileName;
			}
		);
		$folders->method('readMeetingFile')->willReturnCallback(static fn (string $path): string => 'content of ' . basename($path));
		return $folders;
	}//end folders()

	/**
	 * The decision list service.
	 *
	 * @return DecisionListService
	 */
	private function decisionList(): DecisionListService {
		return new DecisionListService(objectService: $this->objects(), pdf: new FilinqPdf(container: $this->container(), logger: new NullLogger()), folders: $this->folders(), l10n: $this->l10n());
	}//end decisionList()

	/**
	 * The exchange service over the whole chain.
	 *
	 * @return CaseSystemExchangeService
	 */
	private function exchange(): CaseSystemExchangeService {
		$proof = $this->createMock(ProofPackageService::class);
		$proof->method('assemble')->willReturn(['files' => ['Decidesk/Raad/2026-03-12 Raadsvergadering/Minutes/Proof package.json']]);
		$meetingFile = new MeetingFileService(
			objectService: $this->objects(),
			decisionList: $this->decisionList(),
			minutesDoc: $this->createMock(MinutesDocumentService::class),
			proofPackage: $proof,
			folders: $this->folders(),
			pdf: new FilinqPdf(container: $this->container(), logger: new NullLogger()),
			container: $this->container(),
			l10n: $this->l10n()
		);
		return new CaseSystemExchangeService(client: $this->client(), meetingFile: $meetingFile, records: $this->records(), jobList: $this->jobList);
	}//end exchange()

	/**
	 * The document service.
	 *
	 * @return CaseDocumentService
	 */
	private function documents(): CaseDocumentService {
		return new CaseDocumentService(objectService: $this->objects(), client: $this->client(), records: $this->records(), container: $this->container());
	}//end documents()

	/**
	 * A case that does not exist is refused with its number.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @return void
	 */
	public function testACaseThatDoesNotExistIsRefusedWithItsNumber(): void {
		$this->councilMeeting();
		$this->world->answers['read-case'] = ['found' => false];

		try {
			$this->documents()->link(itemId: $this->itemPlain, reference: 'Z-2026-99999');
			$this->fail('An unknown case was linked');
		} catch (CaseSystemException $e) {
			$this->assertSame('Case Z-2026-99999 was not found in the case system', $e->getMessage());
			$this->assertSame(422, $e->getStatus());
		}

		$this->assertArrayNotHasKey('caseReference', $this->world->objects[$this->itemPlain]['data']);
	}//end testACaseThatDoesNotExistIsRefusedWithItsNumber()

	/**
	 * Linking reads the case once and stores it on the item, in a payload the register schema accepts.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @return void
	 */
	public function testTheGriffierLinksARaadsvoorstelToItsCase(): void {
		$this->councilMeeting();
		$this->world->answers['read-case'] = ['url' => self::CASE_ITEM, 'identification' => 'Z-2026-00412', 'title' => 'Omgevingsvisie 2040'];

		$case = $this->documents()->link(itemId: $this->itemPlain, reference: 'Z-2026-00412');

		$this->assertSame('Omgevingsvisie 2040', $case['title']);
		$this->assertSame($case, $this->world->objects[$this->itemPlain]['data']['caseReference']);
		$this->assertSame([['reference' => 'Z-2026-00412']], $this->world->callsOf('read-case'));
		$this->assertSame([], $this->world->invalid);
	}//end testTheGriffierLinksARaadsvoorstelToItsCase()

	/**
	 * Fetching copies the chosen documents onto the item, and a document fetched before is skipped.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @return void
	 */
	public function testADocumentFetchedBeforeIsNotFetchedAgain(): void {
		$this->councilMeeting();
		$voorstel = 'https://documenten.example.org/raadsvoorstel';
		$kaart    = 'https://documenten.example.org/kaart';
		$this->world->answers['list-documents'] = ['documents' => [['url' => $voorstel, 'name' => 'Raadsvoorstel omgevingsvisie.pdf'], ['url' => $kaart, 'name' => 'Bijlage 1 kaart.pdf']]];
		$this->world->answers['read-document']  = static fn (array $body): array => ['name' => basename((string)$body['document']) . '.pdf', 'content' => base64_encode('%PDF ' . $body['document'])];

		$first = $this->documents()->fetch(itemId: $this->itemLinked, urls: [$voorstel], userId: 'griffier');
		$this->assertSame('sent', $first['lines'][0]['status']);

		$listed = $this->documents()->listDocuments(itemId: $this->itemLinked);
		$this->assertSame([true, false], array_column($listed, 'fetched'));

		$second = $this->documents()->fetch(itemId: $this->itemLinked, urls: [$voorstel, $kaart], userId: 'griffier');

		$this->assertSame([$kaart], array_column($second['lines'], 'remoteUrl'));
		$this->assertSame([$this->itemLinked, $this->itemLinked], array_column($this->added, 0));
		$this->assertSame(['raadsvoorstel.pdf', 'kaart.pdf'], array_column($this->added, 1));
		$this->assertCount(2, $this->world->callsOf('read-document'));
		$this->assertSame([], $this->world->invalid);
	}//end testADocumentFetchedBeforeIsNotFetchedAgain()

	/**
	 * The decision list holds every decision, its outcome and both signers.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return void
	 */
	public function testTheDecisionListFollowsTheMinutes(): void {
		$this->councilMeeting(lifecycle: 'signed');
		$this->world->put(schema: 'decision', data: ['title' => 'Motie groen dak verworpen', 'text' => 'x', 'decisionType' => 'motion', 'outcome' => 'rejected', 'meeting' => $this->meeting, 'agendaItem' => $this->itemPlain]);
		$third = $this->world->put(schema: 'decision', data: ['title' => 'Benoeming rekenkamerlid', 'text' => 'x', 'decisionType' => 'appointment', 'outcome' => 'adopted', 'meeting' => $this->meeting]);
		$this->world->put(schema: 'voting-round', data: ['meeting' => $this->meeting, 'votesFor' => 27, 'votesAgainst' => 8, 'votesAbstain' => 0, 'relations' => [['id' => $third]]]);

		$result = $this->decisionList()->render(meetingId: $this->meeting, minutes: $this->world->objects[$this->minutes]['data']);

		$this->assertSame('Besluitenlijst.pdf', $result['name']);
		$html = $this->written['Besluitenlijst.pdf'];
		foreach (['Omgevingsvisie 2040 vastgesteld', 'Motie groen dak verworpen', 'Benoeming rekenkamerlid', 'Adopted', 'Rejected', '27 for, 8 against, 0 abstained', 'Voorzitter Jansen, Griffier De Vries', '2026-04-02'] as $expected) {
			$this->assertStringContainsString($expected, $html);
		}
	}//end testTheDecisionListFollowsTheMinutes()

	/**
	 * Without filinq the decision list is saved as HTML, with a note.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return void
	 */
	public function testWithoutFilinqTheDecisionListIsSavedAsHtmlWithANote(): void {
		$this->councilMeeting();
		$this->filinq = false;

		$result = $this->decisionList()->render(meetingId: $this->meeting, minutes: $this->world->objects[$this->minutes]['data']);

		$this->assertSame('Besluitenlijst.html', $result['name']);
		$this->assertStringStartsWith('<h1>Decision list</h1>', $this->written['Besluitenlijst.html']);
		$this->assertStringContainsString('filinq is not installed', $result['note']);
	}//end testWithoutFilinqTheDecisionListIsSavedAsHtmlWithANote()

	/**
	 * Sending before the minutes are approved is refused.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testSendingBeforeApprovalIsRefused(): void {
		$this->councilMeeting(lifecycle: 'review');
		$this->jobList->expects($this->never())->method('add');

		try {
			$this->exchange()->requestSend(meetingId: $this->meeting, userId: 'griffier');
			$this->fail('The meeting file was sent before approval');
		} catch (CaseSystemException $e) {
			$this->assertSame('Approve the minutes before sending the meeting file', $e->getMessage());
			$this->assertSame(422, $e->getStatus());
		}
	}//end testSendingBeforeApprovalIsRefused()

	/**
	 * With approved minutes the send is queued as one job.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testApprovedMinutesQueueTheSend(): void {
		$this->councilMeeting();
		$this->jobList->expects($this->once())->method('add')->with(SendMeetingFileJob::class, ['meeting' => $this->meeting, 'uid' => 'griffier']);

		$this->assertSame(['queued' => true], $this->exchange()->requestSend(meetingId: $this->meeting, userId: 'griffier'));
	}//end testApprovedMinutesQueueTheSend()

	/**
	 * The item's decision reaches its case, one meeting case gets the whole file, every line sent.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testTheGriffierSendsTheFileAfterTheCouncilMeeting(): void {
		$this->councilMeeting();

		$records = $this->exchange()->send(meetingId: $this->meeting, userId: 'griffier');

		$byCase = [];
		foreach ($this->world->callsOf('add-document') as $body) {
			$byCase[$body['case']][] = $body['name'];
		}

		$this->assertSame(['Decision Omgevingsvisie 2040 vastgesteld.pdf'], $byCase[self::CASE_ITEM]);
		$this->assertSame(
			['Agenda.pdf', 'Raadsvoorstel omgevingsvisie.pdf', 'Bijlage mededelingen.pdf', 'Decision Omgevingsvisie 2040 vastgesteld.pdf', 'Besluitenlijst.pdf', 'Minutes.pdf', 'Proof package.json'],
			$byCase[self::CASE_MEETING]
		);
		$this->assertCount(1, $this->world->callsOf('create-case'));
		$this->assertCount(2, $records);
		foreach ($records as $record) {
			$this->assertSame(['sent'], array_values(array_unique(array_column($record['lines'], 'status'))));
		}

		$this->assertSame('Z-2026-00500', $records[1]['targetLabel']);
		$this->assertSame([], $this->world->invalid);
	}//end testTheGriffierSendsTheFileAfterTheCouncilMeeting()

	/**
	 * A document under a confidentiality restriction is sent marked, with its ground.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testAConfidentialDocumentIsSentMarked(): void {
		$this->councilMeeting();
		$ground   = $this->world->put(schema: 'confidentiality-ground', data: ['name' => 'Geheimhouding raad', 'citation' => 'Gemeentewet artikel 25', 'category' => 'statutory', 'requiresRatification' => true, 'active' => true]);
		$document = $this->world->put(schema: 'digital-document', data: ['name' => 'Bijlage mededelingen.pdf', 'documentType' => 'bijlage', 'fileId' => 502]);
		$this->world->put(schema: 'confidentiality-restriction', data: ['scope' => 'document', 'targetDocument' => $document, 'ground' => $ground, 'lifecycle' => 'imposed']);

		$records = $this->exchange()->send(meetingId: $this->meeting, userId: 'griffier');

		foreach ($this->world->callsOf('add-document') as $body) {
			$expected = ($body['name'] === 'Bijlage mededelingen.pdf');
			$this->assertSame($expected, $body['confidential'], $body['name']);
			$this->assertSame(($expected === true ? 'Gemeentewet artikel 25' : ''), $body['ground'], $body['name']);
		}

		$line = array_values(array_filter($records[1]['lines'], static fn (array $line): bool => $line['name'] === 'Bijlage mededelingen.pdf'))[0];
		$this->assertTrue($line['confidential']);
		$this->assertSame('Gemeentewet artikel 25', $line['ground']);
		$this->assertSame([], $this->world->invalid);
	}//end testAConfidentialDocumentIsSentMarked()

	/**
	 * Send again sends only the failed line, to the case created the first time.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return void
	 */
	public function testSendAgainSendsOnlyTheFailedLine(): void {
		$this->councilMeeting();
		$accept = $this->world->answers['add-document'];
		$this->world->answers['add-document'] = static function (array $body) use ($accept): array {
			if ($body['name'] === 'Bijlage mededelingen.pdf') {
				return ['_status' => 400, 'message' => 'The case system refused the document: informatieobjecttype not configured'];
			}

			return $accept($body);
		};

		$records = $this->exchange()->send(meetingId: $this->meeting, userId: 'griffier');
		$failed  = array_values(array_filter($records[1]['lines'], static fn (array $line): bool => $line['status'] === 'failed'));
		$this->assertCount(1, $failed);
		$this->assertSame('The case system refused the document: informatieobjecttype not configured', $failed[0]['error']);

		$this->jobList->expects($this->once())->method('add')->with(SendMeetingFileJob::class, ['record' => $records[1]['id'], 'uid' => 'griffier']);
		$this->exchange()->requestResend(recordId: $records[1]['id'], userId: 'griffier');

		$this->world->answers['add-document'] = $accept;
		$this->world->calls = [];
		$record = $this->exchange()->resend(recordId: $records[1]['id']);

		$this->assertSame(['Bijlage mededelingen.pdf'], array_column($this->world->callsOf('add-document'), 'name'));
		$this->assertSame([self::CASE_MEETING], array_column($this->world->callsOf('add-document'), 'case'));
		$this->assertSame([], $this->world->callsOf('create-case'));
		$this->assertSame(['sent'], array_values(array_unique(array_column($record['lines'], 'status'))));
		$this->assertArrayNotHasKey('error', $record['lines'][2]);
		$this->assertSame([], $this->world->invalid);
	}//end testSendAgainSendsOnlyTheFailedLine()

	/**
	 * Without a linked source every case action answers 409.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return void
	 */
	public function testWithoutACaseSystemTheSendAnswers409(): void {
		$this->councilMeeting();
		foreach ($this->world->all('app_connection') as $id => $row) {
			unset($this->world->objects[$id]);
		}

		$this->assertFalse($this->client()->isConnected());
		try {
			$this->exchange()->requestSend(meetingId: $this->meeting, userId: 'griffier');
			$this->fail('A send was queued without a case system');
		} catch (CaseSystemException $e) {
			$this->assertSame('No case system is connected', $e->getMessage());
			$this->assertSame(409, $e->getStatus());
		}
	}//end testWithoutACaseSystemTheSendAnswers409()
}//end class
