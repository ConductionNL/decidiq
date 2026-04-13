<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk ORI Publication Service
 *
 * Service for publishing voting round data to an ORI (Open Raadsinformatie) API endpoint.
 *
 * This service builds JSON-LD payloads conforming to the ORI standard and handles
 * publication of VotingRound resources to a configured ORI endpoint.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing voting round data to an ORI (Open Raadsinformatie) API endpoint.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService
{

    /**
     * The service container for resolving dependencies.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * The Nextcloud app configuration service.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The HTTP client service for making outbound requests.
     *
     * @var IClientService
     */
    private IClientService $clientService;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container     The service container for resolving dependencies.
     * @param LoggerInterface    $logger        The logger instance.
     * @param IAppConfig         $appConfig     The Nextcloud app configuration service.
     * @param IClientService     $clientService The HTTP client service.
     */
    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger,
        IAppConfig $appConfig,
        IClientService $clientService,
    ) {
        $this->container     = $container;
        $this->logger        = $logger;
        $this->appConfig     = $appConfig;
        $this->clientService = $clientService;
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
     * context and type annotations, and POSTs it to the configured ORI endpoint.
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
        $votingRound   = $objectService->getObject('votingRound', $votingRoundId);

        // Build JSON-LD payload conforming to the ORI standard.
        $payload = [
            '@context'    => 'https://standaarden.overheid.nl/owms/terms/',
            '@type'       => 'VotingRound',
            'identifier'  => $votingRoundId,
            'name'        => $votingRound['name'] ?? '',
            'description' => $votingRound['description'] ?? '',
            'status'      => $votingRound['status'] ?? '',
            'startDate'   => $votingRound['startDate'] ?? '',
            'endDate'     => $votingRound['endDate'] ?? '',
            'result'      => $votingRound['result'] ?? '',
        ];

        // Log only the host/path, not the full URL (which may contain API keys).
        $scheme       = (parse_url($oriEndpoint, PHP_URL_SCHEME) ?? 'https');
        $host         = (parse_url($oriEndpoint, PHP_URL_HOST) ?? '');
        $path         = (parse_url($oriEndpoint, PHP_URL_PATH) ?? '');
        $endpointHost = $scheme.'://'.$host.$path;

        try {
            $client = $this->clientService->newClient();
            $client->post(
                $oriEndpoint,
                [
                    'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
                    'headers' => ['Content-Type' => 'application/ld+json'],
                ]
            );

            $this->logger->info(
                'OriPublicationService: published VotingRound to ORI endpoint',
                [
                    'votingRoundId' => $votingRoundId,
                    'endpoint'      => $endpointHost,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'OriPublicationService: failed to publish VotingRound to ORI endpoint',
                [
                    'votingRoundId' => $votingRoundId,
                    'endpoint'      => $endpointHost,
                    'error'         => $e->getMessage(),
                ]
            );
        }//end try
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
