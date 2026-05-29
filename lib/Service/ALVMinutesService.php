<?php

/**
 * Decidesk ALV Minutes Service
 *
 * Service for generating ALV (Algemene Ledenvergadering) minutes templates
 * and distributing them to members.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Exception;
use OCA\Decidesk\Exception\MissingObjectException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that generates ALV-specific Dutch minutes templates.
 *
 * Generates minutes for Algemene Ledenvergadering (general assemblies) with
 * quorum statements, member rolls, and formal resolution language. Handles
 * distribution of approved minutes to active members via notifications.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 */
class ALVMinutesService
{
    /**
     * ALV Dutch minutes template.
     *
     * @var string
     */
    private const ALV_TEMPLATE = <<<'TEMPLATE'
{title}

Datum: {date}
Locatie: {location}

Aanwezigen:
{presentCount} van de {totalCount} leden
Status quorum: {quorumStatus}

Agendapunten:
{agendaItems}

Resoluties en Stemming:
{resolutions}

Rondvraag en Afsluiting:
{aob}

Notulen opgesteld door: {secretary}
Notulen goedgekeurd door: {chair}
TEMPLATE;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate an ALV draft based on the Minutes and linked Meeting.
     *
     * Fetches the Minutes and linked Meeting, validates that meetingType
     * contains 'alv', fetches AgendaItems and active Participants of the
     * linked GovernanceBody, and renders the ALV Dutch template.
     *
     * @param string $minutesId The Minutes ID
     *
     * @return array{
     *   content: string,
     *   recipientCount: int
     * } Generated content and recipient count
     *
     * @throws MissingObjectException If Minutes or Meeting not found
     * @throws Exception If meeting is not ALV type
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    public function generateALVDraft(string $minutesId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Fetch Minutes.
            $minutesEntity = $objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            $minutes       = null;
            if ($minutesEntity !== null) {
                $minutes = $minutesEntity->jsonSerialize();
            }

            if ($minutes === null) {
                throw new MissingObjectException(message: "Minutes not found: $minutesId");
            }

            // Get linked Meeting.
            $meetingId = null;
            if (empty($minutes['relations']['Meeting']) === false) {
                $meetingRels = $minutes['relations']['Meeting'];
                $meetingId   = $meetingRels;
                if (is_array($meetingRels) === true) {
                    $meetingId = $meetingRels[0];
                }
            }

            if (empty($meetingId) === true) {
                throw new Exception('No Meeting linked to Minutes');
            }

            $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
            $meeting       = null;
            if ($meetingEntity !== null) {
                $meeting = $meetingEntity->jsonSerialize();
            }

            if ($meeting === null) {
                throw new MissingObjectException(message: "Meeting not found: $meetingId");
            }

            // Validate meeting type is ALV.
            $meetingType = strtolower($meeting['meetingType'] ?? '');
            if (strpos($meetingType, 'alv') === false && strpos($meetingType, 'algemene-ledenvergadering') === false) {
                throw new Exception("Meeting is not an ALV (type: $meetingType)", 422);
            }

            // Get GovernanceBody ID.
            $bodyId = null;
            if (empty($meeting['relations']['GovernanceBody']) === false) {
                $bodyRels = $meeting['relations']['GovernanceBody'];
                $bodyId   = $bodyRels;
                if (is_array($bodyRels) === true) {
                    $bodyId = $bodyRels[0];
                }
            }

            // Fetch active participants scoped to this governance body.
            $params       = [
                'leftAt' => null,
                '_limit' => 999,
            ];
            $participants = [];
            if ($bodyId !== null) {
                $params['relations.governance-body'] = $bodyId;
                $objectService->setRegister('decidesk');
                $objectService->setSchema('participant');
                $participantEntities = $objectService->findAll(['filters' => $params]);
                $participants        = array_map(fn($e) => $e->jsonSerialize(), $participantEntities);
            }

            // Count active members.
            $memberCount  = count($participants);
            $presentCount = $memberCount;
            if (empty($meeting['quorumRequired']) === false) {
                $presentCount = min($memberCount, $meeting['quorumRequired']);
            }

            // Fetch agenda items scoped to this meeting.
            $agendaParams = [
                'relations.meeting' => $meetingId,
                '_limit'            => 999,
                '_order'            => 'orderNumber:ASC',
            ];
            $objectService->setRegister('decidesk');
            $objectService->setSchema('agenda-item');
            $agendaItemEntities = $objectService->findAll(['filters' => $agendaParams]);
            $agendaItems        = array_map(fn($e) => $e->jsonSerialize(), $agendaItemEntities);

            // Format agenda items.
            $agendaText = '';
            foreach ($agendaItems as $item) {
                $agendaText .= sprintf(
                    "- %s: %s\n",
                    $item['orderNumber'] ?? '',
                    $item['title'] ?? 'Untitled'
                );
            }

            // Determine quorum status.
            $quorumMet    = $presentCount >= ($meeting['quorumRequired'] ?? 0);
            $quorumStatus = "Quorum niet bereikt ($presentCount/$memberCount leden)";
            if ($quorumMet === true) {
                $quorumStatus = "Quorum bereikt ($presentCount/$memberCount leden)";
            }

            // Render template.
            $searchKeys = [
                '{title}',
                '{date}',
                '{location}',
                '{presentCount}',
                '{totalCount}',
                '{quorumStatus}',
                '{agendaItems}',
                '{resolutions}',
                '{secretary}',
                '{chair}',
                '{aob}',
            ];
            $content    = str_replace(
                $searchKeys,
                [
                    $minutes['title'] ?? 'Algemene Ledenvergadering',
                    $meeting['scheduledDate'] ?? date('d-m-Y'),
                    $meeting['location'] ?? '',
                    $presentCount,
                    $memberCount,
                    $quorumStatus,
                    trim($agendaText),
                    '[Resoluties met stemming in aparte tabel]',
                    '[Secretaris naam]',
                    '[Voorzitter naam]',
                    '[Rondvraag: geen bijzonderheden / gesloten om ...]',
                ],
                self::ALV_TEMPLATE
            );

            $this->logger->info("ALV draft generated for minutes $minutesId");

            return [
                'content'        => $content,
                'recipientCount' => $memberCount,
            ];
        } catch (Exception $e) {
            $this->logger->error("ALVMinutesService::generateALVDraft failed: ".$e->getMessage());
            throw $e;
        }//end try
    }//end generateALVDraft()

    /**
     * Distribute approved minutes to all active members.
     *
     * Fetches the Minutes (must be in approved or signed state), fetches
     * active Participants of the linked GovernanceBody, and sends Nextcloud
     * notifications to each with minutes title and deep link.
     *
     * @param string $minutesId The Minutes ID
     *
     * @return int The count of notifications sent
     *
     * @throws MissingObjectException If Minutes not found
     * @throws Exception If lifecycle is not approved or signed
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
     */
    public function distribute(string $minutesId): int
    {
        try {
            $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $notificationService = $this->container->get('OpenRegisterNotificationService');

            // Fetch Minutes.
            $minutesEntity = $objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            $minutes       = null;
            if ($minutesEntity !== null) {
                $minutes = $minutesEntity->jsonSerialize();
            }

            if ($minutes === null) {
                throw new MissingObjectException(message: "Minutes not found: $minutesId");
            }

            // Validate lifecycle.
            $lifecycle = $minutes['lifecycle'] ?? null;
            if ($lifecycle !== 'approved' && $lifecycle !== 'signed') {
                throw new Exception("Minutes must be approved or signed before distribution (current: $lifecycle)", 403);
            }

            // Get linked Meeting.
            $meetingId = null;
            if (empty($minutes['relations']['Meeting']) === false) {
                $meetingRels = $minutes['relations']['Meeting'];
                $meetingId   = $meetingRels;
                if (is_array($meetingRels) === true) {
                    $meetingId = $meetingRels[0];
                }
            }

            // Get GovernanceBody ID.
            $bodyId = null;
            if ($meetingId !== null) {
                $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
                $meeting       = null;
                if ($meetingEntity !== null) {
                    $meeting = $meetingEntity->jsonSerialize();
                }

                if ($meeting !== null && empty($meeting['relations']['GovernanceBody']) === false) {
                    $bodyRels = $meeting['relations']['GovernanceBody'];
                    $bodyId   = $bodyRels;
                    if (is_array($bodyRels) === true) {
                        $bodyId = $bodyRels[0];
                    }
                }
            }

            // Fetch active participants scoped to this governance body.
            $params       = [
                'leftAt' => null,
                '_limit' => 999,
            ];
            $participants = [];
            if ($bodyId !== null) {
                $params['relations.governance-body'] = $bodyId;
                $objectService->setRegister('decidesk');
                $objectService->setSchema('participant');
                $participantEntities = $objectService->findAll(['filters' => $params]);
                $participants        = array_map(fn($e) => $e->jsonSerialize(), $participantEntities);
            }

            // Resolve Nextcloud UID for each participant and send notifications.
            $userManager = $this->container->get(\OCP\IUserManager::class);
            $sentCount   = 0;
            foreach ($participants as $participant) {
                $ncUid = $participant['nextcloudUserId'] ?? null;
                if (empty($ncUid) === true) {
                    $email = $participant['email'] ?? null;
                    if (empty($email) === false) {
                        $users = $userManager->getByEmail(email: $email);
                        if (empty($users) === false) {
                            $ncUid = array_values($users)[0]->getUID();
                        }
                    }
                }

                if (empty($ncUid) === true) {
                    $displayName = $participant['displayName'] ?? '?';
                    $this->logger->warning('ALVMinutesService: cannot resolve Nextcloud UID for participant', ['participant' => $displayName]);
                    continue;
                }

                try {
                    $notificationService->sendNotification(
                        userId: $ncUid,
                        title: "Notulen gepubliceerd: ".($minutes['title'] ?? 'Untitled'),
                        message: "De notulen zijn nu beschikbaar.",
                        deepLink: "/minutes/$minutesId"
                    );
                    $sentCount++;
                } catch (Exception $e) {
                    $this->logger->warning("Failed to send notification to $ncUid: ".$e->getMessage());
                }
            }//end foreach

            $this->logger->info("ALV minutes distributed to $sentCount participants");

            return $sentCount;
        } catch (Exception $e) {
            $this->logger->error("ALVMinutesService::distribute failed: ".$e->getMessage());
            throw $e;
        }//end try
    }//end distribute()
}//end class
