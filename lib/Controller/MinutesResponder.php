<?php
/**
 * Decidesk Minutes Responder
 *
 * The one place that decides which HTTP status a failed Minutes operation
 * carries.
 *
 * Every Minutes endpoint used to restate the same exception ladder inline, so
 * every endpoint also had to import every exception type and the Http constant
 * class. Collapsing that into named profiles here means an endpoint says WHAT
 * it is doing and this says what going wrong LOOKS like — and the mapping can
 * no longer drift between two endpoints that were meant to agree.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use Exception;
use InvalidArgumentException;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use RuntimeException;
use Throwable;

/**
 * Runs a Minutes operation and maps its failures to HTTP responses.
 *
 * Each run* method is one exception-to-status profile. They differ on purpose:
 * generateDraft reports a missing Minutes record as 404 through a plain
 * InvalidArgumentException, whereas the lifecycle endpoints reserve 404 for
 * MissingObjectException and use 422 for an invalid argument.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesResponder
{

    /**
     * Client-error statuses a service may request explicitly through an exception code.
     *
     * Anything outside this list is treated as an unexpected fault and becomes
     * an opaque 500 — a service cannot accidentally turn an internal error into
     * a caller-facing message.
     *
     * @var array<int,int>
     */
    private const CLIENT_ERROR_STATUSES = [
        Http::STATUS_BAD_REQUEST,
        Http::STATUS_FORBIDDEN,
        Http::STATUS_NOT_FOUND,
        Http::STATUS_CONFLICT,
        Http::STATUS_UNPROCESSABLE_ENTITY,
    ];

    /**
     * Run a draft-generation operation.
     *
     * InvalidArgumentException (including MissingObjectException) is 404,
     * MissingRelationException is 422, any other RuntimeException is 503.
     *
     * @param callable():array<string,mixed> $operation The operation to run
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function runDraft(callable $operation): JSONResponse
    {
        try {
            return new JSONResponse($operation());
        } catch (MissingRelationException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_NOT_FOUND);
        } catch (RuntimeException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_SERVICE_UNAVAILABLE);
        }

    }//end runDraft()

    /**
     * Run a lifecycle operation (transition, reject, document generation).
     *
     * MissingObjectException is 404, MissingRelationException and any other
     * InvalidArgumentException are 422, any other RuntimeException is 503.
     *
     * @param callable():array<string,mixed> $operation The operation to run
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function runLifecycle(callable $operation): JSONResponse
    {
        try {
            return new JSONResponse($operation());
        } catch (MissingObjectException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_NOT_FOUND);
        } catch (MissingRelationException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_SERVICE_UNAVAILABLE);
        }

    }//end runLifecycle()

    /**
     * Run an operation whose domain failure carries its own HTTP status in the exception code.
     *
     * MissingObjectException is 404; an exception whose code equals
     * $honouredStatus is reported with that status; anything else is 400.
     *
     * @param callable():array<string,mixed> $operation      The operation to run
     * @param int                            $honouredStatus The single exception code this endpoint honours
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     */
    public function runCoded(callable $operation, int $honouredStatus): JSONResponse
    {
        try {
            return new JSONResponse($operation());
        } catch (MissingObjectException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            $status = Http::STATUS_BAD_REQUEST;
            if ((int) $e->getCode() === $honouredStatus) {
                $status = $honouredStatus;
            }

            return $this->failure(exception: $e, status: $status);
        }

    }//end runCoded()

    /**
     * Run an operation whose unexpected failures must not leak their message.
     *
     * MissingObjectException is 404 and an exception carrying an explicit
     * client-error status reports that status with its message; everything else
     * is an opaque 500, exactly as these endpoints have always behaved.
     *
     * @param callable():array<string,mixed> $operation The operation to run
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     */
    public function runInternal(callable $operation): JSONResponse
    {
        try {
            return new JSONResponse($operation());
        } catch (MissingObjectException $e) {
            return $this->failure(exception: $e, status: Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            $status = (int) $e->getCode();
            if (in_array($status, self::CLIENT_ERROR_STATUSES, true) === true) {
                return $this->failure(exception: $e, status: $status);
            }

            return new JSONResponse(
                ['message' => 'Internal server error.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end runInternal()

    /**
     * Report a malformed request that never reached a service.
     *
     * @param string $message The caller-facing message
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function badRequest(string $message): JSONResponse
    {
        return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);

    }//end badRequest()

    /**
     * Build the failure response body.
     *
     * @param Throwable $exception The exception being reported
     * @param int       $status    The HTTP status to report it with
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function failure(Throwable $exception, int $status): JSONResponse
    {
        return new JSONResponse(['message' => $exception->getMessage()], $status);

    }//end failure()
}//end class
