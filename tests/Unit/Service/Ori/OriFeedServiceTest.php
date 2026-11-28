<?php

/**
 * The public ORI feeds: only public meetings, their agenda items and their documents.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\Decidiq\Service\Feed\RssDocument;
use OCA\Decidiq\Service\Ori\OriExportMapper;
use OCA\Decidiq\Service\Ori\OriFeedService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriFeedService::class)]
#[UsesClass(OriExportMapper::class)]
#[UsesClass(RssDocument::class)]
final class OriFeedServiceTest extends TestCase {

	private OriFeedService $feeds;

	protected function setUp(): void {
		$store = new InMemoryObjects();
		$store->seed('decidiq', 'meeting', ['id' => 'm-public', 'title' => 'Raad', 'meetingType' => 'regular', 'lifecycle' => 'cancelled', 'scheduledDate' => '2026-01-10T19:30:00+01:00', 'isPublic' => true]);
		$store->seed('decidiq', 'meeting', ['id' => 'm-closed', 'title' => 'Besloten overleg', 'meetingType' => 'regular', 'lifecycle' => 'scheduled', 'isPublic' => false]);
		$store->seed('decidiq', 'meeting', ['id' => 'm-unmarked', 'title' => 'Ongemarkeerd', 'meetingType' => 'regular', 'lifecycle' => 'scheduled']);
		$store->seed('decidiq', 'agenda-item', ['id' => 'a-public', 'title' => 'Motie', 'orderNumber' => 1, 'meeting' => 'm-public', 'documents' => ['d-public']]);
		$store->seed('decidiq', 'agenda-item', ['id' => 'a-closed', 'title' => 'Personeelszaak', 'orderNumber' => 1, 'meeting' => 'm-closed', 'documents' => ['d-closed']]);
		$store->seed('decidiq', 'digital-document', ['id' => 'd-public', 'name' => 'Brief', 'documentType' => 'letter']);
		$store->seed('decidiq', 'digital-document', ['id' => 'd-closed', 'name' => 'Dossier', 'documentType' => 'report']);
		$store->seed('decidiq', 'digital-document', ['id' => 'd-loose', 'name' => 'Los', 'documentType' => 'report']);
		$this->feeds = new OriFeedService($store->service($this), new OriExportMapper(), new RssDocument());
	}

	/**
	 * The guids of a feed's items.
	 *
	 * @param string $xml The feed.
	 *
	 * @return array<int, string>
	 */
	private static function guids(string $xml): array {
		$guids = [];
		foreach (simplexml_load_string($xml)->channel->item as $item) {
			$guids[] = (string)$item->guid;
		}

		return $guids;
	}

	public function testOnlyMeetingsMarkedPublicAreListed(): void {
		$xml = $this->feeds->meetings();

		$this->assertSame(['m-public'], self::guids($xml));
		$item = simplexml_load_string($xml)->channel->item[0];
		$this->assertSame('afgelast', (string)$item->category);
		$this->assertSame('afgelast', json_decode((string)$item->description, true)['status']);
		$this->assertSame('Raad', json_decode((string)$item->description, true)['name']);
	}

	public function testOnlyAgendaItemsOfAPublicMeetingAreListed(): void {
		$xml = $this->feeds->agendaItems();

		$this->assertSame(['a-public'], self::guids($xml));
		$this->assertSame('m-public', json_decode((string)simplexml_load_string($xml)->channel->item[0]->description, true)['vergadering']);
	}

	public function testOnlyDocumentsAttachedToAPublicAgendaItemAreListed(): void {
		$xml = $this->feeds->documents();

		$this->assertSame(['d-public'], self::guids($xml));
		$this->assertSame('brief', (string)simplexml_load_string($xml)->channel->item[0]->category);
	}
	/**
	 * A document under an imposed or ratified restriction, on itself or on its agenda item, stays out of the feed (REQ-EMB-005).
	 *
	 * @return void
	 */
	public function testARestrictedDocumentAndThePapersOfARestrictedItemAreLeftOut(): void {
		$store = new InMemoryObjects();
		$store->seed('decidiq', 'meeting', ['id' => 'm', 'title' => 'Raad', 'isPublic' => true]);
		$store->seed('decidiq', 'agenda-item', ['id' => 'a-open', 'title' => 'Motie', 'meeting' => 'm', 'documents' => ['d-open', 'd-own', 'd-lifted', 'd-ratified']]);
		$store->seed('decidiq', 'agenda-item', ['id' => 'a-closed', 'title' => 'Grondzaak', 'meeting' => 'm', 'documents' => ['d-paper']]);
		foreach (['d-open', 'd-own', 'd-lifted', 'd-ratified', 'd-paper'] as $id) {
			$store->seed('decidiq', 'digital-document', ['id' => $id, 'name' => $id, 'documentType' => 'letter']);
		}

		$store->seed('decidiq', 'confidentiality-restriction', ['id' => 'r-1', 'scope' => 'document', 'targetDocument' => 'd-own', 'lifecycle' => 'imposed']);
		$store->seed('decidiq', 'confidentiality-restriction', ['id' => 'r-2', 'scope' => 'document', 'targetDocument' => 'd-ratified', 'lifecycle' => 'ratified']);
		$store->seed('decidiq', 'confidentiality-restriction', ['id' => 'r-3', 'scope' => 'document', 'targetDocument' => 'd-lifted', 'lifecycle' => 'dissolved']);
		$store->seed('decidiq', 'confidentiality-restriction', ['id' => 'r-4', 'scope' => 'item', 'targetAgendaItem' => 'a-closed', 'lifecycle' => 'imposed']);

		$feeds = new OriFeedService($store->service($this), new OriExportMapper(), new RssDocument());

		$this->assertSame(['d-open', 'd-lifted'], self::guids($feeds->documents()));
	}

	/**
	 * A document whose embargo is still active stays out; a released one is listed (REQ-EMB-005).
	 *
	 * @return void
	 */
	public function testAnEmbargoedDocumentIsLeftOutUntilItsEmbargoIsReleased(): void {
		$store = new InMemoryObjects();
		$store->seed('decidiq', 'meeting', ['id' => 'm', 'title' => 'Raad', 'isPublic' => true]);
		$store->seed('decidiq', 'agenda-item', ['id' => 'a', 'title' => 'Begroting', 'meeting' => 'm', 'documents' => ['d-locked', 'd-released', 'd-plain']]);
		$store->seed('decidiq', 'digital-document', ['id' => 'd-locked', 'name' => 'Begroting', 'documentType' => 'report', 'embargoActive' => true, 'embargoUntil' => '2099-01-01T00:00:00+00:00']);
		$store->seed('decidiq', 'digital-document', ['id' => 'd-released', 'name' => 'Voorjaarsnota', 'documentType' => 'report', 'embargoActive' => false, 'embargoUntil' => '2020-01-01T00:00:00+00:00']);
		$store->seed('decidiq', 'digital-document', ['id' => 'd-plain', 'name' => 'Brief', 'documentType' => 'letter']);

		$feeds = new OriFeedService($store->service($this), new OriExportMapper(), new RssDocument());

		$this->assertSame(['d-released', 'd-plain'], self::guids($feeds->documents()));
	}

	/**
	 * When the restrictions cannot be read the feed fails rather than list documents unchecked.
	 *
	 * @return void
	 */
	public function testTheDocumentsFeedFailsClosedWhenTheRestrictionsCannotBeRead(): void {
		$objects = $this->createMock(\OCA\OpenRegister\Contract\ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config=[]): array {
				if (($config['filters']['schema'] ?? '') === 'confidentiality-restriction') {
					throw new \RuntimeException('database gone');
				}

				return [];
			}
		);
		$feeds = new OriFeedService($objects, new OriExportMapper(), new RssDocument());

		$this->expectException(\RuntimeException::class);
		$feeds->documents();
	}
}
