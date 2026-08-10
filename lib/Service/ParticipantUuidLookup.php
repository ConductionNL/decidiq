<?php
/**
 * Decidesk Participant UUID Lookup
 *
 * Maps a Nextcloud user login (UID) onto the OpenRegister Participant object
 * UUID that the voting, proxy and evaluation flows key on.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Nextcloud UID -> Participant UUID resolution.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class ParticipantUuidLookup
{
    /**
     * Constructor for ParticipantUuidLookup.
     *
     * @param ContainerInterface $container The DI container (lazy ObjectService resolution)
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {

    }//end __construct()

    /**
     * Resolve the OpenRegister participant UUID for a given Nextcloud user ID.
     *
     * Queries the participant register by nextcloudUserId field. Returns null when
     * no matching participant object is found.
     *
     * @param string $nextcloudUid The Nextcloud user login name (UID)
     *
     * @return string|null The participant object UUID, or null if not found
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function forNextcloudUser(string $nextcloudUid): ?string
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $entities = $objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

        foreach ($entities as $participantEntity) {
            $participant = $participantEntity->jsonSerialize();
            return ($participant['uuid'] ?? $participant['id'] ?? null);
        }

        return null;

    }//end forNextcloudUser()
}//end class
