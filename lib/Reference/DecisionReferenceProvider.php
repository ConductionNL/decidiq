<?php

/**
 * Decidesk Decision Reference Provider
 *
 * Provides rich link preview cards for Decision URLs in Mail, Text, and Talk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Reference
 * @package  OCA\Decidesk\Reference
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Reference;

use OCP\Collaboration\Reference\AReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\IL10N;
use Psr\Container\ContainerInterface;

/**
 * Reference provider for Decision URLs.
 *
 * Matches URLs containing `/apps/decidesk/decisions/{uuid}` and renders rich cards.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
 */
class DecisionReferenceProvider extends AReference implements IReferenceProvider
{
    /**
     * Constructor for DecisionReferenceProvider.
     *
     * @param ContainerInterface $container The DI container
     * @param IL10N              $l10n      Localization service
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function __construct(
        private ContainerInterface $container,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Check if this provider can handle a URL.
     *
     * @param string $url The URL to check
     *
     * @return bool True if URL matches Decision pattern
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function matchesUrl(string $url): bool
    {
        return (bool) preg_match(
            '/\/apps\/decidesk\/decisions\/[a-f0-9\-]{36}/',
            $url
        );
    }//end matchesUrl()

    /**
     * Resolve a Decision URL to a rich reference card.
     *
     * @param string $url The Decision URL
     *
     * @return self|null Reference with title, description, and publication status
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function resolveReference(string $url): ?self
    {
        if (!$this->matchesUrl($url)) {
            return null;
        }

        // Extract UUID from URL
        if (!preg_match(
            '/\/apps\/decidesk\/decisions\/([a-f0-9\-]{36})/',
            $url,
            $matches
        )) {
            return null;
        }

        $decisionId = $matches[1];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable) {
            return null;
        }

        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');
        $decisionEntity = $objectService->find($decisionId);

        if ($decisionEntity === null) {
            return null;
        }

        $decision = $decisionEntity->getObject();

        // Extract title and description
        $title = $decision['title'] ?? 'Decision';
        $text = $decision['text'] ?? '';
        $description = substr($text, 0, 200);

        // Append publication status
        if ($decision['isPublished'] ?? false) {
            $description .= ' — ' . $this->l10n->t('Gepubliceerd');
        } else {
            $description .= ' — ' . $this->l10n->t('Niet gepubliceerd');
        }

        // Build reference
        $this->setUrl($url);
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setImageUrl('');
        $this->setRichObject('decision', $decision);

        return $this;
    }//end resolveReference()

    /**
     * Get cache prefix for this provider.
     *
     * @return string
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function getCachePrefix(): string
    {
        return 'decidesk-decision';
    }//end getCachePrefix()

    /**
     * Get cache TTL in seconds.
     *
     * @return int
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function getCacheTtl(): int
    {
        return 3600;
    }//end getCacheTtl()
}//end class
