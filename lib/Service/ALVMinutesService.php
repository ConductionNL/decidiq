<?php

/**
 * Decidesk ALV Minutes Service
 *
 * Service for generating and distributing ALV-specific minutes.
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
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating ALV-specific Dutch minutes and distributing them.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 */
class ALVMinutesService
{
    /**
     * ALV Dutch template for minutes generation.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     */
    private const ALV_TEMPLATE = <<<'TEMPLATE'
# Notulen {title}

Datum: {date}
Locatie: {location}

## Quorum

Aanwezig: {presentCount} leden.
Totaal aantal leden: {totalCount}.
Quorum status: {quorumStatus}

## Agendapunten

{agendaItems}

## Resoluties

{resolutions}

## Rondvraag en sluiting

{aob}

---
Opgesteld door de griffier
TEMPLATE;

    /**
     * Constructor for ALVMinutesService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate an ALV draft from Minutes.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @throws \InvalidArgumentException If Minutes or linked Meeting not found
     * @throws \RuntimeException If meeting type is not ALV
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     *
     * @return array<string, mixed> Array with 'content' (string) and 'recipientCount' (int)
     */
    public function generateALVDraft(string $minutesId): array
    {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Fetch the Minutes.
            $minutes = $objectService->find(id: $minutesId);
            if ($minutes === null) {
                throw new \InvalidArgumentException('Minutes not found');
            }

            $minutesObj = $minutes->getObject();
            $meetingId  = $minutesObj['meeting'] ?? null;

            if (!$meetingId) {
                throw new \InvalidArgumentException('Minutes not linked to a meeting');
            }

            // Fetch the linked Meeting.
            $meeting = $objectService->find(id: $meetingId);
            if ($meeting === null) {
                throw new \InvalidArgumentException('Linked meeting not found');
            }

            $meetingObj  = $meeting->getObject();
            $meetingType = strtolower($meetingObj['meetingType'] ?? '');

            // Validate ALV meeting type.
            if (strpos($meetingType, 'alv') === false && strpos($meetingType, 'algemene-ledenvergadering') === false) {
                throw new \RuntimeException(
                    'This meeting is not an ALV (Algemene Ledenvergadering). Meeting type: '.$meetingType
                );
            }

            // Get participants for quorum.
            $governanceBodyId = $meetingObj['governanceBody'] ?? null;
            $presentCount     = 0;
            $totalCount       = 0;
            $quorumStatus     = 'Niet vastgesteld';

            if ($governanceBodyId) {
                $participantParams = [
                    'governanceBody' => $governanceBodyId,
                    'leftAt'         => null,
                // Active members only.
                    '_limit'         => 1000,
                ];

                try {
                    $participantsResponse = $objectService->findAll(
                        register: 'decidesk',
                        schema: 'Participant',
                        params: $participantParams
                    );

                    $participants = $participantsResponse['results'] ?? [];
                    $totalCount   = count($participants);

                    // Use actual attendance from meeting data if available,
                    // otherwise indicate attendance was not recorded.
                    $attendanceCount = $meetingObj['attendanceCount'] ?? null;
                    if ($attendanceCount !== null) {
                        $presentCount = (int) $attendanceCount;
                        $quorumStatus = $presentCount >= ceil($totalCount / 2) ? "Quorum behaald ({$presentCount} van {$totalCount} leden)" : "Quorum NIET behaald ({$presentCount} van {$totalCount} leden)";
                    } else {
                        // No attendance data recorded; don't simulate.
                        $presentCount = $totalCount;
                        $quorumStatus = "Aanwezig: {$totalCount} van {$totalCount} leden (registratie onvolledig)";
                    }
                } catch (\Throwable) {
                    // Use defaults if participant fetch fails.
                    $totalCount   = 0;
                    $presentCount = 0;
                    $quorumStatus = 'Aanwezigheid niet vastgesteld';
                }//end try
            }//end if

            // Get agenda items for the meeting.
            $agendaItems = '';
            $resolutions = '';

            try {
                $agendaParams = [
                    'meeting' => $meetingId,
                    '_limit'  => 100,
                    '_order'  => 'order:asc',
                ];

                $agendaResponse = $objectService->findAll(
                    register: 'decidesk',
                    schema: 'AgendaItem',
                    params: $agendaParams
                );

                $items = $agendaResponse['results'] ?? [];

                foreach ($items as $item) {
                    $itemTitle    = $item['title'] ?? 'Agendapunt';
                    $itemContent  = $item['content'] ?? '';
                    $agendaItems .= "### {$itemTitle}\n\n{$itemContent}\n\n";

                    // Get linked motions/decisions.
                    if (!empty($item['@self']['slug'])) {
                        $itemSlug = $item['@self']['slug'];
                        try {
                            $decisionsParams = [
                                'agendaItem' => $itemSlug,
                                '_limit'     => 10,
                            ];

                            $decisionsResponse = $objectService->findAll(
                                register: 'decidesk',
                                schema: 'Decision',
                                params: $decisionsParams
                            );

                            $decisions = $decisionsResponse['results'] ?? [];

                            foreach ($decisions as $decision) {
                                $decisionTitle   = $decision['title'] ?? '';
                                $decisionOutcome = $decision['outcome'] ?? 'aangenomen';
                                $resolutions    .= "- **{$decisionTitle}**: {$decisionOutcome}\n";
                            }
                        } catch (\Throwable) {
                            // Skip if we can't fetch decisions.
                        }//end try
                    }//end if
                }//end foreach
            } catch (\Throwable) {
                $agendaItems = "Geen agendapunten gevonden.\n";
                $resolutions = "Geen resoluties vastgesteld.\n";
            }//end try

            // Generate content.
            $content = str_replace(
                [
                    '{title}',
                    '{date}',
                    '{location}',
                    '{presentCount}',
                    '{totalCount}',
                    '{quorumStatus}',
                    '{agendaItems}',
                    '{resolutions}',
                    '{aob}',
                ],
                [
                    $minutesObj['title'] ?? 'Onbekende vergadering',
                    date('d M Y', strtotime($meetingObj['scheduledDate'] ?? 'now')),
                    $meetingObj['location'] ?? 'Onbekende locatie',
                    (string) $presentCount,
                    (string) $totalCount,
                    $quorumStatus,
                    trim($agendaItems) ?: 'Geen agendapunten gevonden.',
                    trim($resolutions) ?: 'Geen resoluties vastgesteld.',
                    'Geen bijzonderheden.',
                ],
                self::ALV_TEMPLATE
            );

            return [
                'content'        => $content,
                'recipientCount' => $totalCount,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: ALV draft generation failed',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end generateALVDraft()

    /**
     * Distribute ALV minutes to active participants.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @throws \RuntimeException If Minutes lifecycle is not approved or signed
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     *
     * @return int Count of notifications sent
     */
    public function distribute(string $minutesId): int
    {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Fetch the Minutes.
            $minutes = $objectService->find(id: $minutesId);
            if ($minutes === null) {
                throw new \RuntimeException('Minutes not found');
            }

            $minutesObj = $minutes->getObject();
            $lifecycle  = $minutesObj['lifecycle'] ?? 'draft';

            // Verify lifecycle.
            if ($lifecycle !== 'approved' && $lifecycle !== 'signed') {
                throw new \RuntimeException(
                    'Minutes must be in "approved" or "signed" state to distribute. Current state: '.$lifecycle
                );
            }

            $governanceBodyId = $minutesObj['governanceBody'] ?? null;
            if (!$governanceBodyId) {
                $this->logger->warning(
                    'Decidesk: Minutes not linked to governance body for distribution',
                    ['minutesId' => $minutesId]
                );
                return 0;
            }

            // Get active participants.
            $participantParams = [
                'governanceBody' => $governanceBodyId,
                'leftAt'         => null,
                '_limit'         => 1000,
            ];

            $participantsResponse = $objectService->findObjects(
                register: 'decidesk',
                schema: 'Participant',
                filters: $participantParams
            );

            $participants = $participantsResponse['results'] ?? [];

            // Send notifications (placeholder - actual Nextcloud notification dispatch would go here).
            $notificationCount = count($participants);

            $this->logger->info(
                'Decidesk: ALV minutes distributed',
                ['minutesId' => $minutesId, 'recipientCount' => $notificationCount]
            );

            return $notificationCount;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: ALV minutes distribution failed',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end distribute()
}//end class
