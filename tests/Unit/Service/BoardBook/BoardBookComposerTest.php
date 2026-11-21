<?php

/**
 * Unit tests for BoardBookComposer.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\BoardBook;

use OCA\Decidiq\Service\BoardBook\BoardBookComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pack's pages, bookmarks, file name and fingerprint.
 */
#[CoversClass(BoardBookComposer::class)]
class BoardBookComposerTest extends TestCase {

	/**
	 * Two agenda items, the second confidential.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items(): array {
		return [
			['id' => 'a', 'orderNumber' => 1, 'title' => 'Opening', 'itemType' => 'formality', 'description' => 'Welkom <b>allen</b>'],
			['id' => 'b', 'orderNumber' => 2, 'title' => 'Jaarrekening', 'description' => 'Geheime cijfers', 'confidentiality' => 'confidential'],
		];
	}//end items()

	/**
	 * The same state gives the same fingerprint; a reorder, a retitle or a new etag changes it.
	 *
	 * @return void
	 */
	public function testFingerprintFollowsOrderTitlesAndEtags(): void {
		$composer = new BoardBookComposer();
		$files = ['a' => [['id' => '7', 'etag' => 'e1']]];
		$base = $composer->fingerprint(items: self::items(), attachments: $files);

		$this->assertStringStartsWith(prefix: 'sha256:', string: $base);
		$this->assertSame(expected: $base, actual: $composer->fingerprint(items: self::items(), attachments: $files));

		$reordered = self::items();
		$reordered[0]['orderNumber'] = 3;
		$this->assertNotSame(expected: $base, actual: $composer->fingerprint(items: $reordered, attachments: $files));

		$retitled = self::items();
		$retitled[1]['title'] = 'Jaarrekening 2024';
		$this->assertNotSame(expected: $base, actual: $composer->fingerprint(items: $retitled, attachments: $files));

		$this->assertNotSame(expected: $base, actual: $composer->fingerprint(items: self::items(), attachments: ['a' => [['id' => '7', 'etag' => 'e2']]]));
	}//end testFingerprintFollowsOrderTitlesAndEtags()

	/**
	 * A confidential section shows only the closed-doors text; an open one its type and escaped description.
	 *
	 * @return void
	 */
	public function testAConfidentialSectionCarriesOnlyThePlaceholder(): void {
		$composer = new BoardBookComposer();
		$closed = $composer->section(item: self::items()[1]);
		$open = $composer->section(item: self::items()[0]);

		$this->assertStringContainsString(needle: BoardBookComposer::CLOSED_DOORS, haystack: $closed);
		$this->assertStringContainsString(needle: '2. Jaarrekening', haystack: $closed);
		$this->assertStringNotContainsString(needle: 'Geheime cijfers', haystack: $closed);

		$this->assertStringContainsString(needle: 'formality', haystack: $open);
		$this->assertStringContainsString(needle: 'Welkom &lt;b&gt;allen&lt;/b&gt;', haystack: $open);
		$this->assertStringNotContainsString(needle: BoardBookComposer::CLOSED_DOORS, haystack: $open);
	}//end testAConfidentialSectionCarriesOnlyThePlaceholder()

	/**
	 * The cover names the body, the title, the Dutch date and the place; the contents list every item.
	 *
	 * @return void
	 */
	public function testTheFrontMatterListsEveryItemIncludingTheConfidentialOne(): void {
		$html = (new BoardBookComposer())->frontMatter(
			meeting: ['title' => 'Raadsvergadering', 'scheduledDate' => '2025-01-15T19:30:00Z', 'location' => 'Raadzaal'],
			bodyName: 'Gemeenteraad',
			items: self::items()
		);

		foreach (['Gemeenteraad', 'Raadsvergadering', '15 januari 2025', 'Raadzaal', '<li>1. Opening</li>', '<li>2. Jaarrekening</li>'] as $expected) {
			$this->assertStringContainsString(needle: $expected, haystack: $html);
		}

		$this->assertSame(expected: ['1. Opening', '2. Jaarrekening'], actual: (new BoardBookComposer())->bookmarks(items: self::items()));
	}//end testTheFrontMatterListsEveryItemIncludingTheConfidentialOne()

	/**
	 * The file name carries the meeting, its date and the version, without path characters.
	 *
	 * @return void
	 */
	public function testTheFileNameIsDistinguishableByVersion(): void {
		$composer = new BoardBookComposer();

		$this->assertSame(
			expected: 'Vergaderbundel Raadsvergadering 2025-01-15 v2.pdf',
			actual: $composer->fileName(meeting: ['title' => 'Raadsvergadering', 'scheduledDate' => '2025-01-15T19:30:00Z'], version: 2)
		);
		$this->assertSame(expected: 'Vergaderbundel RvC-Q1 v1.pdf', actual: $composer->fileName(meeting: ['title' => 'RvC/Q1'], version: 1));
	}//end testTheFileNameIsDistinguishableByVersion()

	/**
	 * Only a PDF within the size guard is merged; pages are counted from their page objects.
	 *
	 * @return void
	 */
	public function testMergeabilityAndPageCount(): void {
		$composer = new BoardBookComposer();

		$this->assertNull(actual: $composer->unmergeableReason(file: ['name' => 'stuk.PDF', 'size' => 10], maxBytes: 100));
		$this->assertSame(expected: 'non-PDF attachment', actual: $composer->unmergeableReason(file: ['name' => 'stuk.docx', 'mime' => 'application/msword'], maxBytes: 100));
		$this->assertSame(expected: 'larger than the size limit', actual: $composer->unmergeableReason(file: ['name' => 'a.pdf', 'size' => 101], maxBytes: 100));
		$this->assertSame(expected: 2, actual: $composer->pageCount(pdf: '<< /Type /Pages >> << /Type /Page >> << /Type/Page >>'));
	}//end testMergeabilityAndPageCount()
}//end class
