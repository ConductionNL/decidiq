<?php
/**
 * Decidesk Board Voting Service
 *
 * Records board votes (named, anonymous, proxy), enforces vote-change
 * prevention after close, and computes resolution adoption against thresholds.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Server-authoritative board voting and adoption computation.
 *
 * Anonymous ballots store an HMAC token instead of the board-member link, so a
 * store-admin cannot reverse a vote to a member; the token is still stable per
 * (member, resolution) pair so double-voting is prevented and quorum is provable.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
 */
class BoardVotingService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Constructor.
     *
     * @param ContainerInterface        $container     The DI container.
     * @param IAppConfig                $appConfig     The app config (for the HMAC secret).
     * @param BoardAuditLogService      $auditLog      The audit log service.
     * @param QuorumVerificationService $quorumService The quorum service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly BoardAuditLogService $auditLog,
        private readonly QuorumVerificationService $quorumService,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Normalise a saved object (or array) into an associative array.
     *
     * @param mixed               $saved    ObjectEntity or array.
     * @param array<string,mixed> $fallback Original payload when serialization is unavailable.
     *
     * @return array<string,mixed>
     */
    private function serializeResult(mixed $saved, array $fallback): array
    {
        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return $saved->jsonSerialize();
        }

        if (is_array($saved) === true) {
            return $saved;
        }

        return $fallback;

    }//end serializeResult()

    /**
     * Return the per-app HMAC secret for anonymous ballot tokens.
     *
     * Reuses the install-time decidesk voter_token_secret so the mapping from
     * (memberId, resolutionId) to token cannot be computed without the secret.
     *
     * @return string 64-char hex secret.
     */
    private function tokenSecret(): string
    {
        $secret = $this->appConfig->getValueString('decidesk', 'voter_token_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->appConfig->setValueString('decidesk', 'voter_token_secret', $secret);
        }

        return $secret;

    }//end tokenSecret()

    /**
     * Derive the stable anonymisation token for a (member, resolution) pair.
     *
     * @param string $boardMemberId BoardMember UUID.
     * @param string $resolutionId  Resolution UUID.
     *
     * @return string 64-char hex HMAC token.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function voterToken(string $boardMemberId, string $resolutionId): string
    {
        return hash_hmac('sha256', $boardMemberId.':'.$resolutionId, $this->tokenSecret());

    }//end voterToken()

    /**
     * Record a vote on a resolution.
     *
     * Rejects the vote if the resolution's voting is not open. For anonymous
     * resolutions the board-member relation is omitted and only the HMAC token
     * is stored. Idempotent per (member, resolution): a second cast updates the
     * existing ballot while voting is open.
     *
     * @param string $resolutionId  Resolution UUID.
     * @param string $boardMemberId BoardMember UUID.
     * @param string $vote          Vote enum value.
     * @param string $voteMethod    Vote-method enum value.
     * @param string $actorUuid     Acting user/participant UUID (for audit).
     *
     * @return array<string,mixed> The persisted ballot.
     *
     * @throws \RuntimeException When voting is closed or the resolution is missing.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function castVote(string $resolutionId, string $boardMemberId, string $vote, string $voteMethod, string $actorUuid): array
    {
        $objectService = $this->objectService();
        $resolution    = $objectService->find(id: $resolutionId, register: self::REGISTER, schema: 'resolution');
        if ($resolution === null) {
            throw new \RuntimeException('Resolution not found');
        }

        $resolutionData = $resolution->jsonSerialize();
        if (($resolutionData['voteOpen'] ?? false) !== true) {
            throw new \RuntimeException('Voting is not open for this resolution');
        }

        $allowedVotes = ['in-favor', 'against', 'abstain', 'absent', 'recused-due-to-conflict'];
        if (in_array($vote, $allowedVotes, true) === false) {
            throw new \RuntimeException('Invalid vote value');
        }

        $anonymized = (($resolutionData['voteType'] ?? 'named') === 'anonymous');
        $token      = $this->voterToken(boardMemberId: $boardMemberId, resolutionId: $resolutionId);

        $ballot = [
            'vote'          => $vote,
            'voteTimestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'voteMethod'    => $voteMethod,
            'anonymized'    => $anonymized,
            'voterToken'    => $token,
        ];

        // Find an existing ballot for idempotency (by token, which is stable).
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-vote');
        $existing     = $objectService->findAll(['filters' => ['voterToken' => $token]]);
        $existingUuid = null;
        foreach (($existing['results'] ?? $existing) as $item) {
            $data         = $this->serializeResult(saved: $item, fallback: []);
            $existingUuid = ($data['id'] ?? ($data['uuid'] ?? null));
            break;
        }

        if ($anonymized === false) {
            $ballot['relations'] = [
                ['schema' => 'resolution', 'id' => $resolutionId],
                ['schema' => 'board-member', 'id' => $boardMemberId],
            ];
        } else {
            $ballot['relations'] = [['schema' => 'resolution', 'id' => $resolutionId]];
        }

        if ($existingUuid !== null) {
            $saved = $objectService->saveObject(register: self::REGISTER, schema: 'board-vote', object: $ballot, uuid: (string) $existingUuid);
        } else {
            $saved = $objectService->saveObject(register: self::REGISTER, schema: 'board-vote', object: $ballot);
        }

        $this->auditLog->append(actorUuid: $actorUuid, action: 'vote', objectUids: [$resolutionId]);

        return $this->serializeResult(saved: $saved, fallback: $ballot);

    }//end castVote()

    /**
     * Compute adoption status of a resolution against its threshold.
     *
     * Counts in-favor / against / abstain / recused ballots and compares the
     * in-favor count to the threshold computed against total seats.
     *
     * @param string $resolutionId Resolution UUID.
     * @param int    $totalSeats   Total board seats for threshold computation.
     *
     * @return array{inFavor:int,against:int,abstain:int,recused:int,required:int,adopted:bool}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function computeResolutionAdoption(string $resolutionId, int $totalSeats): array
    {
        $objectService = $this->objectService();
        $resolution    = $objectService->find(id: $resolutionId, register: self::REGISTER, schema: 'resolution');
        $threshold     = 'simple-majority';
        if ($resolution !== null) {
            $threshold = (string) ($resolution->jsonSerialize()['voteThreshold'] ?? 'simple-majority');
        }

        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-vote');
        $result = $objectService->findAll(['filters' => ['relations.resolution' => $resolutionId]]);

        $tally = ['in-favor' => 0, 'against' => 0, 'abstain' => 0, 'recused-due-to-conflict' => 0, 'absent' => 0];
        foreach (($result['results'] ?? $result) as $item) {
            $data  = $this->serializeResult(saved: $item, fallback: []);
            $value = (string) ($data['vote'] ?? '');
            if (isset($tally[$value]) === true) {
                $tally[$value]++;
            }
        }

        $required = $this->quorumService->requiredVotesFor($threshold, $totalSeats);

        return [
            'inFavor'  => $tally['in-favor'],
            'against'  => $tally['against'],
            'abstain'  => $tally['abstain'],
            'recused'  => $tally['recused-due-to-conflict'],
            'required' => $required,
            'adopted'  => ($tally['in-favor'] >= $required),
        ];

    }//end computeResolutionAdoption()

    /**
     * Close voting on a resolution and persist its adoption status.
     *
     * @param string $resolutionId Resolution UUID.
     * @param int    $totalSeats   Total board seats.
     * @param string $actorUuid    Acting user UUID (for audit).
     *
     * @return array<string,mixed> The adoption outcome.
     *
     * @throws \RuntimeException When the resolution is missing.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function closeVote(string $resolutionId, int $totalSeats, string $actorUuid): array
    {
        $objectService = $this->objectService();
        $resolution    = $objectService->find(id: $resolutionId, register: self::REGISTER, schema: 'resolution');
        if ($resolution === null) {
            throw new \RuntimeException('Resolution not found');
        }

        $outcome = $this->computeResolutionAdoption(resolutionId: $resolutionId, totalSeats: $totalSeats);

        $data = $resolution->jsonSerialize();
        $data['voteOpen'] = false;
        $data['status']   = 'rejected';
        if ($outcome['adopted'] === true) {
            $data['status'] = 'adopted';
        }

        if ($outcome['adopted'] === true) {
            $data['adoptionDate'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        }

        $objectService->saveObject(register: self::REGISTER, schema: 'resolution', object: $data, uuid: $resolutionId);
        $this->auditLog->append(actorUuid: $actorUuid, action: 'vote', objectUids: [$resolutionId]);

        return $outcome;

    }//end closeVote()
}//end class
