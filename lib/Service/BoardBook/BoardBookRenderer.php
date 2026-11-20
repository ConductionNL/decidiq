<?php

/**
 * Decidiq Board Book Renderer
 *
 * Turns a meeting's agenda into the pack PDF through filinq: the cover and
 * table of contents, one section per agenda item with its PDF attachments
 * merged in place and a bookmark per section. An attachment that cannot be
 * merged gets a placeholder page in its place and a line in the skip report;
 * it never fails the pack. When filinq can render but not merge, every
 * attachment is a placeholder and the report says why.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\BoardBook;

use OCA\Decidiq\Support\FilinqPdf;
use Throwable;

/**
 * Renders the pack PDF, or answers null when filinq cannot render at all.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
 */
class BoardBookRenderer {

	/**
	 * The largest attachment merged into a pack.
	 *
	 * @var int
	 */
	public const MAX_ATTACHMENT_BYTES = 26214400;

	/**
	 * The most attachment bytes merged into one pack.
	 *
	 * @var int
	 */
	public const MAX_PACK_BYTES = 104857600;

	/**
	 * The skip reason of every attachment when filinq cannot merge.
	 *
	 * @var string
	 */
	public const NOT_MERGED = 'merging is not available on this instance';

	/**
	 * Constructor.
	 *
	 * @param FilinqPdf         $pdf      Renders and merges through filinq.
	 * @param BoardBookComposer $composer Composes the pages.
	 */
	public function __construct(
		private readonly FilinqPdf $pdf,
		private readonly BoardBookComposer $composer,
	) {
	}//end __construct()

	/**
	 * Render the pack.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 * @param string $bodyName The governance body's name.
	 * @param array<int, array<string, mixed>> $items The agenda items in orderNumber order.
	 * @param array<string, mixed> $attachments The attachments per item: `rows` and `nodes`, both keyed by item id.
	 *
	 * @return array{pdf: string, skipped: array<int, string>}|null Null when filinq cannot render a PDF.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function render(array $meeting, string $bodyName, array $items, array $attachments): ?array {
		$title = 'Vergaderbundel ' . (string)($meeting['title'] ?? '');
		$flat = $this->plan(meeting: $meeting, bodyName: $bodyName, items: $items, attachments: $attachments);
		if ($flat['pdfs'] > 0) {
			$merged = $this->merged(parts: $flat['parts'], title: $title);
			if ($merged !== null) {
				return ['pdf' => $merged, 'skipped' => $flat['skipped']];
			}

			// Filinq cannot merge: one document with a placeholder for every attachment.
			$flat = $this->plan(meeting: $meeting, bodyName: $bodyName, items: $items, attachments: $attachments, notMerged: self::NOT_MERGED);
		}

		$html = [];
		foreach ($flat['parts'] as $part) {
			$html[] = $part['html'];
		}

		$pdf = $this->pdf->fromHtml(html: $this->composer->document(title: $title, fragments: $html), title: $title, context: 'the meeting pack');
		if ($pdf === null) {
			return null;
		}

		return ['pdf' => $pdf, 'skipped' => $flat['skipped']];
	}//end render()

	/**
	 * The pack as parts in order: HTML fragments (with the section's bookmark
	 * on the part that opens it) and attachment PDFs, and the skip report.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 * @param string $bodyName The governance body's name.
	 * @param array<int, array<string, mixed>> $items The agenda items.
	 * @param array<string, mixed> $attachments The attachments per item: `rows` and `nodes`, both keyed by item id.
	 * @param string|null $notMerged When set, every attachment becomes a placeholder for this reason.
	 *
	 * @return array{parts: array<int, array<string, string>>, skipped: array<int, string>, pdfs: int}
	 */
	private function plan(array $meeting, string $bodyName, array $items, array $attachments, ?string $notMerged=null): array {
		$run = new BoardBookPlan(frontMatter: $this->composer->frontMatter(meeting: $meeting, bodyName: $bodyName, items: $items));
		$bookmarks = $this->composer->bookmarks(items: $items);
		foreach ($items as $index => $item) {
			$run->openSection(html: $this->composer->section(item: $item), bookmark: $bookmarks[$index]);
			if ($this->composer->isConfidential(item: $item) === true) {
				continue;
			}

			$itemId = (string)($item['id'] ?? '');
			foreach (($attachments['rows'][$itemId] ?? []) as $position => $file) {
				$node = ($attachments['nodes'][$itemId][$position] ?? null);
				$this->place(run: $run, item: $item, file: $file, node: $node, notMerged: $notMerged);
			}
		}

		return $run->result();
	}//end plan()

