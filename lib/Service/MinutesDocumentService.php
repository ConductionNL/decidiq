<?php
/**
 * Decidesk Minutes Document Service
 *
 * Renders the minutes content into a formatted document and persists it into
 * the linked meeting's Files folder ('Minutes' subfolder). PDF rendering is
 * delegated to Docudesk when (and only when) its PdfService is resolvable
 * from the container; otherwise the plain markdown document is produced and
 * the response says so honestly.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates and persists minutes documents (markdown canonical, Docudesk PDF
 * opportunistic) into the meeting's Files folder.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesDocumentService
{

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
     * Constructor for MinutesDocumentService.
     *
     * @param ContainerInterface       $container         DI container (lazy ObjectService / Docudesk lookup)
     * @param LoggerInterface          $logger            The logger
     * @param MinutesGenerationService $generationService Draft generator (content fallback)
     * @param MeetingFolderService     $folderService     Meeting Files folder writer
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly MinutesGenerationService $generationService,
        private readonly MeetingFolderService $folderService,
    ) {
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
     * @param string $minutesId   UUID of the Minutes object
     * @param string $format      Requested format ('markdown' or 'pdf')
     * @param string $displayName Display name of the requesting user (server session)
     *
     * @throws MissingObjectException    When the Minutes object is not found
     * @throws MissingRelationException  When no Meeting is linked to the Minutes
     * @throws InvalidArgumentException When the format is not supported
     * @throws RuntimeException         When OpenRegister or Files is unavailable
     *
     * @return array<string,mixed> { path, format, docudesk, note? }
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    public function generate(string $minutesId, string $format, string $displayName): array
    {
        if (in_array($format, self::FORMATS, true) === false) {
            throw new InvalidArgumentException(
                sprintf('Unsupported format "%s". Supported: %s.', $format, implode(', ', self::FORMATS)),
                422
            );
        }

        $objectService = $this->getObjectService();

        // ACL applies via session-scoped register/schema context (OWASP A01).
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            throw new MissingObjectException(
                message: sprintf('Minutes object "%s" not found.', $minutesId)
            );
        }

        $minutes = $minutesEntity->getObject();
        $meeting = $this->resolveMeeting(minutes: $minutes, objectService: $objectService);

        if ($meeting === null) {
            throw new MissingRelationException(
                message: sprintf(
                    'No linked Meeting found for Minutes "%s". A meeting link is required to store the document.',
                    $minutesId
                )
            );
        }

        $markdown = $this->resolveContent(minutes: $minutes, minutesId: $minutesId, objectService: $objectService);

        $title    = (string) ($minutes['title'] ?? 'Minutes');
        $version  = (int) ($minutes['version'] ?? 1);
        $stamp    = (new DateTimeImmutable())->format('Y-m-d Hi');
        $baseName = sprintf('%s v%d %s', $title, $version, $stamp);

        $produced = 'markdown';
        $note     = null;
        $docudesk = false;
        $path     = null;

        if ($format === 'pdf') {
            $pdfBytes = $this->tryDocudeskPdf(markdown: $markdown, title: $title);
            if ($pdfBytes !== null) {
                $path     = $this->folderService->writeMeetingFile(
                    meeting: $meeting,
                    subfolder: 'Minutes',
                    fileName: $baseName.'.pdf',
                    content: $pdfBytes
                );
                $produced = 'pdf';
                $docudesk = true;
            } else {
                $note = 'Docudesk is not available on this instance — a markdown document was produced instead of a PDF.';
            }
        }

        if ($path === null) {
            $path     = $this->folderService->writeMeetingFile(
                meeting: $meeting,
                subfolder: 'Minutes',
                fileName: $baseName.'.md',
                content: $markdown
            );
            $produced = 'markdown';
        }

        if ($path === null) {
            throw new RuntimeException(
                'The minutes document could not be stored: the Files backend is unavailable.',
                503
            );
        }

        $record = [
            'path'        => $path,
            'format'      => $produced,
            'generatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'generatedBy' => $displayName,
            'docudesk'    => $docudesk,
        ];

        $this->appendGeneratedDocument(
            minutes: $minutes,
            minutesId: $minutesId,
            record: $record,
            objectService: $objectService
        );

        $result = [
            'path'     => $path,
            'format'   => $produced,
            'docudesk' => $docudesk,
        ];

        if ($note !== null) {
            $result['note'] = $note;
        }

        return $result;

    }//end generate()

    /**
     * Resolve the document body: minutes content, draft fallback, live notes.
     *
     * @param array<string,mixed> $minutes       The Minutes object data
     * @param string              $minutesId     UUID of the Minutes object
     * @param object              $objectService OpenRegister ObjectService
     *
     * @return string Markdown document body
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    private function resolveContent(array $minutes, string $minutesId, object $objectService): string
    {
        $content = trim((string) ($minutes['content'] ?? ''));

        if ($content === '') {
            // The generated draft auto-inserts agenda, motions, voting results,
            // and decisions from the meeting data.
            $content = $this->generationService->generateDraft($minutesId);
        }

        $itemNotes = ($minutes['itemNotes'] ?? []);
        if (is_array($itemNotes) === true && count($itemNotes) > 0) {
            $content .= "\n\n---\n\n## Aantekeningen per agendapunt\n";
            foreach ($itemNotes as $entry) {
                if (is_array($entry) === false) {
                    continue;
                }

                $agendaItemId = (string) ($entry['agendaItem'] ?? '');
                $notes        = trim((string) ($entry['notes'] ?? ''));
                $decisions    = trim((string) ($entry['decisions'] ?? ''));

                if ($notes === '' && $decisions === '') {
                    continue;
                }

                $itemTitle = $this->resolveAgendaItemTitle(agendaItemId: $agendaItemId, objectService: $objectService);
                $content  .= "\n### ".$itemTitle."\n";
                if ($notes !== '') {
                    $content .= "\n".$notes."\n";
                }

                if ($decisions !== '') {
                    $content .= "\n**Besluiten:** ".$decisions."\n";
                }
            }//end foreach
        }//end if

        return $content;

    }//end resolveContent()

    /**
     * Resolve an agenda item's display title (fail-soft to the UUID).
     *
     * @param string $agendaItemId  UUID of the agenda item
     * @param object $objectService OpenRegister ObjectService
     *
     * @return string Agenda item title or the UUID when unresolvable
     */
    private function resolveAgendaItemTitle(string $agendaItemId, object $objectService): string
    {
        if ($agendaItemId === '') {
            return 'Agendapunt';
        }

        try {
            $entity = $objectService->find(id: $agendaItemId, register: 'decidesk', schema: 'agenda-item');
            if ($entity !== null) {
                $item = $entity->getObject();
                return (string) ($item['title'] ?? ($item['name'] ?? $agendaItemId));
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: agenda item title lookup failed for document generation',
                ['agendaItemId' => $agendaItemId, 'error' => $e->getMessage()]
            );
        }

        return $agendaItemId;

    }//end resolveAgendaItemTitle()

    /**
     * Try to render a PDF via Docudesk; return null when Docudesk is absent
     * or rendering fails (the caller falls back to markdown — never a stub).
     *
     * @param string $markdown The markdown document body
     * @param string $title    The document title (PDF metadata)
     *
     * @return string|null PDF binary content or null
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    private function tryDocudeskPdf(string $markdown, string $title): ?string
    {
        try {
            $pdfService = $this->container->get('OCA\DocuDesk\Service\PdfService');
            $html       = $this->markdownToHtml(markdown: $markdown);
            $pdf        = $pdfService->generatePdfFromHtml($html, ['title' => $title]);
            if (is_string($pdf) === true && $pdf !== '') {
                return $pdf;
            }
        } catch (\Throwable $e) {
            $this->logger->info(
                'Decidesk: Docudesk PDF pathway unavailable, falling back to markdown',
                ['error' => $e->getMessage()]
            );
        }

        return null;

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
    private function markdownToHtml(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $html  = [];

        foreach ($lines as $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
            $escaped = preg_replace('/_(.+?)_/', '<em>$1</em>', $escaped) ?? $escaped;

            if (preg_match('/^### (.*)$/', $escaped, $m) === 1) {
                $html[] = '<h3>'.$m[1].'</h3>';
            } else if (preg_match('/^## (.*)$/', $escaped, $m) === 1) {
                $html[] = '<h2>'.$m[1].'</h2>';
            } else if (preg_match('/^# (.*)$/', $escaped, $m) === 1) {
                $html[] = '<h1>'.$m[1].'</h1>';
            } else if (trim($escaped) === '---') {
                $html[] = '<hr/>';
            } else if (preg_match('/^\d+\. (.*)$/', $escaped, $m) === 1) {
                $html[] = '<p class="list-item">'.$m[1].'</p>';
            } else if (trim($escaped) === '') {
                $html[] = '';
            } else {
                $html[] = '<p>'.$escaped.'</p>';
            }
        }//end foreach

        return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body>'
            .implode("\n", $html)
            .'</body></html>';

    }//end markdownToHtml()

    /**
     * Append a generated-document record on the Minutes object (fail-soft).
     *
     * @param array<string,mixed> $minutes       The Minutes object data
     * @param string              $minutesId     UUID of the Minutes object
     * @param array<string,mixed> $record        The generated-document record
     * @param object              $objectService OpenRegister ObjectService
     *
     * @return void
     */
    private function appendGeneratedDocument(array $minutes, string $minutesId, array $record, object $objectService): void
    {
        try {
            $documents = [];
            if (is_array($minutes['generatedDocuments'] ?? null) === true) {
                $documents = $minutes['generatedDocuments'];
            }

            $documents[] = $record;
            $updated     = array_merge($minutes, ['generatedDocuments' => $documents]);

            $objectService->saveObject(object: $updated, register: 'decidesk', schema: 'minutes', uuid: $minutesId);
        } catch (\Throwable $e) {
            // The document itself was persisted; a failed bookkeeping write must
            // not fail the request. Log and continue.
            $this->logger->warning(
                'Decidesk: failed to record generated document on minutes object',
                ['minutesId' => $minutesId, 'error' => $e->getMessage()]
            );
        }

    }//end appendGeneratedDocument()

    /**
     * Resolve the linked Meeting payload from the Minutes object.
     *
     * @param array<string,mixed> $minutes       The Minutes object data
     * @param object              $objectService OpenRegister ObjectService
     *
     * @return array<string,mixed>|null The Meeting data array or null
     */
    private function resolveMeeting(array $minutes, object $objectService): ?array
    {
        $meetingRelation = $minutes['relations']['meeting'] ?? $minutes['meeting'] ?? null;

        if ($meetingRelation === null) {
            return null;
        }

        if (is_array($meetingRelation) === true) {
            if (isset($meetingRelation['id']) === true && $meetingRelation['id'] !== '') {
                return $meetingRelation;
            }

            return null;
        }

        $meetingId = (string) $meetingRelation;
        if ($meetingId === '') {
            return null;
        }

        try {
            $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
            if ($meetingEntity === null) {
                return null;
            }

            return $meetingEntity->getObject();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to fetch linked Meeting for document generation',
                ['meetingId' => $meetingId, 'error' => $e->getMessage()]
            );
            throw new RuntimeException(
                'OpenRegister service is temporarily unavailable. Please try again later.',
                503,
                $e
            );
        }//end try

    }//end resolveMeeting()

    /**
     * Lazy-load the OpenRegister ObjectService from the container.
     *
     * @throws RuntimeException When OpenRegister is not installed
     *
     * @return object The OpenRegister ObjectService instance
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'OpenRegister ObjectService is not available. '
                .'Please ensure the OpenRegister app is installed and enabled.',
                0,
                $e
            );
        }

    }//end getObjectService()
}//end class
