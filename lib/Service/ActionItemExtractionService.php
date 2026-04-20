<?php

/**
 * Decidesk Action Item Extraction Service
 *
 * Service for extracting action items from minutes content.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for extracting action item candidates from minutes content using regex patterns.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
 */
class ActionItemExtractionService
{
    /**
     * Regex patterns for detecting action items in Dutch minutes.
     *
     * @var string[]
     */
    private const PATTERNS = [
        '/^(Actie|AI|Taak|Actiepunt):\s*(.+?)$/im',
        '/wordt verzocht\s+(.+?)(?=\.|,|$)/i',
        '/zal worden\s+(.+?)(?=\.|,|$)/i',
        '/is toegezegd\s+(.+?)(?=\.|,|$)/i',
    ];

    /**
     * Constructor for ActionItemExtractionService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract action item candidates from minutes content.
     *
     * @param string               $content           The minutes content
     * @param array<string,string> $knownParticipants Array of participant names (optional)
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
     *
     * @return array<int, array<string, string|null>>
     */
    public function extractFromContent(string $content, array $knownParticipants=[]): array
    {
        $candidates = [];
        $seenTitles = [];

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $matchCount = count($matches[0]);

                for ($i = 0; $i < $matchCount; $i++) {
                    // Extract the text after the marker (handle different pattern groups).
                    $text = $matches[2][$i] ?? $matches[1][$i] ?? '';
                    $text = trim($text);

                    if (empty($text) || strlen($text) < 3) {
                        continue;
                    }

                    // Remove duplicates.
                    if (isset($seenTitles[$text])) {
                        continue;
                    }

                    $seenTitles[$text] = true;

                    // Try to detect an assignee name from the text.
                    $suggestedAssignee = $this->detectAssignee($text, $knownParticipants);

                    $candidates[] = [
                        'title'             => $text,
                        'suggestedAssignee' => $suggestedAssignee,
                    ];
                }//end for
            }//end if
        }//end foreach

        return $candidates;
    }//end extractFromContent()

    /**
     * Save extracted and confirmed action items.
     *
     * @param string            $minutesId The UUID of the Minutes object
     * @param array<int, array> $confirmed Array of confirmed candidates with title, assignee, dueDate
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
     *
     * @return int Count of saved ActionItems
     */
    public function saveExtracted(string $minutesId, array $confirmed): int
    {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $savedCount = 0;

            foreach ($confirmed as $item) {
                $title = $item['title'] ?? '';
                if (empty($title)) {
                    continue;
                }

                $actionItemPayload = [
                    '@self'      => [
                        'register' => 'decidesk',
                        'schema'   => 'ActionItem',
                    ],
                    'title'      => $title,
                    'taskStatus' => 'open',
                    'minutes'    => $minutesId,
                ];

                if (!empty($item['assignee'])) {
                    $actionItemPayload['assignee'] = $item['assignee'];
                }

                if (!empty($item['dueDate'])) {
                    $actionItemPayload['dueDate'] = $item['dueDate'];
                }

                try {
                    $objectService->saveObject(
                        object: $actionItemPayload,
                        register: 'decidesk',
                        schema: 'ActionItem'
                    );
                    $savedCount++;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Decidesk: failed to save extracted action item',
                        ['title' => $title, 'exception' => $e->getMessage()]
                    );
                }
            }//end foreach

            $this->logger->info(
                'Decidesk: extracted action items saved',
                ['minutesId' => $minutesId, 'count' => $savedCount]
            );

            return $savedCount;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to save extracted action items',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end saveExtracted()

    /**
     * Detect a suggested assignee from the action item text.
     *
     * Attempts to match names from the known participants list against the text.
     *
     * @param string               $text              The action item text
     * @param array<string,string> $knownParticipants Known participant names
     *
     * @return string|null The suggested assignee name or null
     */
    private function detectAssignee(string $text, array $knownParticipants=[]): ?string
    {
        if (empty($knownParticipants)) {
            return null;
        }

        foreach ($knownParticipants as $name) {
            if (stripos($text, $name) !== false) {
                return $name;
            }
        }

        return null;
    }//end detectAssignee()
}//end class