	/**
	 * Put one attachment in the pack: its PDF, or a placeholder and a skip line.
	 *
	 * @param BoardBookPlan        $run     The pack being planned.
	 * @param array<string, mixed> $item    The agenda item.
	 * @param array<string, mixed> $file    The attachment row.
	 * @param object|null          $node    The file node it was read from.
	 * @param string|null          $notMerged When set, the reason no attachment is merged.
	 *
	 * @return void
	 */
	private function place(BoardBookPlan $run, array $item, array $file, ?object $node, ?string $notMerged): void {
		$name = (string)($file['name'] ?? '');
		$reason = $notMerged;
		if ($reason === null) {
			$reason = $this->composer->unmergeableReason(file: $file, maxBytes: self::MAX_ATTACHMENT_BYTES);
		}

		$content = null;
		if ($reason === null) {
			$content = $this->pdfContent(node: $node);
			$reason = $this->contentRefusal(content: $content, run: $run);
		}

		if ($reason !== null || $content === null) {
			$line = sprintf('%02d - %s/%s (%s)', (int)($item['orderNumber'] ?? 0), (string)($item['title'] ?? ''), $name, ($reason ?? 'unreadable'));
			$run->addPlaceholder(html: $this->composer->placeholder(fileName: $name), skipped: $line);
			return;
		}

		$run->addPdf(pdf: $content);
	}//end place()

	/**
	 * Why read content cannot go into the pack, or null when it can.
	 *
	 * @param string|null   $content The attachment's bytes.
	 * @param BoardBookPlan $run     The pack being planned.
	 *
	 * @return string|null
	 */
	private function contentRefusal(?string $content, BoardBookPlan $run): ?string {
		if ($content === null) {
			return 'unreadable';
		}

		if (str_starts_with(ltrim($content), '%PDF') === false || str_contains($content, '/Encrypt') === true) {
			return 'unparsable or encrypted PDF';
		}

		if ($run->pdfBytes() + strlen($content) > self::MAX_PACK_BYTES) {
			return 'the pack size limit is reached';
		}

		return null;
	}//end contentRefusal()

	/**
	 * An attachment's bytes, or null when they cannot be read.
	 *
	 * @param object|null $node The file node.
	 *
	 * @return string|null
	 */
	private function pdfContent(?object $node): ?string {
		if ($node === null || method_exists($node, 'getContent') === false) {
			return null;
		}

		try {
			return (string)$node->getContent();
		} catch (Throwable) {
			return null;
		}
	}//end pdfContent()

	/**
	 * Render each HTML part and merge every part into one PDF; null when any step fails.
	 *
	 * @param array<int, array<string, string>> $parts The parts in order.
	 * @param string                            $title The document title.
	 *
	 * @return string|null
	 */
	private function merged(array $parts, string $title): ?string {
		$streams = [];
		$bookmarks = [];
		foreach ($parts as $part) {
			$stream = ($part['pdf'] ?? null);
			if ($stream === null) {
				$html = $this->composer->document(title: $title, fragments: [$part['html']]);
				$stream = $this->pdf->fromHtml(html: $html, title: $title, context: 'a meeting pack section');
			}

			if ($stream === null) {
				return null;
			}

			if (($part['bookmark'] ?? '') !== '') {
				$bookmarks[count($streams)] = $part['bookmark'];
			}

			$streams[] = $stream;
		}

		return $this->pdf->merge(pdfs: $streams, bookmarks: $bookmarks, context: 'the meeting pack');
	}//end merged()
}//end class
