<?php

/**
 * RSS 2.0 rendering.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Feed;

use OCA\Decidiq\Service\Feed\RssDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RssDocument::class)]
final class RssDocumentTest extends TestCase {

	public function testAChannelWithItemsIsRss2(): void {
		$xml = simplexml_load_string((new RssDocument())->render(
			title: 'Meetings',
			description: 'Public meetings',
			items: [['title' => 'Raad', 'guid' => 'u-1', 'pubDate' => '2026-01-10T19:30:00+01:00', 'category' => 'confirmed', 'description' => '{"name":"Raad"}']]
		));

		$this->assertSame('2.0', (string)$xml['version']);
		$this->assertSame('Meetings', (string)$xml->channel->title);
		$this->assertSame('Public meetings', (string)$xml->channel->description);
		$item = $xml->channel->item[0];
		$this->assertSame('Raad', (string)$item->title);
		$this->assertSame('u-1', (string)$item->guid);
		$this->assertSame('false', (string)$item->guid['isPermaLink']);
		$this->assertSame('Sat, 10 Jan 2026 19:30:00 +0100', (string)$item->pubDate);
		$this->assertSame('confirmed', (string)$item->category);
		$this->assertSame('{"name":"Raad"}', (string)$item->description);
	}

	public function testMarkupInAValueIsEscapedNotInterpreted(): void {
		$rendered = (new RssDocument())->render(title: 'T', description: 'D', items: [['title' => '<b>Motie</b> & co']]);

		$this->assertStringContainsString('<title>&lt;b&gt;Motie&lt;/b&gt; &amp; co</title>', $rendered);
		$this->assertSame('<b>Motie</b> & co', (string)simplexml_load_string($rendered)->channel->item[0]->title);
	}

	public function testAbsentOrUnparsableFieldsAreLeftOut(): void {
		$item = simplexml_load_string((new RssDocument())->render(title: 'T', description: 'D', items: [['title' => 'x', 'pubDate' => 'not a date']]))->channel->item[0];

		$this->assertCount(0, $item->pubDate);
		$this->assertCount(0, $item->guid);
		$this->assertCount(0, $item->description);
		$this->assertSame('application/rss+xml; charset=utf-8', RssDocument::CONTENT_TYPE);
	}
	public function testItemsKeepTheirOrderAndAnUntitledItemGetsAnEmptyTitle(): void {
		$channel = simplexml_load_string((new RssDocument())->render(
			title: 'T',
			description: 'D',
			items: [['title' => 'first'], ['guid' => 'u-2'], ['title' => 'third', 'pubDate' => '']]
		))->channel;

		$this->assertCount(3, $channel->item);
		$this->assertSame('first', (string)$channel->item[0]->title);
		$this->assertCount(1, $channel->item[1]->title);
		$this->assertSame('', (string)$channel->item[1]->title);
		$this->assertSame('u-2', (string)$channel->item[1]->guid);
		$this->assertSame('third', (string)$channel->item[2]->title);
		$this->assertCount(0, $channel->item[2]->pubDate, 'an empty date is left out');
		$this->assertCount(0, $channel->item[0]->category);
	}

	public function testAChannelWithoutItemsHasOnlyItsTitleAndDescription(): void {
		$rendered = (new RssDocument())->render(title: 'Leeg', description: 'Niets', items: []);
		$channel  = simplexml_load_string($rendered)->channel;

		$this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $rendered);
		$this->assertSame(['title', 'description'], array_map(static fn ($node): string => $node->getName(), iterator_to_array($channel->children(), false)));
		$this->assertSame('Leeg', (string)$channel->title);
		$this->assertSame('Niets', (string)$channel->description);
	}

	public function testAPubDateIsWrittenInItsOwnOffset(): void {
		$item = simplexml_load_string((new RssDocument())->render(title: 'T', description: 'D', items: [['title' => 'x', 'pubDate' => '2026-07-01 08:15:00 UTC']]))->channel->item[0];

		$this->assertSame('Wed, 01 Jul 2026 08:15:00 +0000', (string)$item->pubDate);
	}
}
