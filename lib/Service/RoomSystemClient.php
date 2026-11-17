<?php

/**
 * Decidiq RoomSystemClient
 *
 * Sends what the chamber's conference and voting system shows to it, through
 * the integriq connection it is linked by.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One POST per message under /room-system/ on the source linked to the
 * `room-system` connection, with a JSON body. integriq holds the adapter for
 * the delegate system. Sending never fails the caller: while nothing is
 * linked nothing is sent, and a refused or unreachable call is logged.
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */
class RoomSystemClient {

	/**
	 * Finds the linked source and reads call statuses.
	 *
	 * @var IntegriqConnection
	 */
	private readonly IntegriqConnection $connection;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (integriq's connection rows and sources).
	 * @param ContainerInterface     $container     DI container (integriq's call service, lazily).
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		$this->connection = new IntegriqConnection(objectService: $objectService);
	}//end __construct()

	/**
	 * Whether a source is linked to the room-system connection.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function isConnected(): bool {
		return $this->connection->isLinked(connectionKey: RoomSystemMessageService::CONNECTION_KEY);
	}//end isConnected()

	/**
	 * Send one message to the room system.
	 *
	 * @param string               $operation The message name, e.g. `agenda`.
	 * @param array<string, mixed> $body      The message.
	 *
	 * @return bool Whether the room system took it; false when nothing is linked or the call failed.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function send(string $operation, array $body): bool {
		$source = $this->linkedSource();
		if ($source === null) {
			return false;
		}

		return $this->post(source: $source, operation: $operation, body: $body);
	}//end send()

	/**
	 * The linked source, or null when none is linked or the lookup fails.
	 *
	 * @return object|null
	 */
	private function linkedSource(): ?object {
		try {
			return $this->connection->source(connectionKey: RoomSystemMessageService::CONNECTION_KEY);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the room system connection could not be read', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end linkedSource()

	/**
	 * POST a message to the linked source through integriq's call service.
	 *
	 * @param object               $source    The integriq source.
	 * @param string               $operation The message name.
	 * @param array<string, mixed> $body      The message.
	 *
	 * @return bool Whether the room system took it.
	 */
	private function post(object $source, string $operation, array $body): bool {
		$caller = FleetAppId::getService($this->container, 'integriq', 'Service\CallService');
		if ($caller === null) {
			return false;
		}

		try {
			$response = $caller->call(
				source: $source,
				endpoint: '/room-system/' . $operation,
				method: 'POST',
				config: ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the room system could not be reached', ['operation' => $operation, 'exception' => $e->getMessage()]);
			return false;
		}

		$status = $this->connection->statusOf(response: $response);
		if ($status >= 400) {
			$this->logger->warning('Decidiq: the room system refused a message', ['operation' => $operation, 'status' => $status]);
			return false;
		}

		return true;
	}//end post()
}//end class
