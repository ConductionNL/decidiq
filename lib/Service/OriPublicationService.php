<?php

/**
 * Decidesk ORI Publication Service
 *
 * Handles publication of voting results to the ORI (Open Raadsinformatie) API endpoint
 * following the ORI 1.0 JSON-LD format.
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
 * Service for publishing voting results to the ORI API endpoint.
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
    public const CONFIG_ORI_ENDPOINT = 'ori_endpoint';

    /**
     * Publication status values returned by getPublicationStatus().
     *
     * @var string
     */
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_PENDING        = 'pending';
    public const STATUS_PUBLISHED      = 'published';

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig App configuration
     * @param ContainerInterface $container DI container (OpenRegister services)
     * @param LoggerInterface    $logger    PSR logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get ObjectService from DI container.
     *
     * @return object
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Publish voting round results to the configured ORI endpoint.
     *
     * Returns silently if no ORI endpoint is configured (ADR decision: never throw
     * when config is missing). On HTTP failure, logs and marks the round as pending.
     *
     * @param string $votingRoundId UUID of the VotingRound to publish
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    public function publish(string $votingRoundId): void
    {
        $endpoint = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_ORI_ENDPOINT, '');

        if (empty($endpoint) === true) {
            // Gracefully handle missing config — no exception, no log noise.
            return;
        }

        $objectService = $this->getObjectService();
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

        if ($round === null) {
            $this->logger->error("OriPublicationService: VotingRound {$votingRoundId} not found");
            return;
        }

        $payload     = $this->buildJsonLdPayload(round: $round, votingRoundId: $votingRoundId);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payloadJson === false) {
            $this->logger->error("OriPublicationService: JSON encode failed for {$votingRoundId}");
            return;
        }

        try {
            $this->httpPost(url: $endpoint, payload: $payloadJson);

            // Mark as published.
            $round['notes']   = $round['notes'] ?? [];
            $round['notes'][] = [
                'title' => 'ORI publication',
                'body'  => json_encode(
                        [
                            'status'      => self::STATUS_PUBLISHED,
                            'publishedAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
                            'endpoint'    => $endpoint,
                        ]
                        ),
            ];
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

            $this->logger->info("OriPublicationService: VotingRound {$votingRoundId} published to {$endpoint}");
        } catch (\Throwable $e) {
            // Store pending status for retry by background job.
            $this->markPending(round: $round, votingRoundId: $votingRoundId, errorMessage: $e->getMessage());
        }//end try
    }//end publish()

    /**
     * Return the current ORI publication status for a VotingRound.
     *
     * @param string $votingRoundId UUID of the VotingRound
     *
     * @return string One of: "not_configured", "pending", "published"
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        $endpoint = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_ORI_ENDPOINT, '');
        if (empty($endpoint) === true) {
            return self::STATUS_NOT_CONFIGURED;
        }

        try {
            $objectService = $this->getObjectService();
            $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

            if ($round === null) {
                return self::STATUS_PENDING;
            }

            $notes = $round['notes'] ?? [];
            foreach ($notes as $note) {
                if (($note['title'] ?? '') === 'ORI publication') {
                    $body = json_decode($note['body'] ?? '{}', true);
                    return $body['status'] ?? self::STATUS_PENDING;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("OriPublicationService: status check failed: {$e->getMessage()}");
        }

        return self::STATUS_PENDING;
    }//end getPublicationStatus()

    /**
     * Build an ORI 1.0-compliant JSON-LD payload for a VotingRound.
     *
     * @param array<string,mixed> $round         VotingRound object data
     * @param string              $votingRoundId UUID for identification
     *
     * @return array<string,mixed> JSON-LD payload array
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    private function buildJsonLdPayload(array $round, string $votingRoundId): array
    {
        return [
            '@context'     => 'https://schema.org',
            '@type'        => 'VoteAction',
            '@id'          => "urn:decidesk:voting-round:{$votingRoundId}",
            'identifier'   => $votingRoundId,
            'actionStatus' => 'CompletedActionStatus',
            'startTime'    => $round['openedAt'] ?? null,
            'endTime'      => $round['closedAt'] ?? null,
            'result'       => $round['result'] ?? null,
            'object'       => [
                '@type'        => 'VoteTally',
                'votesFor'     => $round['votesFor'] ?? 0,
                'votesAgainst' => $round['votesAgainst'] ?? 0,
                'votesAbstain' => $round['votesAbstain'] ?? 0,
            ],
        ];
    }//end buildJsonLdPayload()

    /**
     * Execute an HTTP POST to the ORI endpoint.
     *
     * Uses PHP streams for zero external dependencies.
     *
     * @param string $url     The ORI endpoint URL
     * @param string $payload JSON-encoded payload
     *
     * @throws \RuntimeException On HTTP error or connection failure
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    private function httpPost(string $url, string $payload): void
    {
        $context = stream_context_create(
                [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/ld+json\r\nAccept: application/json\r\n",
                        'content' => $payload,
                        'timeout' => 10,
                    ],
                ]
                );

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \RuntimeException("HTTP POST to ORI endpoint {$url} failed");
        }
    }//end httpPost()

    /**
     * Mark a VotingRound as having a pending ORI publication (retry needed).
     *
     * @param array<string,mixed> $round         VotingRound object
     * @param string              $votingRoundId UUID
     * @param string              $errorMessage  The error that caused the pending state
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    private function markPending(array $round, string $votingRoundId, string $errorMessage): void
    {
        $this->logger->warning(
            "OriPublicationService: publish failed for {$votingRoundId}, marked as pending: {$errorMessage}"
        );

        try {
            $round['notes']   = $round['notes'] ?? [];
            $round['notes'][] = [
                'title' => 'ORI publication',
                'body'  => json_encode(
                        [
                            'status'   => self::STATUS_PENDING,
                            'error'    => $errorMessage,
                            'markedAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
                        ]
                        ),
            ];

            $this->getObjectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        } catch (\Throwable $e) {
            $this->logger->error("OriPublicationService: could not save pending status: {$e->getMessage()}");
        }
    }//end markPending()
}//end class
