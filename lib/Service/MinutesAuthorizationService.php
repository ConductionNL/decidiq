<?php
/**
 * Decidesk Minutes Authorization Service
 *
 * Per-board-member guard used by the QES-signing flow. Walks
 * Minutes → Meeting → GovernanceBody → Participants and verifies the
 * requesting Nextcloud user is a chair, vice-chair, or secretary on that
 * body — the canonical signatory roles per the Dutch supervisory-board
 * statuten + the decidesk Participant.role enum.
 *
 * Closes R-4 from board-portal-internal-security-review.md (W33).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-board-member guard for the eIDAS signing flow.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */
class MinutesAuthorizationService
{

    /**
     * Participant roles allowed to initiate a QES signing request on a Minutes
     * record. Matches the schema enum exactly — both the English (`chair`,
     * `secretary`) and Dutch-influenced (`chairman`, `vice-chairman`) values
     * are accepted because the decidesk_register.json schemas use both shapes.
     *
     * @var string[]
     */
    private const SIGNATORY_ROLES = [
        'chair',
        'chairman',
        'vice-chairman',
        'secretary',
    ];


    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazy ObjectService lookup)
     * @param LoggerInterface    $logger    Diagnostic logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Return true when the given Nextcloud user is a signatory (chair,
     * vice-chair, or secretary) on the GovernanceBody linked to the Minutes
     * record.
     *
     * Fails CLOSED on any lookup failure — opposite of the unsafe-auth-resolver
     * anti-pattern (gate-8). A missing Minutes, missing Meeting, missing
     * GovernanceBody, or absent Participant returns false so the controller
     * issues 403 rather than treating "service unavailable" as "check skipped".
     *
     * @param string $userId    Nextcloud UID of the requester
     * @param string $minutesId UUID of the Minutes record
     *
     * @return bool
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     */
    public function canInitiateSigning(string $userId, string $minutesId): bool
    {
        if ($userId === '' || $minutesId === '') {
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $minutesEntity = $objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            if ($minutesEntity === null) {
                return false;
            }

            $minutes = $minutesEntity->jsonSerialize();

            $meetingId = $this->extractRelation(record: $minutes, key: 'Meeting');
            if ($meetingId === null) {
                return false;
            }

            $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
            if ($meetingEntity === null) {
                return false;
            }

            $meeting = $meetingEntity->jsonSerialize();
            $bodyId  = $this->extractRelation(record: $meeting, key: 'GovernanceBody');
            if ($bodyId === null) {
                return false;
            }

            $objectService->setRegister('decidesk');
            $objectService->setSchema('participant');
            $participants = $objectService->findAll(
                [
                    'filters' => [
                        'role'   => self::SIGNATORY_ROLES,
                        '_limit' => 999,
                    ],
                ]
            );

            foreach ($participants as $participantEntity) {
                $participant = $participantEntity->jsonSerialize();

                $participantBody = $this->extractRelation(record: $participant, key: 'GovernanceBody');
                if ($participantBody !== $bodyId) {
                    continue;
                }

                $ncUid = (string) ($participant['nextcloudUserId'] ?? '');
                if ($ncUid === $userId) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MinutesAuthorizationService::canInitiateSigning failed; denying',
                ['exception' => $e->getMessage(), 'minutesId' => $minutesId, 'userId' => $userId]
            );
            return false;
        }//end try

        return false;

    }//end canInitiateSigning()


    /**
     * Extract the first related entity UUID from a relations map.
     *
     * @param array<string, mixed> $record The serialised object
     * @param string               $key    Relation key (e.g. 'Meeting')
     *
     * @return string|null
     */
    private function extractRelation(array $record, string $key): ?string
    {
        $relations = ($record['relations'] ?? []);
        if (is_array($relations) === false) {
            return null;
        }

        $value = ($relations[$key] ?? null);
        if (is_array($value) === true) {
            $value = ($value[0] ?? null);
        }

        if (is_string($value) === false || $value === '') {
            return null;
        }

        return $value;

    }//end extractRelation()


}//end class
