<?php

/**
 * Decidiq Consultation Response Document Service
 *
 * Writes the works council's formal advice or consent on a WOR traject as a
 * document, stores it on the traject (its Files leaf), records it as a
 * DigitalDocument and links that as the traject's `responseDocument`. Same
 * posture as MinutesDocumentService: markdown is canonical, a PDF is
 * produced through filinq when that app can render one, and otherwise the
 * markdown is stored and the answer says so; never a fake PDF.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ConsultationResponseRefusedException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generates the formal advice or consent document of a works-council consultation.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 */
class ConsultationResponseDocumentService {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schema slug of a traject.
	 *
	 * @var string
	 */
	public const SCHEMA = 'consultation-request';

	/**
	 * Formats the caller may request.
	 *
	 * @var list<string>
	 */
	public const FORMATS = ['markdown', 'pdf'];

	/**
	 * The states in which the response is adopted, so a document can be written.
	 *
	 * @var list<string>
	 */
	public const ADOPTED_STATES = ['adopted', 'sent', 'decision-received', 'completed'];

	/**
	 * The response outcomes, as the document names them.
	 *
	 * @var array<string, string>
	 */
	private const OUTCOMES = [
		'positive-advice' => 'Positive advice',
		'advice-with-conditions' => 'Advice with conditions',
		'negative-advice' => 'Negative advice',
		'consent-granted' => 'Consent granted',
		'consent-refused' => 'Consent refused',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the traject, writes the document record and the link.
	 * @param FileService            $fileService   Stores the file on the traject.
	 * @param FilinqPdf              $filinqPdf     Renders a PDF when filinq is installed.
	 * @param IL10N                  $l10n          The document's wording.
	 * @param LoggerInterface        $logger        Records a failed write.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly FileService $fileService,
		private readonly FilinqPdf $filinqPdf,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The traject, read under the caller's access rules.
	 *
	 * @param string $id The traject's uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ConsultationResponseRefusedException When there is no such traject the caller may read.
	 *
	 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
	 */
	public function traject(string $id): array {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA);
		} catch (Throwable) {
			$entity = null;
		}

		if ($entity === null) {
			throw new ConsultationResponseRefusedException(message: 'This consultation does not exist.', status: 404);
		}

