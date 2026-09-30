<?php

/**
 * Decidiq CaseSystemCallServiceFake
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

/**
 * Integriq's call service, shaped as CallService::call(source, endpoint,
 * method, config) with an answer that carries getResponse() like a CallLog.
 * The case system behind it is the world's answers; an answer with `_status`
 * answers with that HTTP status.
 */
final class CaseSystemCallServiceFake {
	/**
	 * Constructor.
	 *
	 * @param CaseSystemWorld $world The world.
	 */
	public function __construct(private readonly CaseSystemWorld $world) {
	}//end __construct()

	/**
	 * Call an endpoint on a source.
	 *
	 * @param object              $source   The source.
	 * @param string              $endpoint The endpoint.
	 * @param string              $method   The method.
	 * @param array<string,mixed> $config   The config, with a JSON body.
	 *
	 * @return object The call log.
	 */
	public function call(object $source, string $endpoint, string $method='GET', array $config=[]): object {
		$answer = $this->world->answer(operation: str_replace('/case-system/', '', $endpoint), body: (array)json_decode((string)($config['body'] ?? '{}'), true));
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
			 * The response.
			 *
			 * @return array{body:string,statusCode:int}
			 */
			public function getResponse(): array {
				return ['body' => $this->body, 'statusCode' => $this->status];
			}//end getResponse()
		};
	}//end call()
}//end class
