<?php

/**
 * A clerk asks for a summary or a comparison of a paper: the request is
 * checked, a TaskProcessing task is scheduled under the custom id the listener
 * answers to, and a `requested` summary is saved; refusals schedule nothing
 * (agenda-ai-paper-summaries tasks 2 and 3).
 *
 * Built over the real PaperSummaryAccess and PaperText, the real OCP Task, and
 * doubles of OCP's TaskProcessing manager and OpenRegister's object service.
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
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\PaperSummaryController;
use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\Decidiq\Listener\PaperSummaryTaskListener;
use OCA\Decidiq\Service\PaperSummaryAccess;
use OCA\Decidiq\Service\PaperSummaryService;
use OCA\Decidiq\Service\PaperText;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Service\PaperSummaryService
 * @covers \OCA\Decidiq\Controller\PaperSummaryController
 * @uses   \OCA\Decidiq\Service\PaperSummaryAccess
 * @uses   \OCA\Decidiq\Service\PaperText
 * @uses   \OCA\Decidiq\Exception\PaperSummaryRefusedException
 */
class PaperSummaryServiceTest extends TestCase {

	private const ITEM = 'begroting-2026-bespreking';

	private const PAPER = 900412;

	private const OTHER_PAPER = 910001;

	/**
	 * Tasks handed to scheduleTask(), in order.
	 *
	 * @var array<int, Task>
	 */
	public array $scheduled = [];

	/**
	 * Tasks handed to runTask(), in order.
	 *
	 * @var array<int, Task>
	 */
	public array $ran = [];

	/**
	 * What saveObject() was asked to store: [object, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	public array $saved = [];

	/**
	 * Register rows: the griffier is a council member; a restriction imposed by
	 * the college sits on the item when `$restricted` is set.
	 *
	 * @param string|null $restricted The restriction's state, or null for none
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rows per schema.
	 */
	private function rows(?string $restricted=null): array {
		$rows = [
			'confidentiality-restriction' => [],
			'confidentiality-ground' => [['id' => 'g1', 'name' => 'Economische of financiële belangen van de gemeente']],
			'governance-body' => [['id' => 'college', 'name' => 'College van B en W']],
			'person' => [['id' => 'p-griffier', 'nextcloudUserId' => 'griffier']],
			'membership' => [['id' => 'm1', 'person' => 'p-griffier', 'governanceBody' => 'raad']],
			'digital-document' => [],
		];
		if ($restricted !== null) {
			$rows['confidentiality-restriction'][] = ['id' => 'r1', 'scope' => 'item', 'targetAgendaItem' => self::ITEM, 'ground' => 'g1', 'imposedByBody' => 'college', 'lifecycle' => $restricted];
		}

		return $rows;
	}//end rows()

