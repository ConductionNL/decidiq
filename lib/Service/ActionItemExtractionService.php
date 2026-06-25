<?php

/**
 * Decidesk Action Item Extraction Service
 *
 * Service for extracting action item candidates from minutes content using regex patterns.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that extracts action item candidates from minutes text.
 *
 * Uses regex patterns to detect Dutch action item markers (Actie:, Taak:, AI:, etc.)
 * and phrases (wordt verzocht, zal worden, is toegezegd). Returns candidates with
 * title and optional assignee suggestions for preview and confirmation.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
 */
class ActionItemExtractionService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract action item candidates from minutes content.
     *
     * Splits content into lines and applies regex patterns to detect markers
     * (Actie:, AI:, Taak:, Actiepunt:) and phrases (wordt verzocht, zal worden,
     * is toegezegd). For each match, extracts title and attempts to match a
     * known participant name.
     *
     * @param string $content           Minutes content text
     * @param array  $knownParticipants Optional list of known participant names
     *
     * @return array<array{
     *   title: string,
     *   suggestedAssignee: string|null
     * }> Array of candidate objects
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.1
     */
    public function extractFromContent(string $content, array $knownParticipants=[]): array
    {
        $candidates = [];
        $lines      = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) === true) {
                continue;
            }

            // Pattern 1: Marker at start (Actie:, AI:, Taak:, Actiepunt:).
            if (preg_match('/^(Actie|AI|Taak|Actiepunt|Action|Task):\s*(.+)$/i', $line, $matches) === 1) {
                $title    = trim($matches[2]);
                $assignee = $this->extractAssigneeFromLine(line: $line, knownParticipants: $knownParticipants);

                $candidates[] = [
                    'title'             => $title,
                    'suggestedAssignee' => $assignee,
                ];
                continue;
            }

            // Pattern 2: Dutch action phrases.
            if (preg_match('/(wordt verzocht|zal worden|is toegezegd|dient te|moet)/i', $line) === 1) {
                // Extract a reasonable title from the line.
                $title = $this->extractTitleFromPhrase(line: $line);
                if (empty($title) === false) {
                    $assignee = $this->extractAssigneeFromLine(line: $line, knownParticipants: $knownParticipants);

                    $candidates[] = [
                        'title'             => $title,
                        'suggestedAssignee' => $assignee,
                    ];
                }
            }
        }//end foreach

        return $candidates;
    }//end extractFromContent()

    /**
     * Save extracted action items after user confirmation.
     *
     * For each confirmed candidate, creates an ActionItem via ObjectService.saveObject(),
     * links to the Minutes via relation, and returns the count of saved items.
     *
     * @param string $minutesId The Minutes ID
     * @param array  $confirmed Confirmed candidates: [ { title, assignee?, dueDate? }, ... ]
     *
     * @return int The count of saved ActionItems
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.1
     */
    public function saveExtracted(string $minutesId, array $confirmed): int
    {
        try {
            // Action items are CalDAV VTODOs (ADR-002); the action-item schema is a
            // read-only projection, so write via ActionItemWriter (TaskService) rather
            // than ObjectService::saveObject.
            $writer     = $this->container->get(\OCA\Decidesk\Service\ActionItemWriter::class);
            $savedCount = 0;

            foreach ($confirmed as $candidate) {
                $title = $candidate['title'] ?? null;
                if (empty($title) === true) {
                    continue;
                }

                $actionItem = [
                    'title'      => $title,
                    'taskStatus' => 'open',
                    'relations'  => [
                        'Minutes' => [$minutesId],
                    ],
                ];

                if (empty($candidate['assignee']) === false) {
                    $actionItem['assignee'] = $candidate['assignee'];
                }

                if (empty($candidate['dueDate']) === false) {
                    // Accept only ISO-8601 date strings to prevent strtotime relative-expression injection
                    // (e.g. "yesterday", "next Monday") that produce silently wrong deadline values.
                    $isoDatePattern = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?Z?)?$/';
                    $isValidDate    = preg_match($isoDatePattern, (string) $candidate['dueDate']) === 1;
                    if ($isValidDate === false) {
                        $this->logger->warning(
                            'ActionItemExtractionService: rejected non-ISO-8601 dueDate',
                            ['dueDate' => $candidate['dueDate']]
                        );
                    }

                    if ($isValidDate === true) {
                        $actionItem['dueDate'] = $candidate['dueDate'];
                    }
                }

                try {
                    $created = $writer->create(item: $actionItem);
                    if ($created !== null) {
                        $savedCount++;
                    } else {
                        $this->logger->warning('Failed to save ActionItem VTODO for minutes '.$minutesId);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to save ActionItem: ".$e->getMessage());
                }
            }//end foreach

            $this->logger->info("Saved $savedCount extracted action items for minutes $minutesId");

            return $savedCount;
        } catch (\Exception $e) {
            $this->logger->error("ActionItemExtractionService::saveExtracted failed: ".$e->getMessage());
            return 0;
        }//end try
    }//end saveExtracted()

    /**
     * Extract a suggested assignee name from the line text.
     *
     * Searches for known participant names within the line; returns the first match.
     *
     * @param string $line              The line of text
     * @param array  $knownParticipants List of known participant names
     *
     * @return string|null The matched participant name, or null
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.1
     */
    private function extractAssigneeFromLine(string $line, array $knownParticipants): ?string
    {
        foreach ($knownParticipants as $name) {
            if (stripos($line, $name) !== false) {
                return $name;
            }
        }

        return null;
    }//end extractAssigneeFromLine()

    /**
     * Extract a title from a line containing an action phrase.
     *
     * Takes the entire line (or a truncated version) as the title if it's reasonable length.
     *
     * @param string $line The line containing an action phrase
     *
     * @return string The extracted title
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.1
     */
    private function extractTitleFromPhrase(string $line): string
    {
        // Return the full line if it's a reasonable length (< 150 chars).
        if (strlen($line) <= 150) {
            return $line;
        }

        // Otherwise truncate to first sentence or 100 chars.
        $pos = strpos($line, '.');
        if ($pos !== false && $pos < 100) {
            return substr($line, 0, $pos);
        }

        return substr($line, 0, 100).'...';
    }//end extractTitleFromPhrase()
}//end class