		return $entity->getObject();
	}//end traject()

	/**
	 * Write the response document, store it on the traject and link it.
	 *
	 * @param string $id     The traject's uuid.
	 * @param string $format `markdown` or `pdf`.
	 *
	 * @return array{path: string, format: string, docudesk: bool, responseDocument: string, note?: string}
	 *
	 * @throws ConsultationResponseRefusedException When the format is unknown, the response is not adopted or the file cannot be stored.
	 *
	 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
	 */
	public function generate(string $id, string $format): array {
		if (in_array($format, self::FORMATS, true) === false) {
			throw new ConsultationResponseRefusedException(message: 'A response document is written as markdown or pdf.', status: 422);
		}

		$traject = $this->traject(id: $id);
		self::requireAdopted(traject: $traject);

		$markdown = $this->markdown(traject: $traject);
		$file = $this->store(id: $id, name: $this->baseName(traject: $traject), markdown: $markdown, format: $format);
		$documentId = $this->record(traject: $traject, file: $file);
		$this->link(id: $id, traject: $traject, documentId: $documentId);

		$result = ['path' => $file['path'], 'format' => $file['format'], 'docudesk' => $file['docudesk'], 'responseDocument' => $documentId];
		if ($format === 'pdf' && $file['docudesk'] === false) {
			$result['note'] = 'filinq is not available on this instance: a markdown document was stored instead of a PDF.';
		}

		return $result;
	}//end generate()

	/**
	 * Refuse a traject whose response is not adopted.
	 *
	 * @param array<string, mixed> $traject The traject.
	 *
	 * @return void
	 *
	 * @throws ConsultationResponseRefusedException When it is not adopted or carries no outcome.
	 */
	private static function requireAdopted(array $traject): void {
		if (in_array(($traject['lifecycle'] ?? ''), self::ADOPTED_STATES, true) === false) {
			throw new ConsultationResponseRefusedException(message: 'The response is written once the works council has adopted it.');
		}

		if (isset(self::OUTCOMES[(string)($traject['responseOutcome'] ?? '')]) === false) {
			throw new ConsultationResponseRefusedException(message: 'Record the response outcome before writing the document.');
		}
	}//end requireAdopted()

	/**
	 * The document body.
	 *
	 * @param array<string, mixed> $traject The traject.
	 *
	 * @return string Markdown.
	 *
	 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
	 */
	public function markdown(array $traject): string {
		$heading = $this->l10n->t('Consent of the works council');
		if (($traject['type'] ?? '') === 'adviceRequest') {
			$heading = $this->l10n->t('Advice of the works council');
		}

		$lines = [
			'# ' . $heading,
			'',
			'**' . $this->l10n->t('Subject') . ':** ' . (string)($traject['subject'] ?? ''),
			'**' . $this->l10n->t('WOR article') . ':** ' . (string)($traject['worArticle'] ?? ''),
			'**' . $this->l10n->t('Request received') . ':** ' . (string)($traject['receivedDate'] ?? ''),
			'**' . $this->l10n->t('Response date') . ':** ' . (string)($traject['responseDate'] ?? ''),
			'',
			'## ' . $this->l10n->t(self::OUTCOMES[(string)$traject['responseOutcome']]),
			'',
			trim((string)($traject['responseText'] ?? '')),
		];

		return implode("\n", $lines) . "\n";
	}//end markdown()

	/**
	 * The file name without extension.
	 *
	 * @param array<string, mixed> $traject The traject.
	 *
	 * @return string
	 */
	private function baseName(array $traject): string {
		$name = $this->l10n->t('Consent');
		if (($traject['type'] ?? '') === 'adviceRequest') {
			$name = $this->l10n->t('Advice');
		}

		$subject = (string)preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string)($traject['subject'] ?? ''));
		$name    = $name . ' ' . mb_substr($subject, 0, 80) . ' ' . (string)($traject['responseDate'] ?? '');

		return trim((string)preg_replace('/\s+/', ' ', $name));
	}//end baseName()

	/**
	 * Store the document on the traject: a PDF when one was asked for and rendered, else the markdown.
	 *
	 * @param string $id       The traject's uuid.
	 * @param string $name     The file name without extension.
	 * @param string $markdown The body.
	 * @param string $format   The requested format.
	 *
	 * @return array{path: string, format: string, docudesk: bool, fileId: int|null, fileName: string, mime: string}
	 *
	 * @throws ConsultationResponseRefusedException When the Files backend cannot store it.
	 */
	private function store(string $id, string $name, string $markdown, string $format): array {
		$pdf = null;
		if ($format === 'pdf') {
			$pdf = $this->filinqPdf->fromHtml(html: self::html(markdown: $markdown), title: $name, context: 'the works-council response document');
		}

		$file = ['fileName' => $name . '.md', 'content' => $markdown, 'format' => 'markdown', 'docudesk' => false, 'mime' => 'text/markdown'];
		if ($pdf !== null) {
			$file = ['fileName' => $name . '.pdf', 'content' => $pdf, 'format' => 'pdf', 'docudesk' => true, 'mime' => 'application/pdf'];
		}

		try {
			$node = $this->fileService->addFile(objectEntity: $id, fileName: $file['fileName'], content: $file['content']);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: the works-council response document could not be stored', ['consultation' => $id, 'error' => $e->getMessage()]);
			throw new ConsultationResponseRefusedException(
				message: 'The response document could not be stored: the Files backend is unavailable.',
				status: 503
			);
		}

		unset($file['content']);

		return $file + ['path' => (string)$node->getPath(), 'fileId' => $node->getId()];
	}//end store()

	/**
	 * Record the stored file as a DigitalDocument.
	 *
	 * @param array<string, mixed>                                      $traject The traject.
	 * @param array{fileName: string, mime: string, fileId: int|null} $file    The stored file.
	 *
	 * @return string The document record's uuid.
	 */
	private function record(array $traject, array $file): string {
		$document = [
			'name' => $file['fileName'],
			'documentType' => 'works-council-response',
			'description' => (string)($traject['subject'] ?? ''),
			'encodingFormat' => $file['mime'],
		];
		if ($file['fileId'] !== null) {
			$document['fileId'] = (int)$file['fileId'];
		}

		$saved = $this->objectService->saveObject(object: $document, register: self::REGISTER, schema: 'digital-document');

		return (string)$saved->getUuid();
	}//end record()

	/**
	 * Link the document on the traject, every other field carried forward.
	 *
	 * @param string               $id         The traject's uuid.
	 * @param array<string, mixed> $traject    The traject as read.
	 * @param string               $documentId The document record.
	 *
	 * @return void
	 */
	private function link(string $id, array $traject, string $documentId): void {
		unset($traject['@self'], $traject['id']);
		$traject['responseDocument'] = $documentId;
		$this->objectService->saveObject(object: $traject, register: self::REGISTER, schema: self::SCHEMA, uuid: $id);
	}//end link()

	/**
	 * The body as HTML for the PDF renderer, escaped before any markup.
	 *
	 * @param string $markdown The body.
	 *
	 * @return string A standalone HTML document.
	 */
	private static function html(string $markdown): string {
		$html = [];
		foreach (explode("\n", $markdown) as $line) {
			$escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
			$escaped = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
			$html[]  = match (true) {
				str_starts_with($escaped, '## ') => '<h2>' . substr($escaped, 3) . '</h2>',
				str_starts_with($escaped, '# ') => '<h1>' . substr($escaped, 2) . '</h1>',
				trim($escaped) === '' => '',
				default => '<p>' . $escaped . '</p>',
			};
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body>' . implode("\n", $html) . '</body></html>';
	}//end html()
}//end class
