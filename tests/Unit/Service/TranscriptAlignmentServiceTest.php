<?php

/**
 * Unit tests for TranscriptAlignmentService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\TranscriptAlignmentService;
use OCA\Decidiq\Service\TranscriptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for parsing transcript segments and aligning them to agenda items.
 *
 * @covers \OCA\Decidiq\Service\TranscriptAlignmentService
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptAlignmentServiceTest extends TestCase {
	private TranscriptRepository&MockObject $repository;

	private TranscriptAlignmentService $service;

	protected function setUp(): void {
		$this->repository = $this->createMock(TranscriptRepository::class);
		$this->service    = new TranscriptAlignmentService($this->repository);
	}

	public function testBlankInputHasNoSegments(): void {
		$this->assertSame([], $this->service->parseSegments("  \n "));
	}

	public function testPlainTextBecomesOneSegment(): void {
		$this->assertSame(
			[['startTime' => 0.0, 'endTime' => 0.0, 'speakerLabel' => 'Speaker 1', 'text' => 'Hello all']],
			$this->service->parseSegments("  Hello all \n")
		);
	}

	public function testAJsonObjectIsTreatedAsPlainText(): void {
		$segments = $this->service->parseSegments('{"text":"x"}');

		$this->assertSame('{"text":"x"}', $segments[0]['text']);
	}

	public function testStructuredSegmentsGetStableAnonymousSpeakerLabels(): void {
		$raw = json_encode(
			[
				['speaker' => 'Alice', 'start' => 1, 'end' => 2.5, 'text' => 'Hi'],
				'not a segment',
				['speakerLabel' => 'Bob', 'startTime' => '3', 'endTime' => 4, 'text' => 'Yo'],
				['speaker' => 'Alice', 'startTime' => 5],
				['text' => 'anon'],
			]
		);

		$this->assertSame(
			[
				['startTime' => 1.0, 'endTime' => 2.5, 'speakerLabel' => 'Speaker 1', 'text' => 'Hi'],
				['startTime' => 3.0, 'endTime' => 4.0, 'speakerLabel' => 'Speaker 2', 'text' => 'Yo'],
				['startTime' => 5.0, 'endTime' => 0.0, 'speakerLabel' => 'Speaker 1', 'text' => ''],
				['startTime' => 0.0, 'endTime' => 0.0, 'speakerLabel' => 'Speaker 3', 'text' => 'anon'],
			],
			$this->service->parseSegments($raw)
		);
	}

	public function testAlignSegmentsPicksTheFirstMatchingWindow(): void {
		$timeline = [
			['agendaItem' => 'a', 'start' => 0.0, 'end' => 10.0],
			['agendaItem' => 'b', 'start' => 10.0, 'end' => 0.0],
		];
		$segments = [
			['startTime' => 0.0, 'agendaItem' => 'stale'],
			['startTime' => 9.9],
			['startTime' => 10.0],
			['startTime' => 500.0],
			[],
		];

		$this->assertSame(
			[
				['startTime' => 0.0, 'agendaItem' => 'a'],
				['startTime' => 9.9, 'agendaItem' => 'a'],
				['startTime' => 10.0, 'agendaItem' => 'b'],
				['startTime' => 500.0, 'agendaItem' => 'b'],
				['agendaItem' => 'a'],
			],
			$this->service->alignSegments($segments, $timeline)
		);
	}

	public function testAlignSegmentsDropsAStaleItemWhenNoWindowMatches(): void {
		$this->assertSame(
			[['startTime' => 5.0]],
			$this->service->alignSegments(
				[['startTime' => 5.0, 'agendaItem' => 'old']],
				[['agendaItem' => 'a', 'start' => 6.0, 'end' => 8.0]]
			)
		);
	}

	public function testBuildTimelineIsRelativeToTheFirstItem(): void {
		$this->repository->expects($this->once())->method('fetchAgendaItems')->with('m-1')->willReturn(
			[
				['id' => 'b', 'actualStart' => '2026-03-01T10:05:00+00:00', 'actualDuration' => 120],
				['uuid' => 'a', 'startTime' => '2026-03-01T10:00:00+00:00', 'actualDuration' => 300],
				['id' => 'c', 'startedAt' => '2026-03-01T11:00:00+00:00'],
				['id' => 'skip-empty', 'actualStart' => ''],
				['id' => 'skip-missing'],
				['id' => 'skip-bad', 'actualStart' => 'not a date'],
			]
		);

		$this->assertSame(
			[
				['agendaItem' => 'b', 'start' => 300.0, 'end' => 420.0],
				['agendaItem' => 'a', 'start' => 0.0, 'end' => 300.0],
				['agendaItem' => 'c', 'start' => 3600.0, 'end' => 0.0],
			],
			$this->service->buildTimeline('m-1')
		);
	}

	public function testBuildTimelineWithoutStartedItemsIsEmpty(): void {
		$this->repository->method('fetchAgendaItems')->willReturn([['id' => 'a']]);

		$this->assertSame([], $this->service->buildTimeline('m-1'));
	}
}
