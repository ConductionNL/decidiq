<?php

/**
 * Contract of ParticipationResponder
 *
 * The public surface of ParticipationResponder that other components call, so they depend
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of ParticipationResponder.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ParticipationResponderInterface {

	/**
	 * Run an authenticated-citizen service call and map its outcome to a response.
	 *
	 * @param callable $operation The service call to run.
	 * @param string|null $uid The acting UID the caller resolved from the session; null refuses.
	 * @param string|null $key Envelope key, or null to return the raw payload.
	 * @param int $status The success HTTP status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function citizenAction(callable $operation, ?string $uid, ?string $key = null, int $status = Http::STATUS_OK): JSONResponse;

	/**
	 * Resolve the acting user's session-derived UID.
	 *
	 * Exposed so the routed controller method can bind the acting identity in
	 * its OWN body and hand it to the service call alongside the caller-supplied
	 * object id. That hand-off used to happen inside `citizenAction()`, which
	 * passed the UID into the operation closure as a parameter — behaviourally
	 * identical, but it hid the provenance of the recorded submitter/voter
	 * identity from anything reading the endpoint, human or mechanical. The
	 * value is ALWAYS the session's; no participation endpoint accepts a
	 * submitter, voter or author identity from the request.
	 *
	 * @return string|null The acting UID, or null when no user is signed in.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function currentUid(): ?string;

	/**
	 * Run a staff-guarded service call and map its outcome to a response.
	 *
	 * @param callable $operation The service call to run.
	 * @param string|null $key Envelope key, or null to return the raw payload.
	 * @param int $status The success HTTP status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function staffAction(callable $operation, ?string $key = null, int $status = Http::STATUS_OK): JSONResponse;
}//end interface
