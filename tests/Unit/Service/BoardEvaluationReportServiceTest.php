<?php

/**
 * Unit tests for BoardEvaluationReportService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\BoardEvaluationReportService;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Report rendering, the PDF/markdown fallback and the Files write path.
 *
 * @covers \OCA\Decidiq\Service\BoardEvaluationReportService
 */
class BoardEvaluationReportServiceTest extends TestCase {

	/**
	 * Mock PDF renderer.
	 *
	 * @var FilinqPdf&MockObject
	 */
	private FilinqPdf&MockObject $filinqPdf;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock file service.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock target folder.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $folder;

	/**
	 * Folder paths passed to createFolder(), in order.
	 *
	 * @var list<string>
	 */
	private array $createdFolders = [];

	/**
	 * Files written through newFile(), keyed by name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->filinqPdf = $this->createMock(FilinqPdf::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->folder = $this->createMock(Folder::class);

		$this->createdFolders = [];
		$this->written = [];
		$this->fileService->method('createFolder')->willReturnCallback(
			function (string $path): Folder {
				$this->createdFolders[] = $path;
				return $this->folder;
			}
		);
		$this->folder->method('get')->willThrowException(new NotFoundException('absent'));
		$this->folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null): File {
				$this->written[$name] = (string)$content;
				return $this->createMock(File::class);
			}
		);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return BoardEvaluationReportService
	 */
	private function service(): BoardEvaluationReportService {
		return new BoardEvaluationReportService(
			filinqPdf: $this->filinqPdf,
			logger: $this->logger,
			fileService: $this->fileService,
			objectService: $this->objectService,
		);

	}//end service()

