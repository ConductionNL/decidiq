<?php

/**
 * Decidesk ORI Publication Service
 *
 * Handles HTTP publication of voting round results to the ORI API (Open Raadsinformatie).
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
 * Sends voting round results to the configured ORI endpoint as JSON-LD.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
 */
class OriPublicationService
{

    /**
     * App config key for the ORI endpoint URL.
     *
     * @var string
     */
    private const CONFIG_KEY_ORI_ENDPOINT = 'ori_endpoint';

    /**
     * App config key for publication status storage (stored as notes on VotingRound).
     *
     * @var string
     */
    private const STATUS_PENDING        = 'pending';
    private const STATUS_PUBLISHED      = 'published';
    private const STATUS_NOT_CONFIGURED = 'not_configured';

    /**
     * Constructor for OriPublicationService.
     *
     * @param IAppConfig         $appConfig The app config
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Publish voting round results to the ORI endpoint.
     *
     * If no ORI endpoint is configured, returns silently.
     * On failure, logs a warning (retry is handled by the background job queue).
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    public function publish(string $votingRoundId): void
    {
        $endpoint = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_ORI_ENDPOINT, '');
        if ($endpoint === '') {
            $this->logger->debug('Decidesk: ORI endpoint not configured, skipping publication');
            return;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

        if ($round === null) {
            $this->logger->warning('Decidesk: VotingRound not found for ORI publication', ['id' => $votingRoundId]);
            return;
        }

        $payload = $this->buildJsonLd(votingRoundId: $votingRoundId, round: $round);

        try {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize cURL');
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt(
                    $ch,
                    CURLOPT_HTTPHEADER,
                    [
                        'Content-Type: application/ld+json',
                        'Accept: application/ld+json',
                    ]
                    );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response   = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->markPublicationStatus(objectService: $objectService, round: $round, status: self::STATUS_PUBLISHED);
                $this->logger->info('Decidesk: ORI publication succeeded', ['id' => $votingRoundId, 'status' => $statusCode]);
            } else {
                $this->markPublicationStatus(objectService: $objectService, round: $round, status: self::STATUS_PENDING);
                $this->logger->warning(
                    'Decidesk: ORI publication failed',
                    ['id' => $votingRoundId, 'status' => $statusCode, 'response' => $response]
                );
            }
        } catch (\Throwable $e) {
            $this->markPublicationStatus(objectService: $objectService, round: $round, status: self::STATUS_PENDING);
            $this->logger->warning('Decidesk: ORI publication error', ['id' => $votingRoundId, 'error' => $e->getMessage()]);
        }//end try

    }//end publish()

    /**
     * Get the current publication status for a VotingRound.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return string pending | published | not_configured
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
     */
    public function getPublicationStatus(string $votingRoundId): string
    {
        $endpoint = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_ORI_ENDPOINT, '');
        if ($endpoint === '') {
            return self::STATUS_NOT_CONFIGURED;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $round         = $objectService->getObject(register: 'decidesk', schema: 'voting-round', uuid: $votingRoundId);

        if ($round === null) {
            return self::STATUS_NOT_CONFIGURED;
        }

        foreach (($round['notes'] ?? []) as $note) {
            if (($note['title'] ?? '') === 'ORI Publication Status') {
                $body = json_decode($note['body'] ?? '{}', true);
                return ($body['status'] ?? self::STATUS_PENDING);
            }
        }

        return self::STATUS_PENDING;

    }//end getPublicationStatus()

    /**
     * Build a JSON-LD payload following ORI 1.0 standard for a VotingRound.
     *
     * @param string              $votingRoundId The voting round UUID
     * @param array<string,mixed> $round         The VotingRound object data
     *
     * @return array<string,mixed>
     */
    private function buildJsonLd(string $votingRoundId, array $round): array
    {
        return [
            '@context'     => 'https://schema.openraadsinformatie.nl/1.0/context.jsonld',
            '@type'        => 'stemming',
            '@id'          => "urn:decidesk:voting-round:{$votingRoundId}",
            'result'       => ($round['result'] ?? null),
            'votesFor'     => ($round['votesFor'] ?? 0),
            'votesAgainst' => ($round['votesAgainst'] ?? 0),
            'votesAbstain' => ($round['votesAbstain'] ?? 0),
            'openedAt'     => ($round['openedAt'] ?? null),
            'closedAt'     => ($round['closedAt'] ?? null),
            'quorumMet'    => ($round['quorumMet'] ?? null),
            'votingMethod' => ($round['votingMethod'] ?? null),
        ];

    }//end buildJsonLd()

    /**
     * Write publication status into a note on the VotingRound object.
     *
     * @param object              $objectService The OpenRegister ObjectService
     * @param array<string,mixed> $round         The VotingRound object
     * @param string              $status        The status to record
     *
     * @return void
     */
    private function markPublicationStatus(object $objectService, array $round, string $status): void
    {
        $notes   = ($round['notes'] ?? []);
        $updated = false;

        foreach ($notes as $idx => $note) {
            if (($note['title'] ?? '') === 'ORI Publication Status') {
                $notes[$idx]['body'] = json_encode(['status' => $status, 'updatedAt' => date(\DateTimeInterface::ATOM)]);
                $updated = true;
                break;
            }
        }

        if ($updated === false) {
            $notes[] = [
                'title' => 'ORI Publication Status',
                'body'  => json_encode(['status' => $status, 'updatedAt' => date(\DateTimeInterface::ATOM)]),
            ];
        }

        $round['notes'] = $notes;

        try {
            $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: failed to save publication status', ['error' => $e->getMessage()]);
        }

    }//end markPublicationStatus()
}//end class
