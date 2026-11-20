<?php

/**
 * The named portal readers of a confidential agenda item
 *
 * `POST /api/agenda-items/{id}/readers` adds a reader by BSN or KvK number,
 * `DELETE /api/agenda-items/{id}/readers/{personId}` removes one
 * (portal-voting-record-and-confidential-papers, REQ-PSH-002). Who may do so
 * is checked in ConfidentialReaders before anything is read or written.
 *
 * @category  Controller
 * @package   OCA\Decidiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\ConfidentialReaderRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ConfidentialReaders;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Adds and removes the portal readers of a non-public agenda item.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */
class ConfidentialReaderController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request The request.
	 * @param ConfidentialReaders $readers Checks the caller, then changes the readers.
	 */
	public function __construct(
		IRequest $request,
		private readonly ConfidentialReaders $readers,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Add a reader: body `{person, identityType: bsn|kvk, number, organisation?}`.
	 *
	 * The number goes to portaliq only; it is not in the answer, a log line or
	 * any decidiq object.
	 *
	 * @param string $id The agenda item id.
	 *
	 * @return JSONResponse 201 with the readers, or the refusal's status and reason.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function create(string $id): JSONResponse {
		try {
			$readers = $this->readers->add(
				itemId: $id,
				personId: (string)$this->request->getParam('person', ''),
				identityType: strtolower((string)$this->request->getParam('identityType', '')),
				number: (string)$this->request->getParam('number', ''),
				organisation: (string)$this->request->getParam('organisation', '')
			);
		} catch (ConfidentialReaderRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($readers, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Remove a reader.
	 *
	 * @param string $id       The agenda item id.
	 * @param string $personId The person id.
	 *
	 * @return JSONResponse 200 with the readers, or the refusal's status and reason.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(string $id, string $personId): JSONResponse {
		try {
			$readers = $this->readers->remove(itemId: $id, personId: $personId);
		} catch (ConfidentialReaderRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($readers);
	}//end destroy()
}//end class