	/**
	 * Wrap a payload in an ObjectEntity double.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Seed find() with an evaluation and optional governance body.
	 *
	 * @param array<string, mixed> $evaluation The evaluation payload
	 * @param array<string, mixed>|null $body The body payload, null for not found
	 *
	 * @return void
	 */
	private function seed(array $evaluation, ?array $body = null): void {
		$this->objectService->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($evaluation, $body): ?ObjectEntity {
				if ($schema === 'board-evaluation') {
					return ($id === 'eval-1') ? $this->entity($evaluation) : null;
				}

				if ($schema === 'governance-body' && $body !== null && $id === 'body-1') {
					return $this->entity($body);
				}

				return null;
			}
		);

	}//end seed()

	/**
	 * A closed evaluation with a materialised score summary.
	 *
	 * @param array<string, mixed> $summary Summary overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function evaluation(array $summary = []): array {
		return [
			'id' => 'eval-1',
			'cycleLabel' => '2026',
			'governanceBody' => 'body-1',
			'scoreSummary' => json_encode(
				array_merge(
					[
						'respondentCount' => 5,
						'invitedMemberCount' => 7,
						'overallScore' => 3.8,
						'suppressed' => false,
						'dimensionScores' => ['Strategy' => 4.1, 'Culture' => 3.2],
						'themes' => [
							'Strategy' => [['word' => 'focus'], ['word' => 'vision']],
							'Culture' => [],
						],
					],
					$summary
				)
			),
		];

	}//end evaluation()

	/**
	 * An unknown evaluation id is refused before anything is rendered.
	 *
	 * @return void
	 */
	public function testUnknownEvaluationThrows(): void {
		$this->seed(self::evaluation());
		$this->filinqPdf->expects($this->never())->method('fromHtml');

		$this->expectException(MissingObjectException::class);
		$this->expectExceptionMessage('BoardEvaluation eval-missing not found.');

		$this->service()->generate('eval-missing');

	}//end testUnknownEvaluationThrows()

	/**
	 * With Docudesk available the report is stored as a PDF in the body's evaluation folder.
	 *
	 * @return void
	 */
	public function testPdfIsWrittenWhenDocudeskRenders(): void {
		$this->seed(self::evaluation(), ['name' => 'Raad van Toezicht']);

		$capturedHtml = null;
		$this->filinqPdf->expects($this->once())
			->method('fromHtml')
			->willReturnCallback(
				function (string $html, string $title, string $context) use (&$capturedHtml): string {
					$capturedHtml = $html;
					$this->assertSame('Board evaluation 2026', $title);
					$this->assertSame('the board evaluation report', $context);
					return '%PDF-bytes';
				}
			);

		$result = $this->service()->generate('eval-1');

		$this->assertSame(
			[
				'path' => 'Decidesk/Raad van Toezicht/Evaluations/2026/Board evaluation 2026.pdf',
				'format' => 'pdf',
				'docudesk' => true,
			],
			$result
		);
		$this->assertSame(['Board evaluation 2026.pdf' => '%PDF-bytes'], $this->written);
		$this->assertSame(
			[
				'Decidesk',
				'Decidesk/Raad van Toezicht',
				'Decidesk/Raad van Toezicht/Evaluations',
				'Decidesk/Raad van Toezicht/Evaluations/2026',
				'Decidesk/Raad van Toezicht/Evaluations/2026',
			],
			$this->createdFolders
		);

		$this->assertIsString($capturedHtml);
		$this->assertStringStartsWith('<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body>', $capturedHtml);
		$this->assertStringContainsString('<h1>Board evaluation report — Raad van Toezicht (2026)</h1>', $capturedHtml);
		$this->assertStringContainsString('<h2>Overall board-effectiveness score</h2>', $capturedHtml);
		$this->assertStringContainsString('<p class="list-item">**Strategy**: 4.1</p>', $capturedHtml);
		$this->assertStringContainsString('<p>Respondents: 5 of 7 invited.</p>', $capturedHtml);
		$this->assertStringEndsWith('</body></html>', $capturedHtml);

	}//end testPdfIsWrittenWhenDocudeskRenders()

	/**
	 * Without Docudesk the markdown is stored instead, with a note saying so.
	 *
	 * @return void
	 */
	public function testMarkdownFallbackRendersFullBreakdown(): void {
		$this->seed(self::evaluation(), ['title' => 'Board']);
		$this->filinqPdf->method('fromHtml')->willReturn(null);

		$result = $this->service()->generate('eval-1');

		$this->assertSame('Decidesk/Board/Evaluations/2026/Board evaluation 2026.md', $result['path']);
		$this->assertSame('markdown', $result['format']);
		$this->assertFalse($result['docudesk']);
		$this->assertSame(
			'Docudesk is not available on this instance — a markdown document was produced instead of a PDF.',
			$result['note']
		);

		$this->assertSame(
			implode(
				"\n",
				[
					'# Board evaluation report — Board (2026)',
					'',
					'Respondents: 5 of 7 invited.',
					'',
					'## Overall board-effectiveness score',
					'3.8',
					'',
					'## Per-dimension scores',
					'- **Strategy**: 4.1',
					'- **Culture**: 3.2',
					'',
					'## Recurring free-text themes',
					'- **Strategy**: focus, vision',
					'- **Culture**: _none_',
				]
			),
			$this->written['Board evaluation 2026.md']
		);

	}//end testMarkdownFallbackRendersFullBreakdown()

	/**
	 * A suppressed summary stops after the overall score; a null score says it is unavailable.
	 *
	 * @return void
	 */
	public function testSuppressedSummaryOmitsBreakdown(): void {
		$this->seed(self::evaluation(['suppressed' => true, 'overallScore' => null]), ['name' => 'Board']);
		$this->filinqPdf->method('fromHtml')->willReturn(null);

		$this->service()->generate('eval-1');

		$markdown = $this->written['Board evaluation 2026.md'];
		$this->assertStringContainsString("## Overall board-effectiveness score\n_Not available (no Likert answers recorded)._", $markdown);
		$this->assertStringEndsWith(
			'_Per-dimension and free-text breakdowns are suppressed: respondent count is below the '
			. 'minimum threshold, protecting anonymity on a small body._',
			$markdown
		);
		$this->assertStringNotContainsString('Per-dimension scores', $markdown);

	}//end testSuppressedSummaryOmitsBreakdown()

	/**
	 * Without a summary the counts come from the evaluation and the body falls back to a generic name.
	 *
	 * @return void
	 */
	public function testMissingSummaryAndBodyUseFallbacks(): void {
		$this->seed(
			[
				'id' => 'eval-1',
				'cycleLabel' => 'Q1/2026',
				'scoreSummary' => 'not-json',
				'respondedCount' => 2,
				'invitedMemberCount' => 9,
			]
		);
		$this->filinqPdf->method('fromHtml')->willReturn(null);

		$result = $this->service()->generate('eval-1');

		$this->assertSame('Decidesk/Governance body/Evaluations/Q1-2026/Board evaluation Q1-2026.md', $result['path']);
		$this->assertSame(
			"# Board evaluation report — Governance body (Q1/2026)\n\nRespondents: 2 of 9 invited.\n\n"
			. '_No score summary has been materialised yet — close the cycle to generate scores._',
			$this->written['Board evaluation Q1-2026.md']
		);

	}//end testMissingSummaryAndBodyUseFallbacks()

	/**
	 * The body id is read from the relations block and an array reference is unwrapped.
	 *
	 * @return void
	 */
	public function testBodyResolvedFromRelationsArrayReference(): void {
		$evaluation = self::evaluation();
		unset($evaluation['governanceBody']);
		$evaluation['relations'] = ['governanceBody' => [['id' => 'body-1']]];
		$this->seed($evaluation, ['name' => 'Bestuur']);
		$this->filinqPdf->method('fromHtml')->willReturn(null);

		$result = $this->service()->generate('eval-1');

		$this->assertSame('Decidesk/Bestuur/Evaluations/2026/Board evaluation 2026.md', $result['path']);

	}//end testBodyResolvedFromRelationsArrayReference()

	/**
	 * A failing body lookup is swallowed and the generic body name used.
	 *
	 * @return void
	 */
	public function testBodyLookupFailureFallsBackToGenericName(): void {
		$evaluation = self::evaluation();
		$this->objectService->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($evaluation): ?ObjectEntity {
				if ($schema === 'governance-body') {
					throw new RuntimeException('register down');
				}

				return $this->entity($evaluation);
			}
		);
		$this->filinqPdf->method('fromHtml')->willReturn(null);

		$result = $this->service()->generate('eval-1');

		$this->assertSame('Decidesk/Governance body/Evaluations/2026/Board evaluation 2026.md', $result['path']);

	}//end testBodyLookupFailureFallsBackToGenericName()

	/**
	 * An existing report file is overwritten in place rather than duplicated.
	 *
	 * @return void
	 */
	public function testExistingFileIsOverwritten(): void {
		$this->seed(self::evaluation(), ['name' => 'Board']);
		$this->filinqPdf->method('fromHtml')->willReturn('%PDF');

		$existing = $this->createMock(File::class);
		$existing->expects($this->once())->method('putContent')->with('%PDF');
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('Board evaluation 2026.pdf')->willReturn($existing);
		$folder->expects($this->never())->method('newFile');

		$fileService = $this->createMock(FileService::class);
		$fileService->method('createFolder')->willReturn($folder);
		$this->fileService = $fileService;

		$result = $this->service()->generate('eval-1');

		$this->assertSame('pdf', $result['format']);

	}//end testExistingFileIsOverwritten()

	/**
	 * When Files is unavailable both writes fail, are logged, and the call fails with 503.
	 *
	 * @return void
	 */
	public function testUnavailableFilesBackendThrows503(): void {
		$this->seed(self::evaluation(), ['name' => 'Board']);
		$this->filinqPdf->method('fromHtml')->willReturn('%PDF');

		$fileService = $this->createMock(FileService::class);
		$fileService->method('createFolder')->willThrowException(new RuntimeException('no storage'));
		$this->fileService = $fileService;

		$logged = [];
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->willReturnCallback(
				function (string $message, array $context) use (&$logged): void {
					$this->assertSame('Decidiq: board evaluation report write failed', $message);
					$logged[] = $context;
				}
			);

		try {
			$this->service()->generate('eval-1');
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame(503, $e->getCode());
			$this->assertSame('The evaluation report could not be stored: the Files backend is unavailable.', $e->getMessage());
		}

		$this->assertSame(
			[
				['fileName' => 'Board evaluation 2026.pdf', 'error' => 'no storage'],
				['fileName' => 'Board evaluation 2026.md', 'error' => 'no storage'],
			],
			$logged
		);

	}//end testUnavailableFilesBackendThrows503()

	/**
	 * A cycle label made only of separators sanitises to the default segment name.
	 *
	 * @return void
	 */
	public function testUnsafeCycleLabelSanitisesToDefault(): void {
		$evaluation = self::evaluation();
		$evaluation['cycleLabel'] = '../';
		$this->seed($evaluation, ['name' => 'Board']);
		$this->filinqPdf->method('fromHtml')->willReturn(null);

		$result = $this->service()->generate('eval-1');

		$this->assertSame('Decidesk/Board/Evaluations/evaluation/Board evaluation ..-.md', $result['path']);

	}//end testUnsafeCycleLabelSanitisesToDefault()
}//end class
