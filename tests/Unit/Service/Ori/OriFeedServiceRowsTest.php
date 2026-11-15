<?php

/**
 * The ORI feeds read stored rows in every shape the object service returns them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\Decidiq\Service\Feed\RssDocument;
use OCA\Decidiq\Service\Ori\OriExportMapper;
use OCA\Decidiq\Service\Ori\OriFeedService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriFeedService::class)]
#[UsesClass(OriExportMapper::class)]
#[UsesClass(RssDocument::class)]
final class OriFeedServiceRowsTest extends TestCase {

	/**
	 * A feed service over rows the object service hands back per schema.
	 *
	 * @param array<string, array<int, mixed>> $rows The rows per schema.
	 *
	 * @return OriFeedService
	 */
	private function feeds(array $rows): OriFeedService {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('findAll')->willReturnCallback(
			static function (array $config=[]) use ($rows): array {
				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? null) !== 'decidiq') {
					return [];
				}

				return ($rows[(string)($filters['schema'] ?? '')] ?? []);
			}
		);

		return new OriFeedService($service, new OriExportMapper(), new RssDocument());
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

	public function testAnEntityRowIsReadThroughItsUuidAndObject(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn('m-entity');
		$entity->method('getObject')->willReturn(['title' => 'Raad als entiteit', 'meetingType' => 'regular', 'lifecycle' => 'scheduled', 'isPublic' => true]);

		$xml = $this->feeds(rows: ['meeting' => [$entity]])->meetings();

		$this->assertSame(['m-entity'], self::guids($xml));
		$this->assertSame('Raad als entiteit', (string)simplexml_load_string($xml)->channel->item[0]->title);
	}

	public function testARowWithoutAnIdTakesItsUuidOrItsSelfId(): void {
		$xml = $this->feeds(rows: ['meeting' => [
			['uuid' => 'm-uuid', 'title' => 'Via uuid', 'meetingType' => 'regular', 'lifecycle' => 'scheduled', 'isPublic' => true],
			['@self' => ['id' => 'm-self'], 'title' => 'Via self', 'meetingType' => 'regular', 'lifecycle' => 'scheduled', 'isPublic' => true],
		]])->meetings();

		$this->assertSame(['m-uuid', 'm-self'], self::guids($xml));
	}

	public function testAnEmptyStoreGivesAnEmptyFeed(): void {
		$feeds = $this->feeds(rows: []);

		$this->assertSame([], self::guids($feeds->meetings()));
		$this->assertSame([], self::guids($feeds->agendaItems()));
		$this->assertSame([], self::guids($feeds->documents()));
	}
}
