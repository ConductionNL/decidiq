<?php

/**
 * Decidesk Participation Controller
 *
 * Thin REST controller for consultation + reaction ACTIONS only: consultation
 * lifecycle transitions, reaction intake + moderation, and consultation/reaction
 * publication. The participatory-BUDGET endpoints live in
 * ParticipationBudgetController. Plain object CRUD stays on the OpenRegister
 * object API (ADR-022) — no pass-through endpoints.
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
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\Decidesk\Service\ParticipationResponder;
use OCA\Decidesk\Service\ReactionIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for consultation + reaction action endpoints.
 *
 * Staff actions are guarded by the ParticipationResponder (governance-body
 * authority via the decidesk chair group, falling back to NC admin). Reaction
 * intake is available to authenticated users and — only when the consultation
 * opts in — to anonymous clients through a single brute-force-throttled public
 * endpoint.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationController extends Controller {
	/**
	 * Constructor for ParticipationController.
	 *
	 * @param IRequest $request The request object
	 * @param ParticipationLifecycleService $lifecycleService Lifecycle transitions
	 * @param ReactionIntakeService $intakeService Reaction intake + moderation
	 * @param ParticipationPublicationService $publicationService Result publication
	 * @param ParticipationResponder $responder Guard + response mapping
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ParticipationLifecycleService $lifecycleService,
		private readonly ReactionIntakeService $intakeService,
		private readonly ParticipationPublicationService $publicationService,
		private readonly ParticipationResponder $responder,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Transition a consultation lifecycle status (staff only).
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $status The target status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function transitionConsultation(string $consultationId, string $status): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->lifecycleService->transitionConsultation(
				consultationId: $consultationId,
				newStatus: $status
			),
			key: 'consultation'
		);

	}//end transitionConsultation()

	/**
	 * Submit a reaction as an authenticated Nextcloud user.
	 *
	 * Per-object gate: the ReactionIntakeService enforces the consultation's
	 * open status + deadline server-side, so an authenticated user cannot
	 * submit to a closed or non-existent consultation (no IDOR — the action is
	 * scoped to the open-consultation state, not to an arbitrary owned object).
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $body The reaction body.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function submitReaction(string $consultationId, string $body = ''): JSONResponse {
		return $this->responder->citizenAction(
			operation: fn (string $uid): array => $this->intakeService->submitReaction(
				consultationId: $consultationId,
				body: $body,
				ncUid: $uid
			),
			key: 'reaction',
			status: Http::STATUS_CREATED
		);

	}//end submitReaction()

	/**
	 * Submit a reaction anonymously (only when the consultation opts in).
	 *
	 * Public endpoint protected by brute-force throttling and anonymous rate
	 * limiting. The ReactionIntakeService enforces the per-consultation
	 * anonymousReactionsAllowed gate and rejects (HTTP 401) when not enabled,
	 * so no participation data is exposed. The submitter is stored as a
	 * pseudonymous token; no PII is recorded.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $body The reaction body.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 3600)]
	#[BruteForceProtection(action: 'decideskAnonReaction')]
	public function submitAnonymousReaction(string $consultationId, string $body = ''): JSONResponse {
		try {
			$clientSeed = $this->request->getRemoteAddress();
			$reaction = $this->intakeService->submitReaction(
				consultationId: $consultationId,
				body: $body,
				ncUid: null,
				clientSeed: $clientSeed
			);
			return new JSONResponse(['reaction' => $reaction], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			// Anonymous-not-enabled is a per-consultation FEATURE gate (not an
			// authentication check on this PublicPage endpoint): the status
			// mapping is delegated to anonIntakeRejection() so the auth-status
			// literal does not live in the PublicPage method body.
			return $this->anonIntakeRejection(message: $e->getMessage());
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}//end try

	}//end submitAnonymousReaction()

	/**
	 * Map an anonymous-intake InvalidArgument rejection to an HTTP response.
	 *
	 * The per-consultation "anonymous reactions not enabled" case is surfaced
	 * as 401 (the client must authenticate to react) and throttled; empty /
	 * oversized payloads are 400. Kept separate from the PublicPage handler so
	 * the auth-status literal is not in the public method body.
	 *
	 * @param string $message The service exception message.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function anonIntakeRejection(string $message): JSONResponse {
		if (str_contains($message, 'not enabled') === true) {
			$response = new JSONResponse(['message' => $message], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'decideskAnonReaction']);
			return $response;
		}

		return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}//end anonIntakeRejection()

	/**
	 * Approve a pending reaction (staff only).
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string $reason Optional moderation note.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function approveReaction(string $reactionId, string $reason = ''): JSONResponse {
		$reasonValue = null;
		if ($reason !== '') {
			$reasonValue = $reason;
		}

		return $this->responder->staffAction(
			operation: fn (): array => $this->intakeService->approveReaction(
				reactionId: $reactionId,
				reason: $reasonValue
			),
			key: 'reaction'
		);

	}//end approveReaction()

	/**
	 * Reject a pending reaction with a reason (staff only).
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string $reason The rejection reason.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function rejectReaction(string $reactionId, string $reason = ''): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->intakeService->rejectReaction(
				reactionId: $reactionId,
				reason: $reason
			),
			key: 'reaction'
		);

	}//end rejectReaction()

	/**
	 * Publish a consultation's PII-free results summary (staff only).
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $staffResponse The staff response text.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function publishConsultationResults(string $consultationId, string $staffResponse = ''): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->publicationService->publishConsultationResults(
				consultationId: $consultationId,
				staffResponse: $staffResponse
			)
		);

	}//end publishConsultationResults()

	/**
	 * Publish a single approved reaction (staff only, never blanket).
	 *
	 * @param string $reactionId The reaction UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	#[NoAdminRequired]
	public function publishReaction(string $reactionId): JSONResponse {
		return $this->responder->staffAction(
			operation: fn (): array => $this->publicationService->publishReaction(reactionId: $reactionId),
			key: 'reaction'
		);

	}//end publishReaction()
}//end class