	/**
	 * A Nextcloud file node.
	 *
	 * @param int    $id   The file id
	 * @param string $name The file name
	 *
	 * @return File The node.
	 */
	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);

		return $file;
	}//end file()

	/**
	 * The service for a signed-in user, over the item's two papers.
	 *
	 * @param string                    $uid       The user
	 * @param array<int, string>        $groups    The user's groups
	 * @param array<string, mixed>      $rows      Register rows per schema
	 * @param array<int, string>        $taskTypes The available task type ids
	 * @param array<int, string>        $texts     The chunk texts of every paper
	 * @param \Throwable|null           $schedule  What scheduleTask() throws, if anything
	 *
	 * @return PaperSummaryService The service.
	 */
	private function service(
		string $uid='griffier',
		array $groups=['decidiq-secretariat'],
		?array $rows=null,
		array $taskTypes=[TextToTextSummary::ID, TextToText::ID],
		array $texts=['De kadernota gaat uit van een sluitende meerjarenraming.'],
		?\Throwable $schedule=null,
	): PaperSummaryService {
		$rows = ($rows ?? $this->rows());
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $group): bool => in_array($group, $groups, true));

		$objects = $this->getMockBuilder(ObjectServiceInterface::class)->getMock();
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config=[]): array => ($rows[$config['filters']['schema'] ?? ''] ?? [])
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				$this->saved[] = [$object, $uuid];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(
			fn (mixed $object): array => ($object === self::ITEM ? [$this->file(self::PAPER, 'Raadsvoorstel kadernota 2026.pdf'), $this->file(self::OTHER_PAPER, 'Kadernota 2026.docx')] : [])
		);
		$chunks = [];
		foreach ($texts as $index => $text) {
			$chunk = new Chunk();
			$chunk->setChunkIndex($index);
			$chunk->setTextContent($text);
			$chunks[] = $chunk;
		}

		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('findBySource')->willReturn($chunks);
		$services = [
			'OCA\OpenRegister\Service\FileService' => $fileService,
			'OCA\OpenRegister\Service\TextExtractionService' => $this->createMock(TextExtractionService::class),
			'OCA\OpenRegister\Db\ChunkMapper' => $chunkMapper,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn($taskTypes);
		$manager->method('hasProviders')->willReturn($taskTypes !== []);
		$manager->method('scheduleTask')->willReturnCallback(
			function (Task $task) use ($schedule): void {
				if ($schedule !== null) {
					throw $schedule;
				}

				$task->setId(77);
				$this->scheduled[] = $task;
			}
		);
		$manager->method('runTask')->willReturnCallback(
			function (Task $task): Task {
				$this->ran[] = $task;
				$task->setOutput(['output' => 'Deel ' . count($this->ran) . ' samengevat.']);
				return $task;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new PaperSummaryService(
			access: new PaperSummaryAccess(userSession: $session, groupManager: $groupManager, objectService: $objects),
			paperText: new PaperText(container: $container, logger: $logger),
			objectService: $objects,
			taskManager: $manager,
			logger: $logger
		);
	}//end service()

	/**
	 * Assert the request is refused with this status and nothing is scheduled or saved.
	 *
	 * @param int      $status The expected HTTP status
	 * @param callable $call   The request
	 *
	 * @return PaperSummaryRefusedException The refusal.
	 */
	private function assertRefused(int $status, callable $call): PaperSummaryRefusedException {
		try {
			$call();
		} catch (PaperSummaryRefusedException $e) {
			$this->assertSame($status, $e->getStatus(), $e->getMessage());
			$this->assertSame([], $this->scheduled, 'a refused request schedules no task');
			$this->assertSame([], $this->saved, 'a refused request saves no summary');
			return $e;
		}

		$this->fail('The request should have been refused with ' . $status . '.');
	}//end assertRefused()

	/**
	 * The griffier asks for a summary: a TextToTextSummary task is scheduled
	 * under the listener's custom id and a `requested` summary is saved.
	 *
	 * @return void
	 */
	public function testTheGriffierAsksForASummary(): void {
		$summary = $this->service()->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary');

		$this->assertCount(1, $this->scheduled);
		$task = $this->scheduled[0];
		$this->assertSame(TextToTextSummary::ID, $task->getTaskTypeId());
		$this->assertSame('decidiq', $task->getAppId());
		$this->assertSame('griffier', $task->getUserId());
		$this->assertStringContainsString('sluitende meerjarenraming', $task->getInput()['input']);

		$this->assertCount(1, $this->saved);
		[$object, $uuid] = $this->saved[0];
		$this->assertSame(PaperSummaryTaskListener::CUSTOM_ID_PREFIX . $uuid, $task->getCustomId(), 'the listener finds the summary by this id');
		$this->assertSame('requested', $object['status']);
		$this->assertSame(self::ITEM, $object['agendaItem']);
		$this->assertSame(self::PAPER, $object['paperFileId']);
		$this->assertSame('Raadsvoorstel kadernota 2026.pdf', $object['paperTitle']);
		$this->assertSame('summary', $object['kind']);
		$this->assertSame(77, $object['taskId']);
		$this->assertFalse($object['chunked']);
		$this->assertSame($uuid, $summary['id']);
		$this->assertSame('requested', $summary['status']);
	}//end testTheGriffierAsksForASummary()

	/**
	 * A comparison of two papers runs a TextToText task naming both papers.
	 *
	 * @return void
	 */
	public function testTwoPapersAreCompared(): void {
		$this->service()->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'comparison', comparedFileId: self::OTHER_PAPER);

		$this->assertCount(1, $this->scheduled);
		$this->assertSame(TextToText::ID, $this->scheduled[0]->getTaskTypeId());
		$input = $this->scheduled[0]->getInput()['input'];
		$this->assertStringContainsString('Raadsvoorstel kadernota 2026.pdf', $input);
		$this->assertStringContainsString('Kadernota 2026.docx', $input);
		$object = $this->saved[0][0];
		$this->assertSame('comparison', $object['kind']);
		$this->assertSame(self::OTHER_PAPER, $object['comparedFileId']);
		$this->assertSame('Kadernota 2026.docx', $object['comparedTitle']);
	}//end testTwoPapersAreCompared()

	/**
	 * A file not attached to the item is refused with 422.
	 *
	 * @return void
	 */
	public function testAFileNotAttachedToTheItemIsRefused(): void {
		$this->assertRefused(422, fn () => $this->service()->request(agendaItemId: self::ITEM, fileId: 123, kind: 'summary'));
	}//end testAFileNotAttachedToTheItemIsRefused()

	/**
	 * A member without the secretariat group is refused with 403.
	 *
	 * @return void
	 */
	public function testAMemberIsRefused(): void {
		$this->assertRefused(403, fn () => $this->service(uid: 'pieter', groups: ['decidesk-members'])->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary'));
	}//end testAMemberIsRefused()

	/**
	 * Without a TaskProcessing provider the availability says so and a request answers 503.
	 *
	 * @return void
	 */
	public function testWithoutAProviderTheRequestAnswers503(): void {
		$service = $this->service(taskTypes: []);

		$this->assertSame(['available' => false, 'summary' => false, 'comparison' => false], $service->availability());
		$this->assertRefused(503, fn () => $service->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary'));
	}//end testWithoutAProviderTheRequestAnswers503()

	/**
	 * With only a summary provider, a comparison answers 503.
	 *
	 * @return void
	 */
	public function testAComparisonNeedsATextToTextProvider(): void {
		$service = $this->service(taskTypes: [TextToTextSummary::ID]);

		$this->assertSame(['available' => true, 'summary' => true, 'comparison' => false], $service->availability());
		$this->assertRefused(503, fn () => $service->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'comparison', comparedFileId: self::OTHER_PAPER));
	}//end testAComparisonNeedsATextToTextProvider()

	/**
	 * An unknown kind, or a comparison without a second paper, is refused with 422.
	 *
	 * @return void
	 */
	public function testABadKindOrAMissingSecondPaperIsRefused(): void {
		$this->assertRefused(422, fn () => $this->service()->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'poem'));
		$this->assertRefused(422, fn () => $this->service()->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'comparison'));
		$this->assertRefused(422, fn () => $this->service()->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'comparison', comparedFileId: self::PAPER));
	}//end testABadKindOrAMissingSecondPaperIsRefused()

	/**
	 * A clerk outside an active restriction's circle is refused naming it; once
	 * the restriction is dissolved the task is scheduled.
	 *
	 * @return void
	 */
	public function testAConfidentialPaperStaysInsideItsCircle(): void {
		$refusal = $this->assertRefused(403, fn () => $this->service(rows: $this->rows(restricted: 'imposed'))->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary'));
		$this->assertStringContainsString('College van B en W', $refusal->getMessage());

		$this->service(rows: $this->rows(restricted: 'dissolved'))->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary');
		$this->assertCount(1, $this->scheduled);
	}//end testAConfidentialPaperStaysInsideItsCircle()

	/**
	 * A comparison with a confidential second paper is refused too.
	 *
	 * @return void
	 */
	public function testTheSecondPaperOfAComparisonIsCheckedToo(): void {
		$rows = $this->rows();
		$rows['digital-document'] = [['id' => 'd2', 'fileId' => self::OTHER_PAPER]];
		$rows['confidentiality-restriction'][] = ['id' => 'r2', 'scope' => 'document', 'targetDocument' => 'd2', 'ground' => 'g1', 'imposedByBody' => 'college', 'lifecycle' => 'ratified'];

		$this->assertRefused(403, fn () => $this->service(rows: $rows)->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'comparison', comparedFileId: self::OTHER_PAPER));
	}//end testTheSecondPaperOfAComparisonIsCheckedToo()

	/**
	 * A paper longer than one task's input is summarised part by part, the
	 * scheduled task summarises the parts, and the summary records it.
	 *
	 * @return void
	 */
	public function testALongPaperIsSummarisedInParts(): void {
		$chunk = str_repeat('Begrotingstekst. ', (int)(PaperText::PART_LENGTH / 34));
		$this->service(texts: array_fill(0, 5, $chunk))->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary');

		$this->assertCount(3, $this->ran, 'five chunks of half a part make three parts');
		foreach ($this->ran as $part) {
			$this->assertSame(TextToTextSummary::ID, $part->getTaskTypeId());
		}

		$this->assertCount(1, $this->scheduled);
		$this->assertStringContainsString('Deel 1 samengevat.', $this->scheduled[0]->getInput()['input']);
		$this->assertStringContainsString('Deel 3 samengevat.', $this->scheduled[0]->getInput()['input']);
		$this->assertTrue($this->saved[0][0]['chunked']);
	}//end testALongPaperIsSummarisedInParts()

	/**
	 * When the provider will not take the task, the request answers 503 and no
	 * summary is left behind.
	 *
	 * @return void
	 */
	public function testAScheduleThatFailsLeavesNoSummary(): void {
		$this->assertRefused(503, fn () => $this->service(schedule: new \RuntimeException('no provider for this type'))->request(agendaItemId: self::ITEM, fileId: self::PAPER, kind: 'summary'));
	}//end testAScheduleThatFailsLeavesNoSummary()

	/**
	 * The controller hands the request to the service: 201 with the summary,
	 * the refusal's status otherwise, and the availability as it is.
	 *
	 * @return void
	 */
	public function testTheControllerAnswersWithTheServicesOutcome(): void {
		$params = ['fileId' => (string)self::PAPER, 'kind' => 'summary'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		$created = (new PaperSummaryController(request: $request, summaries: $this->service()))->create(id: self::ITEM);
		$this->assertInstanceOf(JSONResponse::class, $created);
		$this->assertSame(201, $created->getStatus());
		$this->assertSame('requested', $created->getData()['status']);
		$this->assertCount(1, $this->scheduled);

		$refused = (new PaperSummaryController(request: $request, summaries: $this->service(uid: 'pieter', groups: ['decidesk-members'])))->create(id: self::ITEM);
		$this->assertSame(403, $refused->getStatus());
		$this->assertArrayHasKey('message', $refused->getData());

		$availability = (new PaperSummaryController(request: $request, summaries: $this->service(taskTypes: [])))->availability();
		$this->assertSame(200, $availability->getStatus());
		$this->assertFalse($availability->getData()['available']);
	}//end testTheControllerAnswersWithTheServicesOutcome()

	/**
	 * The routes point at the controller's two methods.
	 *
	 * @return void
	 */
	public function testTheRoutesReachTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = [];
		foreach ($routes['routes'] as $route) {
			$byName[$route['name']] = $route;
		}

		$this->assertSame('/api/agenda-items/{id}/paper-summaries', $byName['paperSummary#create']['url'] ?? null);
		$this->assertSame('POST', $byName['paperSummary#create']['verb']);
		$this->assertSame('/api/paper-summaries/availability', $byName['paperSummary#availability']['url'] ?? null);
		$this->assertSame('GET', $byName['paperSummary#availability']['verb']);
		$this->assertTrue(method_exists(PaperSummaryController::class, 'create'));
		$this->assertTrue(method_exists(PaperSummaryController::class, 'availability'));
	}//end testTheRoutesReachTheController()
}//end class
