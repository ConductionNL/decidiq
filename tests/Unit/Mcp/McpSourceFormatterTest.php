<?php

/**
 * Unit tests for McpSourceFormatter.
 *
 * The formatter shapes every MCP answer: the object normalisation, the deep
 * links a client follows, the capped source list and the error envelope.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Mcp;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use OCA\Decidiq\Mcp\McpSourceFormatter;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for McpSourceFormatter.
 *
 * @covers \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class McpSourceFormatterTest extends TestCase {

	/**
	 * The formatter under test.
	 *
	 * @var McpSourceFormatter
	 */
	private McpSourceFormatter $formatter;

	/**
	 * Set up the formatter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->formatter = new McpSourceFormatter();
	}

	/**
	 * An array is returned unchanged.
	 *
	 * @return void
	 */
	public function testToArrayReturnsAnArrayUnchanged(): void {
		$item = ['id' => 'm-1', 'title' => 'Raad'];

		$this->assertSame($item, $this->formatter->toArray(item: $item));
	}

	/**
	 * An object with getObject() is read through it, even when it is also
	 * JSON-serialisable.
	 *
	 * @return void
	 */
	public function testToArrayPrefersGetObject(): void {
		$item = new class implements JsonSerializable {
			public function getObject(): array {
				return ['from' => 'getObject'];
			}

			public function jsonSerialize(): array {
				return ['from' => 'jsonSerialize'];
			}
		};

		$this->assertSame(['from' => 'getObject'], $this->formatter->toArray(item: $item));
	}

	/**
	 * An object without getObject() falls back to jsonSerialize().
	 *
	 * @return void
	 */
	public function testToArrayFallsBackToJsonSerialize(): void {
		$item = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['from' => 'jsonSerialize'];
			}
		};

		$this->assertSame(['from' => 'jsonSerialize'], $this->formatter->toArray(item: $item));
	}

	/**
	 * A plain object is cast to its public properties.
	 *
	 * @return void
	 */
	public function testToArrayCastsAPlainObject(): void {
		$item = new stdClass();
		$item->id = 'm-2';

		$this->assertSame(['id' => 'm-2'], $this->formatter->toArray(item: $item));
	}

	/**
	 * The UUID is read from uuid, then id, then the @self envelope.
	 *
	 * @return void
	 */
	public function testExtractUuidFollowsTheFieldPrecedence(): void {
		$this->assertSame('u', $this->formatter->extractUuid(item: ['uuid' => 'u', 'id' => 'i']));
		$this->assertSame('i', $this->formatter->extractUuid(item: ['id' => 'i', '@self' => ['uuid' => 's']]));
		$this->assertSame('s', $this->formatter->extractUuid(item: ['@self' => ['uuid' => 's', 'id' => 'si']]));
		$this->assertSame('si', $this->formatter->extractUuid(item: ['@self' => ['id' => 'si']]));
		$this->assertSame('42', $this->formatter->extractUuid(item: ['id' => 42]));
		$this->assertSame('', $this->formatter->extractUuid(item: ['title' => 'no id']));
	}

	/**
	 * Known types map to their route; an unknown type is pluralised.
	 *
	 * @return void
	 */
	public function testDeepLinkMapsKnownTypesAndPluralisesOthers(): void {
		$this->assertSame('/apps/decidiq/meetings/u', $this->formatter->deepLink(type: 'meeting', uuid: 'u'));
		$this->assertSame('/apps/decidiq/agenda-items/u', $this->formatter->deepLink(type: 'agendaItem', uuid: 'u'));
		$this->assertSame('/apps/decidiq/decisions/u', $this->formatter->deepLink(type: 'decision', uuid: 'u'));
		$this->assertSame('/apps/decidiq/action-items/u', $this->formatter->deepLink(type: 'actionItem', uuid: 'u'));
		$this->assertSame('/apps/decidiq/motions/u', $this->formatter->deepLink(type: 'motion', uuid: 'u'));
	}

	/**
	 * A dotted type keeps its full name but links by its last segment.
	 *
	 * @return void
	 */
	public function testSourceLinksADottedTypeByItsLastSegment(): void {
		$this->assertSame(
			[
				'type' => 'decidesk.agendaItem',
				'uuid' => 'a-1',
				'url' => '/apps/decidiq/agenda-items/a-1',
				'label' => 'Opening',
			],
			$this->formatter->source(type: 'decidesk.agendaItem', uuid: 'a-1', label: 'Opening')
		);
		$this->assertSame(
			'/apps/decidiq/decisions/d-1',
			$this->formatter->source(type: 'decision', uuid: 'd-1', label: 'x')['url']
		);
	}

	/**
	 * A meeting source is labelled with its title, or "Meeting" without one.
	 *
	 * @return void
	 */
	public function testMeetingSourceUsesTheTitleOrADefault(): void {
		$this->assertSame(
			[
				'type' => 'decidesk.meeting',
				'uuid' => 'm-1',
				'url' => '/apps/decidiq/meetings/m-1',
				'label' => 'Raad',
			],
			$this->formatter->meetingSource(meetingUuid: 'm-1', meeting: ['title' => 'Raad'])
		);
		$this->assertSame('Meeting', $this->formatter->meetingSource(meetingUuid: 'm-1', meeting: [])['label']);
	}

	/**
	 * Up to twenty sources are attached as-is, without truncation markers.
	 *
	 * @return void
	 */
	public function testWithSourcesKeepsTwentySourcesUntruncated(): void {
		$sources = array_map(fn (int $i): array => ['uuid' => "s-{$i}"], range(1, 20));

		$result = $this->formatter->withSources(payload: ['answer' => 1], sources: $sources);

		$this->assertSame(['answer' => 1, 'sources' => $sources], $result);
	}

	/**
	 * More than twenty sources are capped and the total is reported.
	 *
	 * @return void
	 */
	public function testWithSourcesCapsAtTwentyAndReportsTheTotal(): void {
		$sources = array_map(fn (int $i): array => ['uuid' => "s-{$i}"], range(1, 21));

		$result = $this->formatter->withSources(payload: ['answer' => 1], sources: $sources);

		$this->assertSame(1, $result['answer']);
		$this->assertCount(20, $result['sources']);
		$this->assertSame(['uuid' => 's-20'], $result['sources'][19]);
		$this->assertTrue($result['sourcesTruncated']);
		$this->assertSame(21, $result['sourcesTotalCount']);
	}

	/**
	 * The error envelope carries the flag, the code and the message.
	 *
	 * @return void
	 */
	public function testErrorBuildsTheEnvelope(): void {
		$this->assertSame(
			['isError' => true, 'error' => 'forbidden', 'message' => 'No.'],
			$this->formatter->error(code: 'forbidden', message: 'No.')
		);
	}

	/**
	 * The timestamp is an ATOM string close to now.
	 *
	 * @return void
	 */
	public function testNowIsoIsAnAtomTimestamp(): void {
		$parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $this->formatter->nowIso());

		$this->assertInstanceOf(DateTimeImmutable::class, $parsed);
		$this->assertLessThan(60, abs(time() - $parsed->getTimestamp()));
	}
}
