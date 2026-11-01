<?php

/**
 * Decidiq StreamingClient
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reaches the organisation's streaming service through integriq, and only
 * through integriq.
 *
 * The app declares the `streaming` connection (lib/Settings/connections.json);
 * an administrator links an integriq source to it. integriq holds the adapter
 * for the service. decidiq names the intent: one POST per operation under
 * /streaming/ on the linked source, with a JSON body. The source is found
 * through integriq's connection rows, never through integriq's source mapper,
 * which integriq no longer ships.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 */
class StreamingClient {
	/**
	 * The connection key decidiq declares.
	 */
	public const CONNECTION_KEY = 'streaming';

	/**
	 * The refusal every broadcast action gives while no source is linked.
	 */
	public const NOT_CONNECTED = 'No streaming service is connected';

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
	 * @param SigningAnswer          $answers       Reads the body of an integriq call answer.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly SigningAnswer $answers,
	) {
		$this->connection = new IntegriqConnection(objectService: $objectService);
	}//end __construct()

	/**
	 * Whether a source is linked to the streaming connection.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return bool
	 */
	public function isConnected(): bool {
		return $this->connection->isLinked(connectionKey: self::CONNECTION_KEY);
	}//end isConnected()

	/**
	 * Refuse with 409 when no source is linked.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @throws BroadcastRefusedException When no streaming service is connected.
	 *
	 * @return void
	 */
	public function requireConnected(): void {
		if ($this->isConnected() === false) {
			throw new BroadcastRefusedException(message: self::NOT_CONNECTED, status: 409);
		}
	}//end requireConnected()

	/**
	 * Call one operation on the linked source.
	 *
	 * @param string              $operation The operation name.
	 * @param array<string,mixed> $body      The request body.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @throws BroadcastRefusedException When no streaming service is connected (409) or it refuses (502).
	 *
	 * @return array<string,mixed> The decoded answer.
	 */
	public function call(string $operation, array $body): array {
		$source = $this->connection->source(connectionKey: self::CONNECTION_KEY);
		$caller = FleetAppId::getService($this->container, 'integriq', 'Service\CallService');
		if ($source === null || $caller === null) {
			throw new BroadcastRefusedException(message: self::NOT_CONNECTED, status: 409);
		}

		try {
			$response = $caller->call(
				source: $source,
				endpoint: '/streaming/' . $operation,
				method: 'POST',
				config: ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]
			);
		} catch (Throwable $e) {
			throw new BroadcastRefusedException(message: 'The streaming service could not be reached: ' . $e->getMessage(), status: 502);
		}

		$decoded = json_decode($this->answers->body(response: $response), true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		$status = $this->connection->statusOf(response: $response);
		if ($status >= 400) {
			throw new BroadcastRefusedException(message: (string)($decoded['message'] ?? ('The streaming service answered ' . $status)), status: 502);
		}

		return $decoded;
	}//end call()
}//end class
