<?php

/**
 * integriq's call service, answering streaming operations from the world.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

/**
 * Stands in for `OCA\Integriq\Service\CallService::call()` on the streaming
 * connection: one POST per operation under /streaming/, answered by the world.
 * An answer with `_status` sets the HTTP status.
 */
final class StreamingCallServiceFake {
	/**
	 * Constructor.
	 *
	 * @param CaseSystemWorld $world The world that answers.
	 */
	public function __construct(private readonly CaseSystemWorld $world) {
	}//end __construct()

	/**
	 * Call one endpoint on a source.
	 *
	 * @param object              $source   The linked source.
	 * @param string              $endpoint The endpoint.
	 * @param string              $method   The HTTP method.
	 * @param array<string,mixed> $config   Body and headers.
	 *
	 * @return object A call answer with getResponse().
	 */
	public function call(object $source, string $endpoint, string $method='GET', array $config=[]): object {
		$answer = $this->world->answer(operation: str_replace('/streaming/', '', $endpoint), body: (array)json_decode((string)($config['body'] ?? '{}'), true));
		$status = (int)($answer['_status'] ?? 200);
		unset($answer['_status']);

		return new class ((string)json_encode($answer), $status) {
			/**
			 * Constructor.
			 *
			 * @param string $body   The body.
			 * @param int    $status The status.
			 */
			public function __construct(private readonly string $body, private readonly int $status) {
			}//end __construct()

			/**
			 * The raw response.
			 *
			 * @return array<string,mixed>
			 */
			public function getResponse(): array {
				return ['body' => $this->body, 'statusCode' => $this->status];
			}//end getResponse()
		};
	}//end call()
}//end class
