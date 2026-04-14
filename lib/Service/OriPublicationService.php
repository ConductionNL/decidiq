<?php

/**
 * Decidesk ORI Publication Service
 *
 * Service for publishing voting round results to the ORI (Overheid.nl Register
 * van Informatie) API endpoint in JSON-LD format.
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
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing voting results to the ORI API.
 *
 * Reads the configured ORI endpoint from IAppConfig, builds a JSON-LD payload
 * following the ORI 1.0 standard, and sends it via HTTP POST. Returns silently
 * if no ORI endpoint is configured.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService
{

    /**
     * IAppConfig key for the ORI endpoint URL.
     *
     * @var string
     */
    private const ORI_ENDPOINT_KEY = 'ori_endpoint';

    /**
     * Constructor for OriPublicationService.
     *
     * @param ContainerInterface $container     The DI container
     * @param IAppConfig         $appConfig     The app config interface
     * @param IClientService     $clientService The HTTP client service
     * @param LoggerInterface    $logger        The logger
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IClientService $clientService,
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
        return $this->appConfig->getValueString(Application::APP_ID, self::ORI_ENDPOINT_KEY, '');

    }//end getOriEndpoint()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Publish voting round results to the ORI API.
     *
     * If no ORI endpoint is configured, returns silently. On HTTP failure,
     * logs a warning — the retry job will handle re-publication.
     *
     * @param string $votingRoundId The voting round UUID to publish
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return void
     */
    public function publish(string $votingRoundId): void
    {
        $endpoint = $this->getOriEndpoint();

        if ($endpoint === '') {
            // ORI endpoint not configured — silent return.
            return;
        }

        try {
            $objectService = $this->getObjectService();
            $round         = $objectService->getObject(
                register: 'decidesk',
                schema: 'voting-round',
                id: $votingRoundId,
            );

            $payload = $this->buildJsonLdPayload(round: $round, votingRoundId: $votingRoundId);

            $client   = $this->clientService->newClient();
            $response = $client->post(
                $endpoint,
                [
                    'headers' => [
                        'Content-Type' => 'application/ld+json',
                        'Accept'       => 'application/ld+json',
                    ],
                    'body'    => json_encode($payload, flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info(
                    "Decidesk: VotingRound {$votingRoundId} published to ORI (HTTP {$statusCode})"
                );
            } else {
                $this->logger->warning(
                    "Decidesk: ORI publication returned HTTP {$statusCode}",
                    ['votingRoundId' => $votingRoundId]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: ORI publication failed',
                ['exception' => $e->getMessage(), 'votingRoundId' => $votingRoundId]
            );
        }//end try

    }//end publish()

    /**
     * Get the publication status for a VotingRound.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return string One of 'not_configured', 'pending', 'published'.
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        if ($this->getOriEndpoint() === '') {
            return 'not_configured';
        }

        try {
            $objectService = $this->getObjectService();
            $round         = $objectService->getObject(
                register: 'decidesk',
                schema: 'voting-round',
                id: $votingRoundId,
            );

            if (($round['oriPublishedAt'] ?? null) !== null) {
                return 'published';
            }

            return 'pending';
        } catch (\Throwable $e) {
            return 'pending';
        }

    }//end getPublicationStatus()

    /**
     * Build the JSON-LD payload for an ORI 1.0 voting result publication.
     *
     * @param array<string,mixed> $round         The VotingRound object
     * @param string              $votingRoundId The voting round UUID
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return array<string,mixed> JSON-LD payload
     */
    private function buildJsonLdPayload(array $round, string $votingRoundId): array
    {
        return [
            '@context'     => 'https://schema.org',
            '@type'        => 'VoteAction',
            '@id'          => "urn:decidesk:voting-round:{$votingRoundId}",
            'name'         => "Stemronde {$votingRoundId}",
            'startTime'    => ($round['openedAt'] ?? null),
            'endTime'      => ($round['closedAt'] ?? null),
            'result'       => ($round['result'] ?? null),
            'votesFor'     => ($round['votesFor'] ?? 0),
            'votesAgainst' => ($round['votesAgainst'] ?? 0),
            'votesAbstain' => ($round['votesAbstain'] ?? 0),
            'actionStatus' => 'CompletedActionStatus',
        ];

    }//end buildJsonLdPayload()
}//end class
