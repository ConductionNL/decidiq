<?php
/**
 * Decidesk Voting Open Request Handler
 *
 * Turns a POST /api/voting-rounds body into an opened voting round: parses and
 * validates the request, assembles the configurable decision rules, and calls
 * the voting service.
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
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/process-configuration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The open-a-voting-round request.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingOpenRequestHandler
{

    /**
     * Request parsing + validation for opening a voting round.
     *
     * @var VotingOpenRequestParser
     */
    private readonly VotingOpenRequestParser $parser;

    /**
     * Constructor for VotingOpenRequestHandler.
     *
     * @param VotingService $votingService The voting service
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly VotingService $votingService,
    ) {
        $this->parser = new VotingOpenRequestParser();

    }//end __construct()

    /**
     * Parse the request body and open the round.
     *
     * For subjectType=amendment, motionId carries the AMENDMENT UUID; the
     * parliamentary ordering rules (amendments before the motion, chair-set order)
     * are enforced server-side by VotingService (fail closed).
     *
     * @param array<string, mixed> $params The request parameters
     *
     * @return JSONResponse 201 with the created round, or 400 with the validation message
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     */
    public function handle(array $params): JSONResponse
    {
        $request = $this->parser->parse(params: $params);
        if ($request['error'] !== null) {
            return new JSONResponse(['message' => $request['error']], Http::STATUS_BAD_REQUEST);
        }

        $round = $request['payload'];

        $opened = $this->votingService->openVotingRound(
            motionId: $round['motionId'],
            meetingId: $round['meetingId'],
            votingMethod: $round['votingMethod'],
            isSecret: $round['isSecret'],
            closedAt: $round['closedAt'],
            presetParticipantIds: $round['presetIds'],
            revoteOfRoundId: $round['revoteOfRoundId'],
            roundRules: new VotingRoundRules(
                voteThreshold: $round['voteThreshold'],
                abstentionHandling: $round['abstentionHandling'],
                tieBreakRule: $round['tieBreakRule'],
                subjectType: $round['subjectType'],
                governanceBodyId: $round['governanceBodyId']
            )
        );

        return new JSONResponse($opened, Http::STATUS_CREATED);

    }//end handle()
}//end class
