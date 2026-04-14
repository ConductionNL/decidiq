<?php

/**
 * Decidesk ORI Publication Service
 *
 * Service for publishing voting round results to the ORI API (Open Raadsinformatie).
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
 * Service for publishing voting round results to the ORI (Open Raadsinformatie) API.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService
{

    /**
     * App config key for the ORI endpoint URL.
     *
     * @var string
     */
    private const CONFIG_KEY_ENDPOINT = 'ori_endpoint';

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
     * Retrieve the configured ORI endpoint URL.
     *
     * @return string Empty string when not configured.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function getEndpoint(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_ENDPOINT, '');
    }//end getEndpoint()

    /**
     * Build an ORI 1.0 JSON-LD payload for a voting round.
     *
     * @param array<string,mixed> $round The VotingRound object array
     *
     * @return array<string,mixed> JSON-LD payload
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function buildPayload(array $round): array
    {
        return [
            '@context'     => 'https://schema.org',
            '@type'        => 'VoteAction',
            'identifier'   => ($round['id'] ?? $round['uuid'] ?? ''),
            'startTime'    => ($round['openedAt'] ?? null),
            'endTime'      => ($round['closedAt'] ?? null),
            'result'       => ($round['result'] ?? null),
            'votesFor'     => ($round['votesFor'] ?? 0),
            'votesAgainst' => ($round['votesAgainst'] ?? 0),
            'votesAbstain' => ($round['votesAbstain'] ?? 0),
            'object'       => [
                '@type'      => 'Motion',
                'identifier' => ($round['motionId'] ?? null),
            ],
        ];

    }//end buildPayload()

    /**
     * Publish voting round results to the configured ORI endpoint.
     *
     * Returns silently when no ORI endpoint is configured. On HTTP failure,
     * logs a warning and queues a retry via a background job.
     *
     * @param string $votingRoundId The UUID of the VotingRound
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function publish(string $votingRoundId): void
    {
        $endpoint = $this->getEndpoint();
        if ($endpoint === '') {
            // ORI not configured — silent no-op.
            return;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

        if ($round === null) {
            $this->logger->warning(
                'Decidesk: ORI publish — voting round not found',
                ['votingRoundId' => $votingRoundId]
            );
            return;
        }

        $payload = $this->buildPayload(round: $round);

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $endpoint,
                [
                    'json'    => $payload,
                    'headers' => [
                        'Content-Type' => 'application/ld+json',
                        'Accept'       => 'application/json',
                    ],
                    'timeout' => 10,
                ]
            );

            // Mark published on the round object.
            $round['oriPublishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $round['oriStatus']      = 'published';
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

            $this->logger->info(
                'Decidesk: ORI publication successful',
                ['votingRoundId' => $votingRoundId, 'statusCode' => $response->getStatusCode()]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: ORI publication failed, will retry',
                ['votingRoundId' => $votingRoundId, 'error' => $e->getMessage()]
            );

            // Mark as pending retry.
            $round['oriStatus'] = 'pending';
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }//end try

    }//end publish()

    /**
     * Get the publication status for a voting round.
     *
     * @param string $votingRoundId The UUID of the VotingRound
     *
     * @return string One of: "not_configured", "pending", "published"
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        if ($this->getEndpoint() === '') {
            return 'not_configured';
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

        if ($round === null) {
            return 'not_configured';
        }

        return ($round['oriStatus'] ?? 'pending');

    }//end getPublicationStatus()
}//end class
