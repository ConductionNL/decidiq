<?php
/**
 * Shared helpers for the board-portal controllers.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-controllers
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Shared authentication + request body helpers reused across the eight
 * board-portal controllers introduced by board-meeting-resolutions Phase 3.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-controllers
 */
trait BoardPortalControllerTrait
{


    /**
     * Return a JSONResponse with 401 when the session lacks a user; null when
     * the caller is authenticated.
     *
     * @param IUserSession $session The session to inspect
     *
     * @return JSONResponse|null
     */
    protected function requireUserOr401(IUserSession $session): ?JSONResponse
    {
        if ($session->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        return null;

    }//end requireUserOr401()


    /**
     * Read the request payload, stripping URL routing params.
     *
     * @param \OCP\IRequest $request   The request
     * @param string[]      $stripKeys Keys to drop (URL routing param names)
     *
     * @return array<string, mixed>
     */
    protected function bodyParams(\OCP\IRequest $request, array $stripKeys=['id', '_route']): array
    {
        $raw = $request->getParams();
        if (is_array($raw) === false) {
            return [];
        }

        foreach ($stripKeys as $key) {
            unset($raw[$key]);
        }

        return $raw;

    }//end bodyParams()


    /**
     * Map a service result tuple ({success, ...}) to a JSONResponse, choosing
     * the HTTP status by inspecting the error message.
     *
     * @param array<string, mixed> $result      The service result
     * @param string               $payloadKey  Key of the success-side payload (e.g. 'board')
     * @param int                  $successCode HTTP code to return on success
     *
     * @return JSONResponse
     */
    protected function respondFromResult(array $result, string $payloadKey, int $successCode=Http::STATUS_OK): JSONResponse
    {
        if (($result['success'] ?? false) === false) {
            $message = (string) ($result['message'] ?? 'Operation failed.');
            $status  = stripos($message, 'not found') !== false
                ? Http::STATUS_NOT_FOUND
                : Http::STATUS_UNPROCESSABLE_ENTITY;

            return new JSONResponse(['message' => $message], $status);
        }

        return new JSONResponse(($result[$payloadKey] ?? null), $successCode);

    }//end respondFromResult()


}//end trait
