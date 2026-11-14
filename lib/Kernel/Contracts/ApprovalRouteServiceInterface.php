<?php

/**
 * Contract of ApprovalRouteService
 *
 * The public surface of ApprovalRouteService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use OCA\Decidiq\Service\ApprovalPrincipal;
use OCA\Decidiq\Service\HeldRouteRequest;
use RuntimeException;

/**
 * Contract of ApprovalRouteService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalRouteServiceInterface {

	/**
	 * Refuse a caller who cannot reach the subject.
	 *
	 * A REAL authorisation check, not an authentication one. Instantiating a
	 * route writes sign-off stages against someone else's object, so "is signed
	 * in" is not the question — "may this user see this subject" is.
	 *
	 * The check is delegated to OpenRegister: the read runs as the acting user,
	 * so OR's register RBAC and multitenancy decide. A user who cannot reach the
	 * subject gets nothing back, and nothing back is a refusal.
	 *
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema slug.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the subject cannot be reached.
	 *
	 * @spec openspec/specs/approval-routes/spec.md
	 */
	public function assertSubjectAccessible(string $subject, string $subjectSchema): void;

	/**
	 * The held-route request for an ad-hoc route as a caller sent it: the
	 * people its steps name, in step order, under the route's name.
	 *
	 * @param array<string, mixed> $route         The route as the caller sent it.
	 * @param string               $subject       The subject's uuid.
	 * @param string               $subjectSchema The subject's schema slug.
	 *
	 * @return HeldRouteRequest The request, without deadline or substitute terms.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	public function heldRouteFrom(array $route, string $subject, string $subjectSchema): HeldRouteRequest;

	/**
	 * Hold a route on a subject from a list of named people, with no stored
	 * template.
	 *
	 * The everyday case a template cannot serve: a clerk with a document in
	 * front of them and three colleagues who have to look at it, in that order,
	 * before the end of the month. Writing an `ApprovalRoute` row first would
	 * leave a template nobody reuses on every such review.
	 *
	 * The stages are the engine's ordinary ones, so everything that already
	 * works on a route works on this one: the guard, the return verbs, the
	 * parallel groups, the conclusion announcement.
	 *
	 * Every step is an endorsement by one person.
	 *
	 * @param HeldRouteRequest $request The subject, the people in order, the
	 *        route's name, and its deadline and substitute fraction (REQ-AR-016, #1397).
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 *
	 * @throws RuntimeException When no actor is named.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008, REQ-AR-009)
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function holdFor(HeldRouteRequest $request): array;

	/**
	 * Materialise a route's steps as stages against a subject.
	 *
	 * Idempotent: a subject that already has stages is left alone, so a repeated
	 * call cannot give it a second route.
	 *
	 * A stage's `sequence` is the step's OWN `order`, not its position: the
	 * parafering surfaces read the step number and it must mean what the route
	 * meant, and two steps DECLARING the same order are a parallel group that
	 * signs side by side. Steps without an order fall back to their position.
	 *
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema.
	 * @param ApprovalPrincipal $principal Who is asking. Defaults to Ordinary,
	 *        which is the fail-closed answer: a caller that says nothing about
	 *        who it is does not get to declare that silence approves.
	 *
	 * @return array<int, array<string, mixed>> The stages, existing or created.
	 *
	 * @throws RuntimeException When the route declares no usable steps, or
	 *         declares a silence this principal may not set.
	 *
	 * @spec openspec/specs/approval-routes/spec.md
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function instantiate(
		array $route,
		string $subject,
		string $subjectSchema,
		ApprovalPrincipal $principal = ApprovalPrincipal::Ordinary,
	): array;

	/**
	 * Record an action and advance the route.
	 *
	 * THE STAGE WRITE COMES FIRST, the action row after. The old order appended
	 * the action and THEN patched the stage, so a stage write that failed left
	 * an orphan action row claiming a sign-off the route never took — and the
	 * signer's retry appended another. With this order a failed stage write
	 * throws before any action row exists, so the retry starts clean. The
	 * inverse gap (stage advanced, action append failed) surfaces loudly: the
	 * caller gets the throw, and a retry is refused by the guard because the
	 * next stage names a different actor.
	 *
	 * @param array<string, mixed> $action The action: subject, step, actor, action, and optional fields.
	 *
	 * @return array<string, mixed> The recorded action.
	 *
	 * @throws RuntimeException When the action is refused.
	 *
	 * @spec openspec/specs/approval-routes/spec.md
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	public function record(array $action): array;

	/**
	 * The routes on a subject, each with its stages, shaped for the clearance
	 * question.
	 *
	 * A stage whose `route` is empty is a route in its own right: cross-app
	 * routes are held without a stored template, and dropping them here would
	 * make a subject with only such a route read as cleared while somebody is
	 * still waiting on it.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The routes with their stages.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function routesWithStagesFor(string $subject): array;

	/**
	 * The subject's stages, ordered by sequence.
	 *
	 * PUBLIC because the cross-app command seam needs to answer "did that action
	 * finish the route" and must do it by asking THIS class, not by running its
	 * own query. A second reader of decision-stage rows is how the seam and the
	 * engine start to disagree about what a route's state is.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 *
	 * @spec openspec/specs/approval-route-events/spec.md
	 */
	public function stagesFor(string $subject): array;
}//end interface
