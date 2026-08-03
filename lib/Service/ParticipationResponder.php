<?php
/**
 * Decidesk Participation Responder
 *
 * Runs one citizen-participation service call behind the right authorization
 * guard and maps its outcome onto a JSONResponse — the guard/try/catch ladder
 * every participation endpoint would otherwise repeat verbatim.
 *
 * Two entry points mirror the two audiences the participation API serves:
 * `staffAction()` for governance-body (staff) operations, and
 * `citizenAction()` for operations any authenticated citizen may perform, which
 * hands the acting UID to the operation. Both fail closed.
 *
 * Shared by ParticipationController and ParticipationBudgetController.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Guards and response-maps citizen-participation service calls.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationResponder
{
    /**
     * Constructor for ParticipationResponder.
     *
     * @param ParticipationStaffGuard $staffGuard Actor + staff authority
     *
     * @return void
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    public function __construct(
        private readonly ParticipationStaffGuard $staffGuard,
    ) {

    }//end __construct()

    /**
     * Run a staff-guarded service call and map its outcome to a response.
     *
     * @param callable    $operation The service call to run.
     * @param string|null $key       Envelope key, or null to return the raw payload.
     * @param int         $status    The success HTTP status.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    public function staffAction(callable $operation, ?string $key=null, int $status=Http::STATUS_OK): JSONResponse
    {
        return ($this->requireStaff() ?? $this->respond(operation: $operation, key: $key, status: $status));

    }//end staffAction()

    /**
     * Run an authenticated-citizen service call and map its outcome to a response.
     *
     * The operation receives the acting user's UID as its only argument.
     *
     * @param callable    $operation The service call to run, given the acting UID.
     * @param string|null $key       Envelope key, or null to return the raw payload.
     * @param int         $status    The success HTTP status.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    public function citizenAction(callable $operation, ?string $key=null, int $status=Http::STATUS_OK): JSONResponse
    {
        $uid = $this->staffGuard->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        return $this->respond(
            operation: static fn (): array => $operation($uid),
            key: $key,
            status: $status
        );

    }//end citizenAction()

    /**
     * Require the current user to hold staff (governance-body) authority.
     *
     * Returns a 401/403 JSONResponse on failure, null on success. Fail closed.
     *
     * @return JSONResponse|null A response on failure, null when authorized.
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function requireStaff(): ?JSONResponse
    {
        if ($this->staffGuard->currentUid() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->staffGuard->isStaff() === false) {
            return new JSONResponse(['message' => 'Governance-body authority required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireStaff()

    /**
     * Execute a service call, enveloping the result or mapping the exception.
     *
     * @param callable    $operation The service call to run.
     * @param string|null $key       Envelope key, or null to return the raw payload.
     * @param int         $status    The success HTTP status.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function respond(callable $operation, ?string $key, int $status): JSONResponse
    {
        try {
            $payload = $operation();
            if ($key !== null) {
                $payload = [$key => $payload];
            }

            return new JSONResponse($payload, $status);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
        }

    }//end respond()

    /**
     * Map a service exception to an HTTP status code.
     *
     * @param \Throwable $e The thrown exception.
     *
     * @return int The HTTP status.
     *
     * @spec openspec/specs/citizen-participation/spec.md
     */
    private function statusForException(\Throwable $e): int
    {
        if ($e instanceof \InvalidArgumentException) {
            return Http::STATUS_BAD_REQUEST;
        }

        return Http::STATUS_CONFLICT;

    }//end statusForException()
}//end class
