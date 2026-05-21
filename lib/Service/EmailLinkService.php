<?php
/**
 * Decidesk Email Link Service
 *
 * Stateless service handling EmailLink objects: linking emails (Nextcloud
 * Mail) to governance objects (Decision, AgendaItem) and extracting
 * decision references from email subject/body for auto-suggest linking.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-6
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for linking emails to decisions and agenda items, with reverse
 * lookup and decision-reference extraction.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-6.1
 */
class EmailLinkService
{
    /**
     * Construct the EmailLinkService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Link an email to a decision.
     *
     * @param array<string, mixed> $emailLink EmailLink properties
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When required fields are missing
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.1
     */
    public function linkEmailToDecision(array $emailLink): array
    {
        if (empty($emailLink['subject']) === true) {
            throw new InvalidArgumentException('EmailLink subject is required');
        }

        if (isset($emailLink['receivedAt']) === false) {
            $emailLink['receivedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        }

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $emailLink,
            register: 'decidesk',
            schema: 'email-link',
        );

        $this->logger->info(
            'Decidesk: Email linked',
            ['subject' => $emailLink['subject'], 'linkedTo' => ($emailLink['linkedTo'] ?? null)]
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end linkEmailToDecision()

    /**
     * Link an email to an agenda item (alias for linkEmailToDecision with same schema).
     *
     * @param array<string, mixed> $emailLink EmailLink properties
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.1
     */
    public function linkEmailToAgendaItem(array $emailLink): array
    {
        return $this->linkEmailToDecision(emailLink: $emailLink);

    }//end linkEmailToAgendaItem()

    /**
     * Find email links by target object.
     *
     * @param string $target Target reference 'register:schema:uuid'
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.1
     */
    public function findLinkedEmails(string $target): array
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('email-link');

            $results = $objectService->findAll(
                limit: 200,
                offset: 0,
                filters: ['linkedTo' => $target],
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Decidesk: findLinkedEmails failed',
                ['target' => $target, 'error' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ($results as $entity) {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                $out[] = $entity->getObject();
            } else if (is_array($entity) === true) {
                $out[] = $entity;
            }
        }

        return $out;

    }//end findLinkedEmails()

    /**
     * Extract decision references from an email subject or body.
     *
     * Matches patterns like 'B-2026-031', 'Besluit-2024-001', or 'Decision-2024-001'.
     *
     * @param string $text Email subject or body
     *
     * @return array<int, string> Extracted decision/motion identifiers
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.5
     */
    public function extractEmailMetadata(string $text): array
    {
        $matches = [];
        preg_match_all(
            '/\b(?:Decision|Besluit|B|Motie|M|A|Amendement)[-_ ](\d{4})[-_ ](\d{2,4})\b/i',
            $text,
            $rawMatches
        );

        if (isset($rawMatches[0]) === true && count($rawMatches[0]) > 0) {
            $matches = array_unique($rawMatches[0]);
        }

        return array_values($matches);

    }//end extractEmailMetadata()
}//end class
