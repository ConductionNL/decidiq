<?php

/**
 * Decidiq Board Book Composer
 *
 * The pure half of the meeting pack (vergaderbundel): the HTML of the cover,
 * the table of contents and each agenda item section, the closed-doors and
 * attachment placeholder pages, the bookmarks, the file name and the
 * fingerprint of the agenda state the pack was compiled from. No I/O.
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
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\BoardBook;

/**
 * Composes the meeting pack's pages, bookmarks, file name and fingerprint.
 *
 * An agenda item is `{id, title, orderNumber, itemType, description,
 * confidentiality}`; an attachment is `{id, name, etag, mime, size}`.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */
class BoardBookComposer {

	/**
	 * The section body of a confidential agenda item.
	 *
	 * @var string
	 */
	public const CLOSED_DOORS = 'Behandeling achter gesloten deuren — stukken separaat beschikbaar';

	/**
	 * The refusal for a meeting without agenda items.
	 *
	 * @var string
	 */
	public const EMPTY_AGENDA = 'Een vergaderbundel vereist minimaal één agendapunt';

	/**
	 * Dutch month names, for the cover date.
	 *
	 * @var array<int, string>
	 */
	private const MONTHS = [
		1 => 'januari',
		2 => 'februari',
		3 => 'maart',
		4 => 'april',
		5 => 'mei',
		6 => 'juni',
		7 => 'juli',
		8 => 'augustus',
		9 => 'september',
		10 => 'oktober',
		11 => 'november',
		12 => 'december',
	];

	/**
	 * Whether an agenda item is held behind closed doors.
	 *
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-005-access-control-and-confidential-item-exclusion
	 */
	public function isConfidential(array $item): bool {
		return ($item['confidentiality'] ?? 'internal') === 'confidential';
	}//end isConfidential()

	/**
	 * The fingerprint of an agenda state: the ordered item ids with their
	 * orderNumber and title, and the id and etag of each item's attachments.
	 *
	 * @param array<int, array<string, mixed>>             $items       The agenda items in orderNumber order.
	 * @param array<string, array<int, array<string, mixed>>> $attachments The attachments per item id.
	 *
	 * @return string `sha256:<hex>`.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function fingerprint(array $items, array $attachments): string {
		$state = [];
		foreach ($items as $item) {
			$id = (string)($item['id'] ?? '');
			$files = [];
			foreach (($attachments[$id] ?? []) as $file) {
				$files[] = [(string)($file['id'] ?? ''), (string)($file['etag'] ?? '')];
			}

			$state[] = [$id, (int)($item['orderNumber'] ?? 0), (string)($item['title'] ?? ''), $files];
		}

		return 'sha256:' . hash('sha256', (string)json_encode($state, JSON_UNESCAPED_UNICODE));
	}//end fingerprint()

	/**
	 * The bookmark of each agenda item section: `"<orderNumber>. <title>"`.
	 *
	 * @param array<int, array<string, mixed>> $items The agenda items in orderNumber order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function bookmarks(array $items): array {
		$bookmarks = [];
		foreach ($items as $item) {
			$bookmarks[] = $this->sectionTitle(item: $item);
		}

		return $bookmarks;
	}//end bookmarks()

	/**
	 * The pack's file name, distinguishable by meeting, date and version.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 * @param int                  $version The pack version.
	 *
	 * @return string E.g. `Vergaderbundel Raadsvergadering 2025-01-15 v2.pdf`.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-006-offline-delivery-into-attendees-own-files
	 */
	public function fileName(array $meeting, int $version): string {
		$parts = ['Vergaderbundel'];
		$title = $this->pathSafe(text: (string)($meeting['title'] ?? ''));
		if ($title !== '') {
			$parts[] = $title;
		}

		$date = substr((string)($meeting['scheduledDate'] ?? ''), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
			$parts[] = $date;
		}

		$parts[] = 'v' . $version;

		return implode(' ', $parts) . '.pdf';
	}//end fileName()

