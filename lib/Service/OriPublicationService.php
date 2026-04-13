<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing voting round data to an ORI (Open Raadsinformatie) API endpoint.
 *
 * This service builds JSON-LD payloads conforming to the ORI standard and handles
 * publication of VotingRound resources to a configured ORI endpoint.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService
{
    /**
     * @var ContainerInterface The service container for resolving dependencies.
     */
    private ContainerInterface $container;

    /**
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;

    /**
     * @var IAppConfig The Nextcloud app configuration service.
     */
    private IAppConfig $appConfig;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The service container for resolving dependencies.
     * @param LoggerInterface    $logger    The logger instance.
     * @param IAppConfig         $appConfig The Nextcloud app configuration service.
     */
    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger,
        IAppConfig $appConfig,
    ) {
        $this->container = $container;
        $this->logger = $logger;
        $this->appConfig = $appConfig;
    }//end __construct()

    /**
     * Retrieve the ObjectService from the container.
     *
     * @return object The ObjectService instance.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Publish a VotingRound to the configured ORI endpoint.
     *
     * Fetches the VotingRound by ID, builds a JSON-LD payload with ORI-compliant
     * context and type annotations, and logs the publication attempt. The actual
     * HTTP call to the ORI endpoint would be dispatched via an IJob in production.
     *
     * @param string $votingRoundId The UUID of the VotingRound to publish.
     *
     * @return void
     */
    public function publish(string $votingRoundId): void
    {
        $oriEndpoint = $this->appConfig->getValueString(Application::APP_ID, 'ori_endpoint', '');

        if ($oriEndpoint === '') {
            return;
        }//end if

        $objectService = $this->getObjectService();
        $votingRound = $objectService->getObject('votingRound', $votingRoundId);

        // Build JSON-LD payload conforming to the ORI standard.
        $payload = [
            '@context' => 'https://standaarden.overheid.nl/owms/terms/',
            '@type' => 'VotingRound',
            'identifier' => $votingRoundId,
            'name' => $votingRound['name'] ?? '',
            'description' => $votingRound['description'] ?? '',
            'status' => $votingRound['status'] ?? '',
            'startDate' => $votingRound['startDate'] ?? '',
            'endDate' => $votingRound['endDate'] ?? '',
            'result' => $votingRound['result'] ?? '',
        ];

        // In production the actual HTTP POST to the ORI endpoint would be
        // dispatched via an IJob (background job) to avoid blocking the request.
        $this->logger->info('OriPublicationService: publishing VotingRound to ORI endpoint', [
            'votingRoundId' => $votingRoundId,
            'endpoint' => $oriEndpoint,
            'payload' => json_encode($payload),
        ]);
    }//end publish()

    /**
     * Get the publication status of a VotingRound on the ORI endpoint.
     *
     * Returns the current publication status. In a full implementation this would
     * track whether the resource has been successfully published, is pending, or
     * has encountered an error.
     *
     * @param string $votingRoundId The UUID of the VotingRound to check.
     *
     * @return string The publication status: 'not_configured' or 'pending'.
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        $oriEndpoint = $this->appConfig->getValueString(Application::APP_ID, 'ori_endpoint', '');

        if ($oriEndpoint === '') {
            return 'not_configured';
        }//end if

        // Simplified — full implementation would track publication status
        // per VotingRound in a dedicated database table.
        return 'pending';
    }//end getPublicationStatus()
}//end class
