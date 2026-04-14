<?php

/**
 * Decidesk ORI Publication Service
 *
 * Service for publishing voting round results to the ORI (Open Raadsinformatie) API.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that sends voting round results to the ORI 1.0 API endpoint
 * as JSON-LD. Publication is skipped silently when no endpoint is configured.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService
{
    /**
     * Construct the OriPublicationService.
     *
     * @param ContainerInterface $container The DI container for lazy-loading services
     * @param IAppConfig         $appConfig App configuration for reading ORI endpoint
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the configured ORI endpoint URL.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     *
     * @return string|null The URL or null if not configured
     */
    private function getEndpoint(): ?string
    {
        $endpoint = $this->appConfig->getValueString(Application::APP_ID, 'ori_endpoint', '');
        if (empty($endpoint) === false) {
            return $endpoint;
        }

        return null;

    }//end getEndpoint()

    /**
     * Publish a VotingRound's results to the ORI API.
     *
     * Reads the ORI endpoint from IAppConfig. If not configured, returns silently.
     * Builds a JSON-LD payload following ORI 1.0 format and POSTs it. On failure,
     * logs a warning — a retry background job handles exponential backoff.
     *
     * @param string $votingRoundId UUID of the VotingRound to publish
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return void
     */
    public function publish(string $votingRoundId): void
    {
        $endpoint = $this->getEndpoint();
        if ($endpoint === null) {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('voting-round');
            $roundObject = $objectService->find($votingRoundId);

            if ($roundObject === null) {
                $this->logger->warning("Decidesk ORI: VotingRound $votingRoundId not found for publication");
                return;
            }

            $roundData = $roundObject->getObject();

            $payload = $this->buildJsonLd(votingRoundId: $votingRoundId, roundData: $roundData);

            $context = stream_context_create(
                    [
                        'http' => [
                            'method'  => 'POST',
                            'header'  => "Content-Type: application/ld+json\r\nAccept: application/json\r\n",
                            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'timeout' => 10,
                        ],
                    ]
                    );

            $result = @file_get_contents($endpoint, false, $context);

            if ($result === false) {
                $this->logger->warning("Decidesk ORI: Publication to $endpoint failed for round $votingRoundId");
                return;
            }

            $this->logger->info("Decidesk ORI: VotingRound $votingRoundId published successfully to $endpoint");
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Decidesk ORI: Publication error for round $votingRoundId: {$e->getMessage()}"
            );
        }//end try

    }//end publish()

    /**
     * Build a JSON-LD payload following the ORI 1.0 standard for a VotingRound.
     *
     * @param string              $votingRoundId UUID of the VotingRound
     * @param array<string,mixed> $roundData     VotingRound object data
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return array<string,mixed> JSON-LD payload
     */
    private function buildJsonLd(string $votingRoundId, array $roundData): array
    {
        return [
            '@context'  => 'http://schema.org/',
            '@type'     => 'VoteAction',
            '@id'       => 'urn:voting-round:'.$votingRoundId,
            'name'      => 'Stemuitslag '.$votingRoundId,
            'startTime' => $roundData['openedAt'] ?? null,
            'endTime'   => $roundData['closedAt'] ?? null,
            'result'    => $roundData['result'] ?? null,
            'object'    => [
                '@type'        => 'GovernmentPermit',
                'votesFor'     => $roundData['votesFor'] ?? 0,
                'votesAgainst' => $roundData['votesAgainst'] ?? 0,
                'votesAbstain' => $roundData['votesAbstain'] ?? 0,
                'votingMethod' => $roundData['votingMethod'] ?? 'for-against-abstain',
                'isSecret'     => $roundData['isSecret'] ?? false,
                'quorumMet'    => $roundData['quorumMet'] ?? false,
            ],
        ];

    }//end buildJsonLd()

    /**
     * Get the current publication status for a VotingRound.
     *
     * Returns 'not_configured' if no ORI endpoint is set, 'published' if the
     * round has a closedAt (indicating results have been sent), or 'pending'.
     *
     * @param string $votingRoundId UUID of the VotingRound
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     *
     * @return string 'not_configured' | 'published' | 'pending'
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        if ($this->getEndpoint() === null) {
            return 'not_configured';
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('voting-round');
            $roundObject = $objectService->find($votingRoundId);

            if ($roundObject === null) {
                return 'pending';
            }

            $roundData = $roundObject->getObject();
            if (($roundData['closedAt'] ?? null) !== null) {
                return 'published';
            }

            return 'pending';
        } catch (\Throwable $e) {
            return 'pending';
        }

    }//end getPublicationStatus()
}//end class
