<?php

/**
 * Decidiq Governing Document Controller
 *
 * GET /api/governing-documents/{id}/in-force?date=YYYY-MM-DD: the version of
 * a governing document in force on a date, for anyone who can read the
 * document.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governing-documents-register
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use DateTimeImmutable;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\GoverningDocumentConsolidationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * In-force resolution of a governing document.
 *
 * @spec openspec/changes/governing-documents-register
 */
class GoverningDocumentController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest                                       $request       HTTP request
	 * @param GoverningDocumentConsolidationServiceInterface $consolidation In-force resolution
	 * @param IUserSession                                   $userSession   User session
	 */
	public function __construct(
		IRequest $request,
		private readonly GoverningDocumentConsolidationServiceInterface $consolidation,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The version in force on a date. Whether the document can be read is
	 * OpenRegister's call: it is looked up with the user's own rights, and a
	 * document they cannot see answers 404 before any version is read.
	 *
	 * @param string $id   The governing document UUID
	 * @param string $date The date, YYYY-MM-DD; today when empty
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/governing-documents-register
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function inForce(string $id, string $date = ''): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$day = self::parseDate(date: $date);
		if ($day === null) {
			return new JSONResponse(['message' => 'The date must be a calendar date written as YYYY-MM-DD.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->consolidation->readableDocument(documentId: $id) === null) {
			return new JSONResponse(['message' => 'Governing document not found.'], Http::STATUS_NOT_FOUND);
		}

		$version = $this->consolidation->inForceOn(documentId: $id, date: $day);

		return new JSONResponse(
			[
				'document' => $id,
				'date' => $day->format('Y-m-d'),
				'version' => self::summary(version: $version),
			]
		);
	}//end inForce()

	/**
	 * A strict YYYY-MM-DD date, today when empty, or null when malformed.
	 *
	 * @param string $date The requested date.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function parseDate(string $date): ?DateTimeImmutable {
		if ($date === '') {
			return new DateTimeImmutable('today');
		}

		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($day === false || $day->format('Y-m-d') !== $date) {
			return null;
		}

		return $day;
	}//end parseDate()

	/**
	 * The version as the endpoint answers it, or null.
	 *
	 * @param array<string, mixed>|null $version The version in force.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function summary(?array $version): ?array {
		if ($version === null) {
			return null;
		}

		return [
			'id' => ($version['id'] ?? ($version['uuid'] ?? ($version['@self']['id'] ?? null))),
			'versionNumber' => ($version['versionNumber'] ?? null),
			'effectiveDate' => ($version['effectiveDate'] ?? null),
			'expiryDate' => ($version['expiryDate'] ?? null),
			'status' => ($version['status'] ?? null),
			'determinedBy' => ($version['determinedBy'] ?? null),
		];
	}//end summary()
}//end class
