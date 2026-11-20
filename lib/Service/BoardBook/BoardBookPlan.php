<?php

/**
 * Decidiq Board Book Plan
 *
 * The meeting pack as it is being laid out: HTML fragments and attachment
 * PDFs in order, the bookmark of the part that opens each section, and the
 * skip report. Consecutive HTML is kept in one part, so filinq renders as few
 * documents as the merged attachments allow.
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

/**
 * Collects the pack's parts in order.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
 */
class BoardBookPlan {

	/**
	 * The parts: `{html, bookmark}` or `{pdf}`.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $parts = [];

	/**
	 * The skip report.
	 *
	 * @var array<int, string>
	 */
	private array $skipped = [];

	/**
	 * The attachment PDFs placed so far.
	 *
	 * @var int
	 */
	private int $pdfs = 0;

	/**
	 * Their bytes so far.
	 *
	 * @var int
	 */
	private int $bytes = 0;

	/**
	 * Start the plan with the cover and the table of contents.
	 *
	 * @param string $frontMatter The front matter's HTML.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function __construct(string $frontMatter) {
		$this->parts[] = ['html' => $frontMatter, 'bookmark' => ''];
	}//end __construct()

	/**
	 * Open an agenda item's section: a new part carrying its bookmark.
	 *
	 * @param string $html     The section's HTML.
	 * @param string $bookmark Its bookmark.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function openSection(string $html, string $bookmark): void {
		$this->parts[] = ['html' => $html, 'bookmark' => $bookmark];
	}//end openSection()

	/**
	 * Add a placeholder page after what is there, and its skip line.
	 *
	 * @param string $html    The placeholder's HTML.
	 * @param string $skipped The skip line.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
	 */
	public function addPlaceholder(string $html, string $skipped): void {
		$this->skipped[] = $skipped;
		$last = (count($this->parts) - 1);
		if (isset($this->parts[$last]['html']) === true) {
			$this->parts[$last]['html'] .= "\n" . $html;
			return;
		}

		$this->parts[] = ['html' => $html, 'bookmark' => ''];
	}//end addPlaceholder()

	/**
	 * Add an attachment's PDF after what is there.
	 *
	 * @param string $pdf The PDF bytes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function addPdf(string $pdf): void {
		$this->parts[] = ['pdf' => $pdf];
		$this->pdfs++;
		$this->bytes += strlen($pdf);
	}//end addPdf()

	/**
	 * The attachment bytes placed so far.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
	 */
	public function pdfBytes(): int {
		return $this->bytes;
	}//end pdfBytes()

	/**
	 * The parts, the skip report and the number of attachment PDFs.
	 *
	 * @return array{parts: array<int, array<string, string>>, skipped: array<int, string>, pdfs: int}
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
	 */
	public function result(): array {
		return ['parts' => $this->parts, 'skipped' => $this->skipped, 'pdfs' => $this->pdfs];
	}//end result()
}//end class
