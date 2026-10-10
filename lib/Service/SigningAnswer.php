<?php

/**
 * Decidiq Signing Answer
 *
 * Reads what the signing service answers about a request (status and, once
 * signed, the base64 document) and what a finalised signature list says
 * about its signers. Kept apart from EIDASSignatureService, which owns the
 * transport through integriq.
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
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Read a signing service answer.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SigningAnswer {

	/**
	 * Statuses that mean everyone signed.
	 *
	 * @var array<int, string>
	 */
	private const SIGNED = ['signed', 'completed', 'complete', 'finished'];

	/**
	 * Statuses that mean the round ended without a signed copy.
	 *
	 * @var array<int, string>
	 */
	private const FAILED = ['failed', 'declined', 'rejected', 'cancelled', 'canceled', 'expired'];

	/**
	 * The response body of an integriq call.
	 *
	 * The integriq CallService::call() returns the call log as an OpenRegister
	 * object whose data holds `response.body`. An older call log exposed
	 * `getResponse()`; both are read.
	 *
	 * @param mixed $response The call log.
	 *
	 * @return string The raw body, or an empty string.
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function body(mixed $response): string {
		if (is_object($response) === false) {
			return '';
		}

		if (method_exists($response, 'getObject') === true) {
			$data = (array)$response->getObject();
			$body = ($data['response']['body'] ?? null);
			if (is_string($body) === true && $body !== '') {
				return $body;
			}
		}

		if (method_exists($response, 'getResponse') === true) {
			$raw = $response->getResponse();
			return (string)($raw['body'] ?? '');
		}

		return '';
	}//end body()

	/**
	 * The result of a status answer. Any status that is not a finished one
	 * reads as pending, so a round is only closed on a clear answer; a signed
	 * answer without a decodable document also reads as pending, so nothing
	 * half-stored is ever linked.
	 *
	 * @param array<string, mixed> $response The decoded answer
	 *
	 * @return array{status: string, document: ?string, fileName: ?string, message: string}
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function result(array $response): array {
		$raw = strtolower((string)($response['status'] ?? ''));
		$status = 'pending';
		if (in_array($raw, self::SIGNED, true) === true) {
			$status = 'signed';
		} elseif (in_array($raw, self::FAILED, true) === true) {
			$status = 'failed';
		}

		if ($status !== 'signed') {
			return ['status' => $status, 'document' => null, 'fileName' => null, 'message' => 'The signing request is ' . $status . '.'];
		}

		$document = base64_decode((string)($response['document'] ?? ''), true);
		if ($document === false || $document === '') {
			return [
				'status' => 'pending',
				'document' => null,
				'fileName' => null,
				'message' => 'The signing service reported signed but sent no readable document.',
			];
		}

		$fileName = (string)($response['fileName'] ?? '');
		if ($fileName === '') {
			$fileName = null;
		}

		return [
			'status' => 'signed',
			'document' => $document,
			'fileName' => $fileName,
			'message' => 'Signed.',
		];
	}//end result()

	/**
	 * The signer of each signature tuple, as the list of names `signedBy` holds.
	 *
	 * @param array<int, mixed> $signatureList List of {signer, signature, timestamp} tuples
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function signerNames(array $signatureList): array {
		$names = [];
		foreach ($signatureList as $entry) {
			$name = $entry;
			if (is_array($entry) === true) {
				$name = ($entry['signer'] ?? '');
			}

			if (is_string($name) === true && $name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end signerNames()
}//end class
