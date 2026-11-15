<?php

/**
 * Contract of VotingErrorResponder
 *
 * The public surface of VotingErrorResponder that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of VotingErrorResponder.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VotingErrorResponderInterface {

	/**
	 * Run the operation, turning a refusal into a 400.
	 *
	 * @param callable(): JSONResponse $operation The endpoint body
	 *
	 * @return JSONResponse The operation's response, or a 400 carrying the refusal message
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function badRequest(callable $operation): JSONResponse;

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
	public function badRequestOrNotFound(callable $operation): JSONResponse;

	/**
	 * Run the operation, logging any failure and returning an opaque 500.
	 *
	 * The exception message is deliberately not surfaced to the caller — it is
	 * written to the log with the supplied context instead.
	 *
	 * @param callable(): JSONResponse $operation The endpoint body
	 * @param string $logMessage The message to log on failure
	 * @param array<string, mixed> $context The log context
	 * @param string $response The message to return to the caller
	 *
	 * @return JSONResponse The operation's response, or an opaque 500
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function internalError(callable $operation, string $logMessage, array $context, string $response): JSONResponse;

	/**
	 * Run the operation, mapping a validation error to 400 and a missing target to 404.
	 *
	 * @param callable(): JSONResponse $operation The endpoint body
	 *
	 * @return JSONResponse The operation's response, or a 400 / 404
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function invalidOrMissing(callable $operation): JSONResponse;
}//end interface