	/**
	 * The cover page and the table of contents.
	 *
	 * @param array<string, mixed>             $meeting  The meeting.
	 * @param string                           $bodyName The governance body's name.
	 * @param array<int, array<string, mixed>> $items    The agenda items in orderNumber order.
	 *
	 * @return string An HTML fragment.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function frontMatter(array $meeting, string $bodyName, array $items): string {
		$cover = ['<div class="cover">'];
		if ($bodyName !== '') {
			$cover[] = '<p class="body">' . $this->escape(text: $bodyName) . '</p>';
		}

		$cover[] = '<h1>' . $this->escape(text: (string)($meeting['title'] ?? 'Vergadering')) . '</h1>';
		$date = $this->dutchDate(value: (string)($meeting['scheduledDate'] ?? ''));
		if ($date !== '') {
			$cover[] = '<p class="date">' . $this->escape(text: $date) . '</p>';
		}

		$location = trim((string)($meeting['location'] ?? ''));
		if ($location !== '') {
			$cover[] = '<p class="location">' . $this->escape(text: $location) . '</p>';
		}

		$cover[] = '</div>';

		$toc = ['<div class="toc" style="page-break-before: always;">', '<h2>Inhoudsopgave</h2>', '<ol>'];
		foreach ($items as $item) {
			$toc[] = '<li>' . $this->escape(text: $this->sectionTitle(item: $item)) . '</li>';
		}

		$toc[] = '</ol>';
		$toc[] = '</div>';

		return implode("\n", array_merge($cover, $toc));
	}//end frontMatter()

	/**
	 * One agenda item's section: its title, type and description, or only
	 * the closed-doors placeholder for a confidential item.
	 *
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return string An HTML fragment that starts on a new page.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-005-access-control-and-confidential-item-exclusion
	 */
	public function section(array $item): string {
		$html = [
			'<div class="section" style="page-break-before: always;">',
			'<h2>' . $this->escape(text: $this->sectionTitle(item: $item)) . '</h2>',
		];
		if ($this->isConfidential(item: $item) === true) {
			$html[] = '<p class="closed">' . $this->escape(text: self::CLOSED_DOORS) . '</p>';
			$html[] = '</div>';
			return implode("\n", $html);
		}

		$itemType = trim((string)($item['itemType'] ?? ''));
		if ($itemType !== '') {
			$html[] = '<p class="type">' . $this->escape(text: $itemType) . '</p>';
		}

		$description = trim((string)($item['description'] ?? ''));
		if ($description !== '') {
			$html[] = '<p>' . nl2br($this->escape(text: $description)) . '</p>';
		}

		$html[] = '</div>';

		return implode("\n", $html);
	}//end section()

	/**
	 * The page in an attachment's place when it is not merged into the pack.
	 *
	 * @param string $fileName The attachment's name.
	 *
	 * @return string An HTML fragment that starts on a new page.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
	 */
	public function placeholder(string $fileName): string {
		return implode(
			"\n",
			[
				'<div class="placeholder" style="page-break-before: always;">',
				'<h3>' . $this->escape(text: $fileName) . '</h3>',
				'<p>Dit stuk is niet in de vergaderbundel opgenomen. Het is los beschikbaar bij het agendapunt, onder Bestanden.</p>',
				'</div>',
			]
		);
	}//end placeholder()

	/**
	 * A complete HTML document around fragments.
	 *
	 * @param string             $title     The document title.
	 * @param array<int, string> $fragments The fragments, in order.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function document(string $title, array $fragments): string {
		return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><title>' . $this->escape(text: $title) . '</title></head><body>'
			. implode("\n", $fragments) . '</body></html>';
	}//end document()

	/**
	 * Whether an attachment can be merged as a PDF within the size guard.
	 *
	 * @param array<string, mixed> $file     The attachment.
	 * @param int                  $maxBytes The size guard.
	 *
	 * @return string|null Why it is not merged, or null when it can be.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-002-defensive-attachment-merging-with-placeholder-pages
	 */
	public function unmergeableReason(array $file, int $maxBytes): ?string {
		$name = strtolower((string)($file['name'] ?? ''));
		$mime = strtolower((string)($file['mime'] ?? ''));
		if ($mime !== 'application/pdf' && str_ends_with($name, '.pdf') === false) {
			return 'non-PDF attachment';
		}

		if ((int)($file['size'] ?? 0) > $maxBytes) {
			return 'larger than the size limit';
		}

		return null;
	}//end unmergeableReason()

	/**
	 * The number of pages in a PDF, counted from its page objects.
	 *
	 * @param string $pdf The PDF bytes.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
	 */
	public function pageCount(string $pdf): int {
		return (int)preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
	}//end pageCount()

	/**
	 * The section title of an agenda item: `"<orderNumber>. <title>"`.
	 *
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return string
	 */
	private function sectionTitle(array $item): string {
		return sprintf('%d. %s', (int)($item['orderNumber'] ?? 0), (string)($item['title'] ?? 'Agendapunt'));
	}//end sectionTitle()

	/**
	 * A date written the Dutch way, e.g. "15 januari 2025"; empty when it is no date.
	 *
	 * @param string $value An ISO date or date-time.
	 *
	 * @return string
	 */
	private function dutchDate(string $value): string {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) !== 1 || isset(self::MONTHS[(int)$match[2]]) === false) {
			return '';
		}

		return sprintf('%d %s %s', (int)$match[3], self::MONTHS[(int)$match[2]], $match[1]);
	}//end dutchDate()

	/**
	 * Text made safe for a file name.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function pathSafe(string $text): string {
		$clean = (string)preg_replace('/[\/\\\\:*?"<>|]+/', '-', $text);
		$clean = (string)preg_replace('/[[:cntrl:]]+/', '', $clean);

		return trim($clean, ' .-');
	}//end pathSafe()

	/**
	 * Text escaped for HTML.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, (ENT_QUOTES | ENT_SUBSTITUTE), 'UTF-8');
	}//end escape()
}//end class
