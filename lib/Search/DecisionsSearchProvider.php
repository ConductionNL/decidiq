<?php

/**
 * Decidesk Search Provider
 *
 * Integrates Decisions, Minutes, and ActionItems with Nextcloud global search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\Decidesk\Search
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Search;

use OCP\IL10N;
use OCP\IUser;
use OCP\Search\ISearchProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Container\ContainerInterface;

/**
 * Search provider for Decision, Minutes, and ActionItem objects.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
 */
class DecisionsSearchProvider implements ISearchProvider
{
    /**
     * Constructor for DecisionsSearchProvider.
     *
     * @param ContainerInterface $container The DI container
     * @param IL10N              $l10n      Localization service
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     */
    public function __construct(
        private ContainerInterface $container,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Get unique provider ID.
     *
     * @return string
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     */
    public function getId(): string
    {
        return 'decidesk';
    }//end getId()

    /**
     * Get translated provider name.
     *
     * @return string
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     */
    public function getName(): string
    {
        return $this->l10n->t('Besluiten en notulen');
    }//end getName()

    /**
     * Search for Decisions, Minutes, and ActionItems.
     *
     * @param IUser        $user  The user performing the search
     * @param ISearchQuery $query The search query
     *
     * @return SearchResult
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable) {
            return SearchResult::complete($this->getId(), []);
        }

        $results = [];
        $schemas = ['decision', 'minutes', 'action-item'];

        foreach ($schemas as $schema) {
            try {
                $objectService->setRegister('decidesk');
                $objectService->setSchema($schema);

                // Search using the query term as a filter
                $objects = $objectService->findAll(
                    limit: 10,
                    offset: 0,
                    order: [],
                    filters: ['_search' => $query->getTerm()]
                );

                foreach ($objects as $obj) {
                    $id = $obj['id'] ?? '';
                    $title = $obj['title'] ?? $obj['name'] ?? '';
                    $date = $obj['decisionDate'] ?? $obj['createdAt'] ?? '';
                    $lifecycle = $obj['lifecycle'] ?? '';

                    $subline = trim(sprintf('%s · %s', $lifecycle, $date));

                    $results[] = new SearchResultEntry(
                        thumbnail: '',
                        title: $title,
                        subline: $subline,
                        resourceUrl: sprintf('/apps/decidesk/%ss/%s', $schema, $id),
                        rounded: false
                    );
                }
            } catch (\Throwable) {
                // Continue if schema not available
                continue;
            }
        }

        return SearchResult::complete($this->getId(), $results);
    }//end search()
}//end class
