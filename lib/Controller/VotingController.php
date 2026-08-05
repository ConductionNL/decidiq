<?php
/**
 * Decidesk Voting Controller
 *
 * Thin REST controller for voting round management, vote casting, and proxy
 * delegation. Every endpoint is guard -> read input -> delegate; the
 * exception-to-status mapping lives in VotingErrorResponder and the
 * open-a-round request shape lives in VotingOpenRequestHandler.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ProxyDelegationService;
use OCA\Decidesk\Service\VotingErrorResponder;
use OCA\Decidesk\Service\VotingOpenRequestHandler;
use OCA\Decidesk\Service\VotingRoundGuard;
use OCA\Decidesk\Service\VotingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for voting round API endpoints.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingController extends Controller
{
    /**
     * Constructor for VotingController.
     *
     * @param IRequest                 $request       The request object
     * @param VotingService            $votingService The voting service
     * @param OriPublicationService    $oriService    The ORI publication service
     * @param IUserSession             $userSession   The user session
     * @param VotingRoundGuard         $guard         Per-meeting authorisation guard
     * @param VotingOpenRequestHandler $openHandler   Open-a-round request handling
     * @param ProxyDelegationService   $proxyService  Proxy (volmacht) grant / revoke
     * @param VotingErrorResponder     $errors        Exception-to-status mapping
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        IRequest $request,
        private readonly VotingService $votingService,
        private readonly OriPublicationService $oriService,
        private readonly IUserSession $userSession,
        private readonly VotingRoundGuard $guard,
        private readonly VotingOpenRequestHandler $openHandler,
        private readonly ProxyDelegationService $proxyService,
        private readonly VotingErrorResponder $errors,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Open a new VotingRound.
     *
     * POST /api/voting-rounds
     * Body: { "motionId": "uuid", "meetingId": "uuid", "votingMethod": "for-against-abstain",
     *         "isSecret": false, "closedAt": null, "presetParticipantIds": ["uuid1", "uuid2"],
     *         "voteThreshold": "simple-majority", "abstentionHandling": "exclude",
     *         "tieBreakRule": "rejected", "revoteOfRound": "uuid|null",
     *         "subjectType": "motion|amendment" }
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function open(): JSONResponse
    {
        $params    = $this->request->getParams();
        $meetingId = ($params['meetingId'] ?? '');

        // Per-meeting chair/secretary check: use the meetingId from the request body if present.
        $guardMeetingId = null;
        if ($meetingId !== '') {
            $guardMeetingId = $meetingId;
        }

        $guard = $this->guard->requireChairOrSecretary(meetingId: $guardMeetingId);
        if ($guard !== null) {
            return $guard;
        }

        return $this->errors->badRequest(fn (): JSONResponse => $this->openHandler->handle(params: $params));

    }//end open()

    /**
     * Cast a vote in a VotingRound.
     *
     * POST /api/voting-rounds/{id}/cast
     * Body: { "participantId": "uuid", "value": "for", "isProxy": false, "delegatorId": null }
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/user-settings/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function cast(string $id): JSONResponse
    {
        // Derive participant identity from the authenticated session — never trust client input.
        $nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($nextcloudUid === '') {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Resolve the OpenRegister participant UUID for this Nextcloud user.
        $participantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
        if ($participantId === null) {
            return new JSONResponse(['message' => 'Geen deelnemersprofiel gevonden voor de ingelogde gebruiker'], Http::STATUS_FORBIDDEN);
        }

        $params      = $this->request->getParams();
        $value       = ($params['value'] ?? '');
        $isProxy     = (bool) ($params['isProxy'] ?? false);
        $delegatorId = null;
        if (isset($params['delegatorId']) === true && $params['delegatorId'] !== '') {
            $delegatorId = $params['delegatorId'];
        }

        if ($value === '') {
            return new JSONResponse(['message' => 'value is required'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($value, ['for', 'against', 'abstain'], true) === false) {
            return new JSONResponse(['message' => 'value must be for, against, or abstain'], Http::STATUS_BAD_REQUEST);
        }

        return $this->errors->badRequest(
            fn (): JSONResponse => new JSONResponse(
                $this->votingService->castVote(
                    votingRoundId: $id,
                    participantId: $participantId,
                    value: $value,
                    isProxy: $isProxy,
                    delegatorId: $delegatorId,
                    callerUid: $nextcloudUid
                ),
                Http::STATUS_CREATED
            )
        );

    }//end cast()

    /**
     * Close a VotingRound, optionally anonymising votes.
     *
     * POST /api/voting-rounds/{id}/close
     * Body: { "anonymise": true|false, "chairCasting": "for"|"against" (optional) }
     *
     * When `chairCasting` is present it is the chair's casting vote resolving a
     * tie under tieBreakRule 'chair-decides'. It requires the per-meeting CHAIR
     * role (secretary does not suffice) and is refused on rounds with a
     * different tie-break rule (fail closed in the service).
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function close(string $id): JSONResponse
    {
        // Resolve the meeting from this voting round's motion chain for per-meeting auth.
        $meetingId = $this->guard->resolveMeetingIdFromVotingRound(votingRoundId: $id);
        $guard     = $this->guard->requireChairOrSecretary(meetingId: $meetingId);
        if ($guard !== null) {
            return $guard;
        }

        $params    = $this->request->getParams();
        $anonymise = isset($params['anonymise']) && $params['anonymise'] === true;

        $chairCasting = null;
        if (isset($params['chairCasting']) === true && $params['chairCasting'] !== '') {
            // The casting vote is the chair's personal prerogative — require the
            // chair role specifically (fail closed; secretary may close, not cast).
            $chairGuard = $this->guard->requireChair(meetingId: $meetingId);
            if ($chairGuard !== null) {
                return $chairGuard;
            }

            $chairCasting = (string) $params['chairCasting'];
            if (in_array($chairCasting, ['for', 'against'], true) === false) {
                return new JSONResponse(['message' => 'chairCasting must be for or against'], Http::STATUS_BAD_REQUEST);
            }
        }

        // Casting-vote refusals are client errors; a missing round is a 404.
        return $this->errors->badRequestOrNotFound(
            fn (): JSONResponse => new JSONResponse($this->closeRound(votingRoundId: $id, anonymise: $anonymise, chairCasting: $chairCasting))
        );

    }//end close()

    /**
     * Publish VotingRound result to ORI.
     *
     * POST /api/voting-rounds/{id}/publish
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/voting-result-publication/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function publish(string $id): JSONResponse
    {
        $guard = $this->guard->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        return $this->errors->internalError(
            fn (): JSONResponse => new JSONResponse(['status' => $this->publishRound(votingRoundId: $id)]),
            'Decidesk: ORI publication failed',
            ['votingRoundId' => $id],
            'Publication failed'
        );

    }//end publish()

    /**
     * Grant proxy delegation.
     *
     * POST /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "uuid", "toParticipantId": "uuid" }
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/proxy-voting/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function proxy(string $id): JSONResponse
    {
        // Resolve the Nextcloud UID to an OpenRegister participant UUID before storing —
        // the proxy record must reference the same identifier type as castVote() uses.
        $nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($nextcloudUid === '') {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fromParticipantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
        if ($fromParticipantId === null) {
            return new JSONResponse(['message' => 'Geen deelnemersprofiel gevonden'], Http::STATUS_FORBIDDEN);
        }

        $params          = $this->request->getParams();
        $toParticipantId = ($params['toParticipantId'] ?? '');

        if ($toParticipantId === '') {
            return new JSONResponse(['message' => 'toParticipantId is required'], Http::STATUS_BAD_REQUEST);
        }

        return $this->errors->invalidOrMissing(
            function () use ($id, $fromParticipantId, $toParticipantId): JSONResponse {
                $this->proxyService->grantProxy(
                    votingRoundId: $id,
                    fromParticipantId: $fromParticipantId,
                    toParticipantId: $toParticipantId
                );

                return new JSONResponse(['success' => true]);
            }
        );

    }//end proxy()

    /**
     * Save a show-of-hands aggregate tally for an open VotingRound.
     *
     * POST /api/voting-rounds/{id}/tally
     * Body: { "votesFor": int, "votesAgainst": int, "votesAbstain": int }
     *
     * Restricted to chair/secretary. Only valid for show-of-hands rounds.
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function tally(string $id): JSONResponse
    {
        $guard = $this->guard->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params       = $this->request->getParams();
        $votesFor     = (int) ($params['votesFor'] ?? 0);
        $votesAgainst = (int) ($params['votesAgainst'] ?? 0);
        $votesAbstain = (int) ($params['votesAbstain'] ?? 0);

        return $this->errors->badRequest(
            fn (): JSONResponse => new JSONResponse(
                $this->votingService->saveShowOfHandsTally(
                    votingRoundId: $id,
                    votesFor: $votesFor,
                    votesAgainst: $votesAgainst,
                    votesAbstain: $votesAbstain,
                )
            )
        );

    }//end tally()

    /**
     * Revoke proxy delegation.
     *
     * DELETE /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "uuid" }
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/proxy-voting/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function revokeProxy(string $id): JSONResponse
    {
        // Resolve the Nextcloud UID to an OpenRegister participant UUID — must match
        // the identifier type stored by proxy() when the grant was created.
        $nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($nextcloudUid === '') {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fromParticipantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
        if ($fromParticipantId === null) {
            return new JSONResponse(['message' => 'Geen deelnemersprofiel gevonden'], Http::STATUS_FORBIDDEN);
        }

        return $this->errors->badRequest(
            function () use ($id, $fromParticipantId): JSONResponse {
                $this->proxyService->revokeProxy(votingRoundId: $id, fromParticipantId: $fromParticipantId);

                return new JSONResponse(['success' => true]);
            }
        );

    }//end revokeProxy()

    /**
     * Close the round through the variant the request asked for.
     *
     * Anonymisation is irreversible, so it is a distinct service method rather
     * than a boolean flag; this is the single place the request flag selects one.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param bool        $anonymise     Whether the request asked for anonymisation
     * @param string|null $chairCasting  The validated chair casting vote, when present
     *
     * @return array<string, mixed> The closed voting round object
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function closeRound(string $votingRoundId, bool $anonymise, ?string $chairCasting): array
    {
        if ($anonymise === true) {
            return $this->votingService->closeVotingRoundAnonymised(
                votingRoundId: $votingRoundId,
                chairCasting: $chairCasting
            );
        }

        return $this->votingService->closeVotingRound(votingRoundId: $votingRoundId, chairCasting: $chairCasting);

    }//end closeRound()

    /**
     * Publish the round to ORI and read back its publication status.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return mixed The publication status
     *
     * @spec openspec/specs/voting-result-publication/spec.md
     */
    private function publishRound(string $votingRoundId): mixed
    {
        $this->oriService->publish(votingRoundId: $votingRoundId);

        return $this->oriService->getPublicationStatus(votingRoundId: $votingRoundId);

    }//end publishRound()
}//end class
