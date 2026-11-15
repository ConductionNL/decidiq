<?php

/**
 * Test Suite for ActionItemExtractionService
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActionItemExtractionService;
use OCA\Decidiq\Service\ActionItemWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ActionItemExtractionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
 */
class ActionItemExtractionServiceTest extends TestCase {
	private ActionItemExtractionService $service;
	private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
	private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ActionItemExtractionService($this->container, $this->logger);
	}

	/**
	 * Test that extractFromContent detects "Actie:" marker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
	 */
	public function testExtractFromContentDetectsActieMarker(): void {
		$content = <<<'CONTENT'
Agendapunt 1 - Financieel verslag
De financieel controller geeft toelichting op de cijfers.

Actie: Griffier verstuurt definitief rapport naar alle leden
AI: Voorzitter bereikt bestuursbesluit

Agendapunt 2 - Volgende vergadering
CONTENT;

		$candidates = $this->service->extractFromContent($content);

		$this->assertIsArray($candidates);
		$this->assertGreaterThanOrEqual(1, count($candidates));
		$this->assertStringContainsString('Griffier', $candidates[0]['title']);
	}

	/**
	 * Test that extractFromContent detects "wordt verzocht" phrase.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
	 */
	public function testExtractFromContentDetectsWordtVerzoechtPhrase(): void {
		$content = <<<'CONTENT'
De secretaris wordt verzocht om de uitnodiging uit te sturen vóór 15 mei.
De penningmeester zal de begroting finaliseren.
CONTENT;

		$candidates = $this->service->extractFromContent($content);

		$this->assertIsArray($candidates);
		$this->assertGreaterThanOrEqual(1, count($candidates));
		// Should have detected "wordt verzocht" line
		$found = false;
		foreach ($candidates as $candidate) {
			if (stripos($candidate['title'], 'secretaris') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found);
	}

	/**
	 * Test that extractFromContent returns empty for content with no markers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
	 */
	public function testExtractFromContentReturnsEmptyForUnmatchedContent(): void {
		$content = <<<'CONTENT'
Agendapunt 1 - Opening
Agendapunt 2 - Behandeling motie
Agendapunt 3 - Rondvraag
Agendapunt 4 - Sluiting
CONTENT;

		$candidates = $this->service->extractFromContent($content);

		$this->assertIsArray($candidates);
		$this->assertCount(0, $candidates);
	}

	/**
	 * Test that extractFromContent matches known participant name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.5
	 */
	public function testExtractFromContentMatchesKnownParticipantName(): void {
		$content = 'Actie: Jan de Vries bereikt bestuursbesluit op 1 mei';
		$knownParticipants = ['Jan de Vries', 'Marie Hendriks'];

		$candidates = $this->service->extractFromContent($content, $knownParticipants);

		$this->assertCount(1, $candidates);
		$this->assertEquals('Jan de Vries', $candidates[0]['suggestedAssignee']);
	}

	/**
	 * A long phrase line with an early full stop is cut at that sentence.
	 *
	 * @return void
	 */
	public function testLongPhraseLineIsTruncatedAtTheFirstSentence(): void {
		$line = 'De secretaris wordt verzocht de stukken rond te sturen. ' . str_repeat('Daarna volgt nog een lange toelichting zonder einde ', 4);

		$candidates = $this->service->extractFromContent($line);

		$this->assertCount(1, $candidates);
		$this->assertSame('De secretaris wordt verzocht de stukken rond te sturen', $candidates[0]['title']);
		$this->assertNull($candidates[0]['suggestedAssignee']);
	}

	/**
	 * A long phrase line without an early full stop is cut at 100 chars plus an ellipsis.
	 *
	 * @return void
	 */
	public function testLongPhraseLineWithoutEarlySentenceIsCutAtHundredChars(): void {
		$line = str_repeat('x', 120) . ' moet nog gebeuren door Piet ' . str_repeat('y', 40);

		$candidates = $this->service->extractFromContent($line, ['Piet']);

		$this->assertCount(1, $candidates);
		$this->assertSame(str_repeat('x', 100) . '...', $candidates[0]['title']);
		$this->assertSame('Piet', $candidates[0]['suggestedAssignee']);
	}

	/**
	 * Confirmed candidates are written through the ActionItemWriter with the
	 * relation, assignee and an accepted ISO due date; title-less ones are skipped.
	 *
	 * @return void
	 */
	public function testSaveExtractedWritesEachConfirmedCandidate(): void {
		$writer = $this->createMock(ActionItemWriter::class);
		$writer->expects($this->exactly(2))->method('create')->willReturnCallback(
			function (array $item): array {
				static $calls = 0;
				$calls++;
				if ($calls === 1) {
					$this->assertSame(
						[
							'title' => 'Rapport versturen',
							'taskStatus' => 'open',
							'relations' => ['Minutes' => ['min-1']],
							'assignee' => 'alice',
							'dueDate' => '2026-05-15',
						],
						$item
					);
				} else {
					$this->assertSame(['title' => 'Begroting', 'taskStatus' => 'open', 'relations' => ['Minutes' => ['min-1']]], $item);
				}

				return ['id' => 'todo-' . $calls];
			}
		);
		$this->container->expects($this->once())->method('get')->with(ActionItemWriter::class)->willReturn($writer);
		$this->logger->expects($this->once())->method('info')->with('Saved 2 extracted action items for minutes min-1');

		$count = $this->service->saveExtracted(
			'min-1',
			[
				['title' => 'Rapport versturen', 'assignee' => 'alice', 'dueDate' => '2026-05-15'],
				['title' => ''],
				['assignee' => 'bob'],
				['title' => 'Begroting'],
			]
		);

		$this->assertSame(2, $count);
	}

	/**
	 * A relative-expression due date is rejected (logged) and left off the item.
	 *
	 * @return void
	 */
	public function testSaveExtractedRejectsNonIsoDueDate(): void {
		$writer = $this->createMock(ActionItemWriter::class);
		$writer->expects($this->once())->method('create')
			->with(['title' => 'Bellen', 'taskStatus' => 'open', 'relations' => ['Minutes' => ['m']]])
			->willReturn(['id' => 'x']);
		$this->container->method('get')->willReturn($writer);
		$this->logger->expects($this->once())->method('warning')
			->with('ActionItemExtractionService: rejected non-ISO-8601 dueDate', ['dueDate' => 'next Monday']);

		$this->assertSame(1, $this->service->saveExtracted('m', [['title' => 'Bellen', 'dueDate' => 'next Monday']]));
	}

	/**
	 * A writer returning null or throwing does not count, and is logged.
	 *
	 * @return void
	 */
	public function testSaveExtractedSkipsFailedWrites(): void {
		$writer = $this->createMock(ActionItemWriter::class);
		$writer->method('create')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new \RuntimeException('caldav down')),
			['id' => 'ok']
		);
		$this->container->method('get')->willReturn($writer);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$count = $this->service->saveExtracted('m9', [['title' => 'a'], ['title' => 'b'], ['title' => 'c']]);

		$this->assertSame(1, $count);
		$this->assertSame(
			['Failed to save ActionItem VTODO for minutes m9', 'Failed to save ActionItem: caldav down'],
			$warnings
		);
	}

	/**
	 * When the writer cannot be resolved nothing is saved and the error is logged.
	 *
	 * @return void
	 */
	public function testSaveExtractedReturnsZeroWhenWriterUnavailable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no writer'));
		$this->logger->expects($this->once())->method('error')
			->with('ActionItemExtractionService::saveExtracted failed: no writer');

		$this->assertSame(0, $this->service->saveExtracted('m', [['title' => 'a']]));
	}
}
