<?php
/**
 * Decidesk Board Voting Service
 *
 * Server-authoritative board-resolution voting: vote casting with optional HMAC
 * anonymization, adoption computation against the resolution threshold, and board
 * loading. HMAC keying provides cryptographic unlinkability of anonymous votes.
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

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Board resolution voting and adoption computation.
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
     * Constructor for BoardVotingService.
     *
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     * @param IAppConfig         $appConfig       App config (HMAC anonymization secret).
     * @param AuditLogService    $auditLogService The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $appConfig,
        private readonly AuditLogService $auditLogService,
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
     * Resolve the board-vote anonymization secret, initializing it once if missing.
     *
     * @return string The anonymization secret.
     */
    private function anonymizationSecret(): string
    {
        $secret = $this->appConfig->getValueString('decidesk', 'board_vote_hmac_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->appConfig->setValueString('decidesk', 'board_vote_hmac_secret', $secret);
        }

        return $secret;

    }//end anonymizationSecret()

    /**
     * Compute the per-resolution HMAC of a member identity for anonymous voting.
     *
     * The HMAC is keyed by the app secret and the resolution id, so the same member
     * produces a stable token within a resolution (preventing double-voting) while the
     * token is not reversible to the member identity.
     *
     * @param string $resolutionId  Resolution UUID (acts as voting-round key material).
     * @param string $boardMemberId BoardMember UUID.
     *
     * @return string The anonymization token, prefixed with 'hmac:'.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function anonymizeMember(string $resolutionId, string $boardMemberId): string
    {
        $key = hash_hmac('sha256', $resolutionId, $this->anonymizationSecret());
        return 'hmac:'.hash_hmac('sha256', $boardMemberId, $key);

    }//end anonymizeMember()

    /**
     * Cast a board vote, optionally anonymizing the member link via HMAC.
     *
     * @param array $voteData   Vote payload: resolution-koppeling, board-member-koppeling, vote, vote-method, proxy-holder.
     * @param bool  $anonymized Whether to anonymize the member link.
     *
     * @return array The serialized created vote.
     *
     * @throws \InvalidArgumentException When required fields are missing.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function saveVote(array $voteData, bool $anonymized): array
    {
        $resolutionId = (string) ($voteData['resolution-koppeling'] ?? '');
        $memberId     = (string) ($voteData['board-member-koppeling'] ?? '');
        $choice       = (string) ($voteData['vote'] ?? '');

        if ($resolutionId === '' || $memberId === '' || $choice === '') {
            throw new InvalidArgumentException('resolution-koppeling, board-member-koppeling and vote are required.');
        }

        $stored = $memberId;
        if ($anonymized === true) {
            $stored = $this->anonymizeMember(resolutionId: $resolutionId, boardMemberId: $memberId);
        }

        $vote = [
            'resolution-koppeling'   => $resolutionId,
            'board-member-koppeling' => $stored,
            'vote'                   => $choice,
            'vote-timestamp'         => gmdate('Y-m-d\TH:i:s\Z'),
            'vote-method'            => (string) ($voteData['vote-method'] ?? 'electronic'),
            'proxy-holder'           => ($voteData['proxy-holder'] ?? null),
            'anonymized'             => $anonymized,
        ];

        $saved = $this->objectService()->saveObject(register: self::REGISTER, schema: 'board-vote', object: $vote);
        $data  = $saved->jsonSerialize();

        // The audit trail records the (un-anonymized) member only via the action actor when named;
        // for anonymous votes the actor is the HMAC token, preserving unlinkability.
        $actor = $memberId;
        if ($anonymized === true) {
            $actor = $stored;
        }

        $this->auditLogService->append(
            actor: $actor,
            action: 'vote',
            objectUids: [$resolutionId, (string) ($data['id'] ?? '')]
        );

        return $data;

    }//end saveVote()

    /**
     * Load a board together with all of its members in one call.
     *
     * @param string $boardId Board UUID.
     *
     * @return array{board: array|null, members: array<int, array>} Board and members.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function loadBoardWithMembers(string $boardId): array
    {
        $objectService = $this->objectService();
        $boardEntity   = $objectService->find(id: $boardId, register: self::REGISTER, schema: 'board');
        $board         = null;
        if ($boardEntity !== null) {
            $board = $boardEntity->jsonSerialize();
        }

        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-member');
        $memberEntities = $objectService->findAll(['filters' => ['board-koppeling' => $boardId]]);

        $members = [];
        foreach ($memberEntities as $entity) {
            $members[] = $entity->jsonSerialize();
        }//end foreach

        return [
            'board'   => $board,
            'members' => $members,
        ];

    }//end loadBoardWithMembers()

    /**
     * Compute the adoption status of a resolution against its threshold.
     *
     * @param string $resolutionId Resolution UUID.
     *
     * @return array{in_favor: int, against: int, abstain: int, threshold: string, adopted: bool}
     *
     * @throws \RuntimeException When the resolution does not exist.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function computeResolutionAdoption(string $resolutionId): array
    {
        $objectService    = $this->objectService();
        $resolutionEntity = $objectService->find(id: $resolutionId, register: self::REGISTER, schema: 'resolution');
        if ($resolutionEntity === null) {
            throw new RuntimeException('Resolution '.$resolutionId.' not found.');
        }

        $resolution = $resolutionEntity->jsonSerialize();
        $threshold  = (string) ($resolution['vote-threshold'] ?? 'simple-majority');
        $eligible   = (int) ($resolution['eligible-voter-count'] ?? 0);

        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-vote');
        $votes = $objectService->findAll(['filters' => ['resolution-koppeling' => $resolutionId]]);

        $inFavor = 0;
        $against = 0;
        $abstain = 0;
        foreach ($votes as $entity) {
            $choice = (string) ($entity->jsonSerialize()['vote'] ?? '');
            if ($choice === 'in-favor') {
                $inFavor++;
            } else if ($choice === 'against') {
                $against++;
            } else if ($choice === 'abstain') {
                $abstain++;
            }
        }//end foreach

        $adopted = $this->thresholdMet(
            threshold: $threshold,
            inFavor: $inFavor,
            against: $against,
            eligible: $eligible
        );

        return [
            'in_favor'  => $inFavor,
            'against'   => $against,
            'abstain'   => $abstain,
            'threshold' => $threshold,
            'adopted'   => $adopted,
        ];

    }//end computeResolutionAdoption()

    /**
     * Evaluate whether a vote tally meets a threshold.
     *
     * @param string $threshold Threshold enum value.
     * @param int    $inFavor   Votes in favor.
     * @param int    $against   Votes against.
     * @param int    $eligible  Eligible voter count.
     *
     * @return bool True when the threshold is met.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function thresholdMet(string $threshold, int $inFavor, int $against, int $eligible): bool
    {
        switch ($threshold) {
            case 'unanimous':
                return ($inFavor > 0 && $against === 0);
            case 'qualified-majority-two-thirds':
                return ($eligible > 0 && ($inFavor * 3) >= ($eligible * 2));
            case 'qualified-majority-three-quarters':
                return ($eligible > 0 && ($inFavor * 4) >= ($eligible * 3));
            case 'simple-majority':
            default:
                return ($inFavor > $against);
        }

    }//end thresholdMet()
}//end class
