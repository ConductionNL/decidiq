<?php
/**
 * Decidesk Minutes Error Responder
 *
 * Turns a domain exception raised by the minutes services into the JSON error
 * response the matching endpoint documents.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Maps a caught exception to its documented HTTP status.
 *
 * Extracted from MinutesController, where the same four-branch
 * catch-and-translate ladder was written out once per endpoint and accounted
 * for most of the class's overall complexity of 58.
 *
 * The status map stays with the endpoint rather than living here, because the
 * same exception legitimately means different things on different endpoints —
 * an InvalidArgumentException is a 404 when generating a draft (the minutes do
 * not exist) and a 422 when transitioning (the requested step is not the next
 * one). An exception the endpoint does not name is re-thrown, so this never
 * turns an unexpected failure into a tidy 4xx.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesErrorResponder
{
    /**
     * Translate an exception into the endpoint's documented error response.
     *
     * @param Throwable         $error     The caught exception
     * @param array<string,int> $statusMap Exception class name => HTTP status
     *
     * @return JSONResponse The error response.
     *
     * @throws Throwable When the exception is not named in the status map.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function translate(Throwable $error, array $statusMap): JSONResponse
    {
        foreach ($statusMap as $exceptionClass => $status) {
            if (is_a($error, $exceptionClass) === true) {
                return new JSONResponse(['message' => $error->getMessage()], $status);
            }
        }

        throw $error;

    }//end translate()

    /**
     * Translate an exception that carries its HTTP intent in its code.
     *
     * The ALV service raises plain exceptions and encodes the intended status
     * in the exception code (422 "not an ALV", 403 "not approved yet"), so this
     * endpoint distinguishes on the code rather than on the class.
     *
     * @param Throwable $error          The caught exception
     * @param int       $expectedCode   The exception code that maps to $matchedStatus
     * @param int       $matchedStatus  The HTTP status for $expectedCode
     * @param int       $fallbackStatus The HTTP status for every other code
     *
     * @return JSONResponse The error response.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     */
    public function translateCode(
        Throwable $error,
        int $expectedCode,
        int $matchedStatus,
        int $fallbackStatus,
    ): JSONResponse {
        $status = $fallbackStatus;
        if ((int) $error->getCode() === $expectedCode) {
            $status = $matchedStatus;
        }

        return new JSONResponse(['message' => $error->getMessage()], $status);

    }//end translateCode()
}//end class
