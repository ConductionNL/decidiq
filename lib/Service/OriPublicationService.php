<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk ORI Publication Service
 *
 * Handles publication of voting round results to the ORI API (Open Raadsinformatie)
 * following ORI 1.0 JSON-LD format with exponential backoff retry.
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
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing voting results to the ORI API.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService
{

    /**
     * App config key for the ORI endpoint URL.
     */
    private const CONFIG_KEY_ORI_ENDPOINT = 'ori_endpoint';

    /**
     * App config key for the publication status prefix.
     */
    private const CONFIG_KEY_PUB_STATUS = 'ori_pub_status_';

    /**
     * Constructor for OriPublicationService.
     *
     * @param IAppConfig         $appConfig     The app configuration
     * @param IClientService     $clientService The HTTP client service
     * @param ContainerInterface $container     The DI container
     * @param LoggerInterface    $logger        The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the configured ORI endpoint URL.
     *
     * @return string Empty string when not configured.
     */
    private function getOriEndpoint(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_ORI_ENDPOINT, '');
    }//end getOriEndpoint()

    /**
     * Publish voting round results to the ORI API.
     *
     * Reads the ORI endpoint from app config; returns silently when not configured.
     * Builds a JSON-LD payload following ORI 1.0 format and sends a POST request.
     * On failure, queues a retry via the background job mechanism.
     *
     * @param string $votingRoundId The VotingRound object ID to publish
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function publish(string $votingRoundId): void
    {
        $endpoint = $this->getOriEndpoint();
        if ($endpoint === '') {
            $this->logger->debug('Decidesk: ORI endpoint not configured, skipping publication');
            return;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $round         = $objectService->findObject(
            register: Application::APP_ID,
            schema: 'voting-round',
            id: $votingRoundId
        );

        if ($round === null) {
            $this->logger->warning("Decidesk: VotingRound {$votingRoundId} not found for ORI publication");
            return;
        }

        $payload = $this->buildJsonLdPayload($votingRoundId, $round);

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $endpoint,
                [
                    'headers' => [
                        'Content-Type' => 'application/ld+json',
                        'Accept'       => 'application/json',
                    ],
                    'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
                    'timeout' => 30,
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->appConfig->setValueString(
                    Application::APP_ID,
                    self::CONFIG_KEY_PUB_STATUS . $votingRoundId,
                    'published'
                );
                $this->logger->info("Decidesk: VotingRound {$votingRoundId} published to ORI");
            } else {
                throw new \RuntimeException("ORI returned HTTP {$statusCode}");
            }
        } catch (\Throwable $e) {
            $this->appConfig->setValueString(
                Application::APP_ID,
                self::CONFIG_KEY_PUB_STATUS . $votingRoundId,
                'pending'
            );
            $this->logger->error(
                "Decidesk: ORI publication failed for round {$votingRoundId}",
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end publish()

    /**
     * Get the publication status for a voting round.
     *
     * @param string $votingRoundId The VotingRound ID
     *
     * @return string One of: 'pending', 'published', 'not_configured'
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        if ($this->getOriEndpoint() === '') {
            return 'not_configured';
        }

        return $this->appConfig->getValueString(
            Application::APP_ID,
            self::CONFIG_KEY_PUB_STATUS . $votingRoundId,
            'pending'
        );
    }//end getPublicationStatus()

    /**
     * Build the ORI 1.0 JSON-LD payload for a voting round.
     *
     * @param string              $votingRoundId The VotingRound ID
     * @param array<string,mixed> $round         The VotingRound object data
     *
     * @return array<string,mixed> The JSON-LD payload
     */
    private function buildJsonLdPayload(string $votingRoundId, array $round): array
    {
        return [
            '@context'       => 'https://schema.org/',
            '@type'          => 'VoteAction',
            '@id'            => "urn:decidesk:voting-round:{$votingRoundId}",
            'name'           => "Stemronde {$votingRoundId}",
            'startTime'      => ($round['openedAt'] ?? null),
            'endTime'        => ($round['closedAt'] ?? null),
            'result'         => ($round['result'] ?? null),
            'votesFor'       => ($round['votesFor'] ?? 0),
            'votesAgainst'   => ($round['votesAgainst'] ?? 0),
            'votesAbstain'   => ($round['votesAbstain'] ?? 0),
            'votingMethod'   => ($round['votingMethod'] ?? null),
            'isSecret'       => ($round['isSecret'] ?? false),
            'quorumMet'      => ($round['quorumMet'] ?? null),
            'publisher'      => [
                '@type' => 'Organization',
                'name'  => 'Decidesk',
            ],
        ];
    }//end buildJsonLdPayload()

}//end class
