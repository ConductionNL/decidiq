<?php

/**
 * Tests for PaperWatermarkPolicy (agenda-paper-watermark).
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
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\PaperWatermarkPolicy;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A meeting's setting and its paper's confidentiality decide the stamp.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
 */
final class PaperWatermarkPolicyTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $queries = [];

	/**
	 * The policy over a fixed set of restrictions.
	 *
	 * @param list<array<string, mixed>> $restrictions The restrictions findAll returns
	 * @param bool                       $failRead     Whether findAll throws
	 *
	 * @return PaperWatermarkPolicy
	 */
	private function policy(array $restrictions = [], bool $failRead = false): PaperWatermarkPolicy {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, mixed ...$rest) use ($restrictions, $failRead): array {
				$this->queries[] = ['config' => $config, 'rest' => $rest];
				if ($failRead === true) {
					throw new RuntimeException('database gone');
				}

				$rows = [];
				foreach ($restrictions as $restriction) {
					$entity = new ObjectEntity();
					$entity->setObject($restriction);
					$rows[] = $entity;
				}

				return ['results' => $rows];
			}
		);

		return new PaperWatermarkPolicy(objectService: $objects, logger: new NullLogger());
	}//end policy()

	public function testAutomaticOnAPublicMeetingWithoutRestrictionsDoesNotStamp(): void {
		self::assertFalse($this->policy()->mustStamp(meeting: ['watermarkPapers' => 'automatic', 'isPublic' => true], agendaItemId: 'item-1', documentIds: ['doc-1']));
		self::assertCount(2, $this->queries, 'The item and the document are each checked.');
		self::assertFalse($this->policy()->mustStamp(meeting: ['isPublic' => true]));
		self::assertCount(2, $this->queries, 'A paper without item or document needs no read.');
	}//end testAutomaticOnAPublicMeetingWithoutRestrictionsDoesNotStamp()

	public function testAutomaticOnAClosedMeetingStamps(): void {
		self::assertTrue($this->policy()->mustStamp(meeting: ['watermarkPapers' => 'automatic', 'isPublic' => false], agendaItemId: 'item-1'));
		self::assertTrue($this->policy()->mustStamp(meeting: []), 'A meeting that does not say it is public is not.');
		self::assertSame([], $this->queries);
	}//end testAutomaticOnAClosedMeetingStamps()

	public function testAutomaticOnAPublicMeetingWithARestrictedItemStamps(): void {
		$policy = $this->policy(restrictions: [['scope' => 'item', 'targetAgendaItem' => 'item-1', 'lifecycle' => 'imposed']]);
		self::assertTrue($policy->mustStamp(meeting: ['isPublic' => true], agendaItemId: 'item-1'));

		$query = $this->queries[0];
		self::assertSame(
			['register' => 'decidiq', 'schema' => 'confidentiality-restriction', 'scope' => 'item', 'targetAgendaItem' => 'item-1'],
			$query['config']['filters']
		);
		self::assertSame([false, false], array_slice($query['rest'], -2), 'The restrictions are read as the system.');
	}//end testAutomaticOnAPublicMeetingWithARestrictedItemStamps()

	public function testARestrictedDocumentStampsAndADissolvedOrForeignOneDoesNot(): void {
		$ratified = $this->policy(restrictions: [['scope' => 'document', 'targetDocument' => 'doc-2', 'lifecycle' => 'ratified']]);
		self::assertTrue($ratified->mustStamp(meeting: ['isPublic' => true], documentIds: ['doc-1', 'doc-2']));

		$dissolved = $this->policy(restrictions: [['scope' => 'item', 'targetAgendaItem' => 'item-1', 'lifecycle' => 'dissolved']]);
		self::assertFalse($dissolved->mustStamp(meeting: ['isPublic' => true], agendaItemId: 'item-1'));

		$foreign = $this->policy(restrictions: [
			['scope' => 'item', 'targetAgendaItem' => 'item-9', 'lifecycle' => 'imposed'],
			['scope' => 'decision', 'targetAgendaItem' => 'item-1', 'lifecycle' => 'imposed'],
		]);
		self::assertFalse($foreign->mustStamp(meeting: ['isPublic' => true], agendaItemId: 'item-1', documentIds: ['', 'doc-1']));
	}//end testARestrictedDocumentStampsAndADissolvedOrForeignOneDoesNot()

	public function testOffNeverStampsAndOnAlwaysDoes(): void {
		$restricted = $this->policy(restrictions: [['scope' => 'item', 'targetAgendaItem' => 'item-1', 'lifecycle' => 'imposed']]);
		self::assertFalse($restricted->mustStamp(meeting: ['watermarkPapers' => 'off', 'isPublic' => false], agendaItemId: 'item-1'));
		self::assertTrue($this->policy()->mustStamp(meeting: ['watermarkPapers' => 'on', 'isPublic' => true]));
		self::assertSame([], $this->queries);
	}//end testOffNeverStampsAndOnAlwaysDoes()

	public function testAnUnreadableRestrictionStamps(): void {
		self::assertTrue($this->policy(failRead: true)->mustStamp(meeting: ['isPublic' => true], agendaItemId: 'item-1'));
	}//end testAnUnreadableRestrictionStamps()

	public function testTheStampNamesTheReaderTheMomentAndTheMeetingText(): void {
		$at = new DateTimeImmutable('2026-10-03 20:15:00');
		self::assertSame('Aisha Bakker, 2026-10-03 20:15, Vertrouwelijk', $this->policy()->stampText(meeting: ['watermarkText' => ' Vertrouwelijk '], displayName: 'Aisha Bakker', openedAt: $at));
		self::assertSame('Aisha Bakker, 2026-10-03 20:15', $this->policy()->stampText(meeting: [], displayName: 'Aisha Bakker', openedAt: $at));
		self::assertSame('2026-10-03 20:15', $this->policy()->stampText(meeting: ['watermarkText' => ''], displayName: ' ', openedAt: $at));
	}//end testTheStampNamesTheReaderTheMomentAndTheMeetingText()

	public function testTheMeetingCarriesTheWatermarkSettings(): void {
		$meeting = MergedRegisterSchema::forSlug(slug: 'meeting');
		self::assertSame(['automatic', 'on', 'off'], $meeting['properties']['watermarkPapers']['enum']);
		self::assertSame('automatic', $meeting['properties']['watermarkPapers']['default']);
		self::assertSame(60, $meeting['properties']['watermarkText']['maxLength']);
		self::assertNotSame([], MergedRegisterSchema::violations(slug: 'meeting', payload: ['title' => 'x', 'watermarkPapers' => 'sometimes']));

		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$include  = [];
		foreach ($manifest['pages'] as $page) {
			foreach (($page['config']['widgets'] ?? []) as $widget) {
				if (($page['id'] ?? '') === 'MeetingDetail' && ($widget['id'] ?? '') === 'meeting-planning') {
					$include = $widget['content']['include'];
				}
			}
		}

		self::assertContains('watermarkPapers', $include);
		self::assertContains('watermarkText', $include);
	}//end testTheMeetingCarriesTheWatermarkSettings()
}//end class
