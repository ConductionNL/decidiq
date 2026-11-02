<?php

/**
 * Decidiq Minutes Document Service
 *
 * Renders the minutes content into a formatted document and persists it into
 * the linked meeting's Files folder ('Minutes' subfolder). PDF rendering is
 * delegated to filinq when (and only when) FilinqPdf can resolve it, under
 * whichever name that app carries on this instance; otherwise the plain
 * markdown document is produced and the response says so honestly.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/specs/resolution-minutes/spec.md
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

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\MissingRelationException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generates and persists minutes documents (markdown canonical, Docudesk PDF
 * opportunistic) into the meeting's Files folder.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesDocumentService {

	/**
	 * Formats the caller may request.
	 *
	 * @var string[]
	 */
	public const FORMATS = [
		'markdown',
		'pdf',
	];

	/**
	 * Block-level markdown patterns mapped to their HTML template, in match order.
	 *
	 * @var array<string, string>
	 */
	private const BLOCK_PATTERNS = [
		'/^### (.*)$/' => '<h3>%s</h3>',
		'/^## (.*)$/' => '<h2>%s</h2>',
		'/^# (.*)$/' => '<h1>%s</h1>',
		'/^\d+\. (.*)$/' => '<p class="list-item">%s</p>',
	];

	/**
	 * Loads the minutes and their linked meeting under the caller's ACL.
	 *
	 * @var MinutesRecordReader
	 */
	private readonly MinutesRecordReader $reader;

	/**
	 * Constructor for MinutesDocumentService.
	 *
	 * @param FilinqPdf $filinqPdf Renders a PDF through filinq when it is installed
	 * @param LoggerInterface $logger The logger
	 * @param MinutesGenerationService $generationService Draft generator (content fallback)
	 * @param MeetingFolderService $folderService Meeting Files folder writer
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly FilinqPdf $filinqPdf,
		private readonly LoggerInterface $logger,
		private readonly MinutesGenerationService $generationService,
		private readonly MeetingFolderService $folderService,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->reader = new MinutesRecordReader(objectService: $objectService, logger: $logger);
	}//end __construct()

	/**
	 * Generate a minutes document and persist it into the meeting folder.
	 *
	 * Content resolution: the minutes' own `content` when non-empty, otherwise
	 * the generated draft (which auto-inserts voting results from the voting
	 * system); the live `itemNotes` captured during the meeting are appended
	 * as a "Live notes" section either way.
	 *
	 * Format `pdf` uses Docudesk's PdfService when resolvable; when Docudesk
	 * is absent (or rendering fails) the markdown document is persisted
	 * instead and the result carries `docudesk: false` plus an honest note.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $format Requested format ('markdown' or 'pdf')
	 * @param string $displayName Display name of the requesting user (server session)
	 *
	 * @throws MissingObjectException When the Minutes object is not found
	 * @throws MissingRelationException When no Meeting is linked to the Minutes
	 * @throws InvalidArgumentException When the format is not supported
	 * @throws RuntimeException When OpenRegister or Files is unavailable
	 *
	 * @return array<string,mixed> Keys: path, format, docudesk, and optionally note.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function generate(string $minutesId, string $format, string $displayName): array {
		self::requireFormat(format: $format);

		// The reader applies OpenRegister's ACL via the register/schema context (OWASP A01).
		$minutes = $this->reader->require(minutesId: $minutesId);
		$meeting = $this->requireLinkedMeeting(minutesId: $minutesId, minutes: $minutes);
		$request = ($this->documentRequest(minutesId: $minutesId, minutes: $minutes) + ['format' => $format]);

		$document = $this->storeDocument(meeting: $meeting, request: $request);
		if ($document['path'] === null) {
			throw new RuntimeException('The minutes document could not be stored: the Files backend is unavailable.', 503);
		}

		$record = self::documentRecord(document: $document, displayName: $displayName);
		$this->recordGeneratedDocument(minutesId: $minutesId, minutes: self::withGeneratedDocument(minutes: $minutes, record: $record));

		return self::documentResult(document: $document);
	}//end generate()

	/**
	 * Refuse a format the caller may not request.
	 *
	 * @param string $format Requested format
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the format is not supported
	 */
	private static function requireFormat(string $format): void {
		if (in_array($format, self::FORMATS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unsupported format "%s". Supported: %s.', $format, implode(', ', self::FORMATS)),
				422
			);
		}
	}//end requireFormat()

	/**
	 * The meeting the minutes link to; the document is stored in its folder.
	 *
	 * @param string              $minutesId UUID of the Minutes object
	 * @param array<string,mixed> $minutes   The Minutes object data
	 *
	 * @return array<string,mixed> The meeting data
	 *
	 * @throws MissingRelationException When no Meeting is linked to the Minutes
	 */
	private function requireLinkedMeeting(string $minutesId, array $minutes): array {
		$meeting = $this->reader->linkedMeeting(
			minutes: $minutes,
			failureLog: 'Decidiq: failed to fetch linked Meeting for document generation'
		);

		if ($meeting === null) {
			throw new MissingRelationException(
				message: sprintf('No linked Meeting found for Minutes "%s". A meeting link is required to store the document.', $minutesId)
			);
		}

		return $meeting;
	}//end requireLinkedMeeting()

	/**
	 * The document to store: its body, title and file name without extension.
	 *
	 * @param string              $minutesId UUID of the Minutes object
	 * @param array<string,mixed> $minutes   The Minutes object data
	 *
	 * @return array{markdown: string, title: string, baseName: string}
	 */
	private function documentRequest(string $minutesId, array $minutes): array {
		$markdown = $this->resolveContent(minutes: $minutes, minutesId: $minutesId);
		$title = (string)($minutes['title'] ?? 'Minutes');
		$version = (int)($minutes['version'] ?? 1);
		$stamp = (new DateTimeImmutable())->format('Y-m-d Hi');

		return ['markdown' => $markdown, 'title' => $title, 'baseName' => sprintf('%s v%d %s', $title, $version, $stamp)];
	}//end documentRequest()

	/**
	 * The generated-document record kept on the Minutes object.
	 *
	 * @param array<string,mixed> $document    The stored document
	 * @param string              $displayName Display name of the requesting user
	 *
	 * @return array<string,mixed>
	 */
	private static function documentRecord(array $document, string $displayName): array {
		return [
			'path' => $document['path'],
			'format' => $document['format'],
			'generatedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			'generatedBy' => $displayName,
			'docudesk' => $document['docudesk'],
		];
	}//end documentRecord()

	/**
	 * What generate() answers: path, format, docudesk, and the note when there is one.
	 *
	 * @param array<string,mixed> $document The stored document
	 *
	 * @return array<string,mixed>
	 */
	private static function documentResult(array $document): array {
		$result = [
			'path' => $document['path'],
			'format' => $document['format'],
			'docudesk' => $document['docudesk'],
		];

		if ($document['note'] !== null) {
			$result['note'] = $document['note'];
		}

		return $result;
	}//end documentResult()

	/**
	 * Render and persist the document, degrading from PDF to markdown honestly.
	 *
	 * A PDF is attempted only when it was asked for AND Docudesk rendered bytes;
	 * whenever no PDF path was produced the markdown document is written instead.
	 *
	 * @param array<string,mixed> $meeting The linked Meeting data
	 * @param array<string,mixed> $request The markdown body, title, base file name and requested format
	 *
	 * @return array{path: string|null, format: string, docudesk: bool, note: string|null}
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function storeDocument(array $meeting, array $request): array {
		[$pdfBytes, $note] = $this->pdfAttempt(request: $request);
		$document = ['path' => null, 'format' => 'markdown', 'docudesk' => false, 'note' => $note];

		if ($pdfBytes !== null) {
			$document['path'] = $this->writeMinutesFile(meeting: $meeting, file: [$request['baseName'] . '.pdf', $pdfBytes]);
			$document['format'] = 'pdf';
			$document['docudesk'] = true;
		}

		if ($document['path'] === null) {
			$document['path'] = $this->writeMinutesFile(meeting: $meeting, file: [$request['baseName'] . '.md', $request['markdown']]);
			$document['format'] = 'markdown';
		}

		return $document;
	}//end storeDocument()

	/**
	 * The PDF bytes when a PDF was asked for and rendered, and the note when it was asked for but not rendered.
	 *
	 * @param array<string,mixed> $request The markdown body, title and requested format
	 *
	 * @return array{0: string|null, 1: string|null} [pdfBytes, note]
	 */
	private function pdfAttempt(array $request): array {
		if ($request['format'] !== 'pdf') {
			return [null, null];
		}

		$pdfBytes = $this->tryDocudeskPdf(markdown: $request['markdown'], title: $request['title']);
		if ($pdfBytes === null) {
			return [null, 'Docudesk is not available on this instance — a markdown document was produced instead of a PDF.'];
		}

		return [$pdfBytes, null];
	}//end pdfAttempt()

	/**
	 * Write one file into the meeting's Minutes folder.
	 *
	 * @param array<string,mixed>       $meeting The linked Meeting data
	 * @param array{0: string, 1: string} $file  The file name and content
	 *
	 * @return string|null The path, or null when it could not be written
	 */
	private function writeMinutesFile(array $meeting, array $file): ?string {
		return $this->folderService->writeMeetingFile(
			meeting: $meeting,
			subfolder: 'Minutes',
			fileName: $file[0],
			content: $file[1]
		);
	}//end writeMinutesFile()

	/**
	 * Resolve the document body: minutes content, draft fallback, live notes.
	 *
	 * @param array<string,mixed> $minutes The Minutes object data
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return string Markdown document body
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function resolveContent(array $minutes, string $minutesId): string {
		$content = trim((string)($minutes['content'] ?? ''));

		if ($content === '') {
			// The generated draft auto-inserts agenda, motions, voting results,
			// and decisions from the meeting data.
			$content = $this->generationService->generateDraft($minutesId);
		}

		$itemNotes = ($minutes['itemNotes'] ?? []);
		if (is_array($itemNotes) === false || $itemNotes === []) {
			return $content;
		}

		return $content . $this->itemNotesSection(itemNotes: $itemNotes);
	}//end resolveContent()

	/**
	 * The live notes section: a heading and one block per agenda-item note.
	 *
	 * @param array<mixed> $itemNotes The itemNotes entries
	 *
	 * @return string Markdown section
	 */
	private function itemNotesSection(array $itemNotes): string {
		$section = "\n\n---\n\n## Aantekeningen per agendapunt\n";
		foreach ($itemNotes as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$section .= $this->renderItemNote(entry: $entry);
		}

		return $section;
	}//end itemNotesSection()

	/**
	 * Render one agenda-item note block; empty string when it carries nothing.
	 *
	 * @param array<string,mixed> $entry One itemNotes entry
	 *
	 * @return string Markdown block (empty when notes and decisions are blank)
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function renderItemNote(array $entry): string {
		$notes = trim((string)($entry['notes'] ?? ''));
		$decisions = trim((string)($entry['decisions'] ?? ''));

		// Both parts are already trimmed, so an empty concatenation means both are blank.
		if (($notes . $decisions) === '') {
			return '';
		}

		$block = "\n### " . $this->resolveAgendaItemTitle(agendaItemId: (string)($entry['agendaItem'] ?? '')) . "\n";
		if ($notes !== '') {
			$block .= "\n" . $notes . "\n";
		}

		if ($decisions !== '') {
			$block .= "\n**Besluiten:** " . $decisions . "\n";
		}

		return $block;
	}//end renderItemNote()

	/**
	 * Resolve an agenda item's display title (fail-soft to the UUID).
	 *
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @return string Agenda item title or the UUID when unresolvable
	 */
	private function resolveAgendaItemTitle(string $agendaItemId): string {
		if ($agendaItemId === '') {
			return 'Agendapunt';
		}

		try {
			return $this->lookupAgendaItemTitle(agendaItemId: $agendaItemId);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: agenda item title lookup failed for document generation',
				['agendaItemId' => $agendaItemId, 'error' => $e->getMessage()]
			);
		}

		return $agendaItemId;
	}//end resolveAgendaItemTitle()

	/**
	 * The title, or else the name, of an agenda item; its UUID when it has neither or is missing.
	 *
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @return string
	 */
	private function lookupAgendaItemTitle(string $agendaItemId): string {
		$entity = $this->objectService->find(id: $agendaItemId, register: 'decidiq', schema: 'agenda-item');
		if ($entity === null) {
			return $agendaItemId;
		}

		$item = $entity->getObject();
		return (string)($item['title'] ?? ($item['name'] ?? $agendaItemId));
	}//end lookupAgendaItemTitle()

	/**
	 * Try to render a PDF via Docudesk; return null when Docudesk is absent
	 * or rendering fails (the caller falls back to markdown — never a stub).
	 *
	 * @param string $markdown The markdown document body
	 * @param string $title The document title (PDF metadata)
	 *
	 * @return string|null PDF binary content or null
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function tryDocudeskPdf(string $markdown, string $title): ?string {
		return $this->filinqPdf->fromHtml(
			html: $this->markdownToHtml(markdown: $markdown),
			title: $title,
			context: 'the minutes document'
		);
	}//end tryDocudeskPdf()

	/**
	 * Minimal markdown → HTML conversion for the Docudesk pathway.
	 *
	 * Covers the constructs the minutes templates emit: headings (#/##/###),
	 * bold, horizontal rules, list items, and paragraphs. Content is
	 * HTML-escaped before markup substitution so minute text can never inject
	 * markup into the rendered PDF.
	 *
	 * @param string $markdown The markdown source
	 *
	 * @return string A standalone HTML document
	 */
	private function markdownToHtml(string $markdown): string {
		$html = [];
		foreach (explode("\n", $markdown) as $line) {
			$html[] = $this->markdownLineToHtml(line: $line);
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body>'
			. implode("\n", $html)
			. '</body></html>';

	}//end markdownToHtml()

	/**
	 * Convert a single markdown line into its HTML equivalent.
	 *
	 * The line is HTML-escaped first, then inline emphasis is substituted and
	 * the block-level patterns in BLOCK_PATTERNS are tried in declaration order;
	 * anything left over becomes a horizontal rule, a blank line, or a paragraph.
	 *
	 * @param string $line One raw markdown line
	 *
	 * @return string The HTML fragment for that line
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function markdownLineToHtml(string $line): string {
		$escaped = self::inlineHtml(line: $line);

		foreach (self::BLOCK_PATTERNS as $pattern => $template) {
			if (preg_match($pattern, $escaped, $matches) === 1) {
				return sprintf($template, $matches[1]);
			}
		}

		return self::plainLineHtml(escaped: $escaped);
	}//end markdownLineToHtml()

	/**
	 * An escaped line that is no heading or list item: a rule, a blank line or a paragraph.
	 *
	 * @param string $escaped The escaped line
	 *
	 * @return string
	 */
	private static function plainLineHtml(string $escaped): string {
		$trimmed = trim($escaped);
		if ($trimmed === '---') {
			return '<hr/>';
		}

		if ($trimmed === '') {
			return '';
		}

		return '<p>' . $escaped . '</p>';
	}//end plainLineHtml()

	/**
	 * One markdown line HTML-escaped, with bold and emphasis substituted.
	 *
	 * @param string $line One raw markdown line
	 *
	 * @return string
	 */
	private static function inlineHtml(string $line): string {
		$escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
		$escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
		return preg_replace('/_(.+?)_/', '<em>$1</em>', $escaped) ?? $escaped;
	}//end inlineHtml()

	/**
	 * The Minutes object with a generated-document record appended.
	 *
	 * @param array<string,mixed> $minutes The Minutes object data
	 * @param array<string,mixed> $record  The generated-document record
	 *
	 * @return array<string,mixed>
	 */
	private static function withGeneratedDocument(array $minutes, array $record): array {
		$documents = [];
		if (is_array($minutes['generatedDocuments'] ?? null) === true) {
			$documents = $minutes['generatedDocuments'];
		}

		$documents[] = $record;
		return array_merge($minutes, ['generatedDocuments' => $documents]);
	}//end withGeneratedDocument()

	/**
	 * Save the Minutes object with its generated-document record (fail-soft).
	 *
	 * @param string              $minutesId UUID of the Minutes object
	 * @param array<string,mixed> $minutes   The Minutes object data with the record appended
	 *
	 * @return void
	 */
	private function recordGeneratedDocument(string $minutesId, array $minutes): void {
		try {
			$this->objectService->saveObject(object: $minutes, register: 'decidiq', schema: 'minutes', uuid: $minutesId);
		} catch (\Throwable $e) {
			// The document itself was persisted; a failed bookkeeping write must
			// not fail the request. Log and continue.
			$this->logger->warning(
				'Decidiq: failed to record generated document on minutes object',
				['minutesId' => $minutesId, 'error' => $e->getMessage()]
			);
		}
	}//end recordGeneratedDocument()
}//end class
