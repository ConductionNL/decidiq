<?php
/**
 * Decidesk Voting Error Responder
 *
 * Maps the exceptions the voting services raise onto the HTTP responses the
 * voting endpoints return. Each endpoint declares which mapping it wants by
 * choosing a method, so the controller carries no catch clauses of its own and
 * the status-code contract of every endpoint lives in one place.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use InvalidArgumentException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Exception-to-JSONResponse mapping for the voting endpoints.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingErrorResponder
{
    /**
     * Constructor for VotingErrorResponder.
     *
     * @param LoggerInterface $logger The logger
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Run the operation, turning a refusal into a 400.
     *
     * @param callable(): JSONResponse $operation The endpoint body
     *
     * @return JSONResponse The operation's response, or a 400 carrying the refusal message
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function badRequest(callable $operation): JSONResponse
    {
        try {
            return $operation();
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end badRequest()

    /**
     * Run the operation, distinguishing an unknown round from a refusal.
     *
     * A refusal whose message names a missing object is a 404; every other
     * refusal is a client error.
     *
     * @param callable(): JSONResponse $operation The endpoint body
     *
     * @return JSONResponse The operation's response, or a 404 / 400
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function badRequestOrNotFound(callable $operation): JSONResponse
    {
        try {
            return $operation();
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found') === true) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end badRequestOrNotFound()

    /**
     * Run the operation, mapping a validation error to 400 and a missing target to 404.
     *
     * @param callable(): JSONResponse $operation The endpoint body
     *
     * @return JSONResponse The operation's response, or a 400 / 404
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function invalidOrMissing(callable $operation): JSONResponse
    {
        try {
            return $operation();
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end invalidOrMissing()

    /**
     * Run the operation, logging any failure and returning an opaque 500.
     *
     * The exception message is deliberately not surfaced to the caller — it is
     * written to the log with the supplied context instead.
     *
     * @param callable(): JSONResponse $operation  The endpoint body
     * @param string                   $logMessage The message to log on failure
     * @param array<string, mixed>     $context    The log context
     * @param string                   $response   The message to return to the caller
     *
     * @return JSONResponse The operation's response, or an opaque 500
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function internalError(callable $operation, string $logMessage, array $context, string $response): JSONResponse
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->logger->error($logMessage, array_merge($context, ['error' => $e->getMessage()]));

            return new JSONResponse(['message' => $response], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end internalError()
}//end class
