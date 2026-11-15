<?php

/**
 * Contract of CaseSystemExchangeService
 *
 * The public surface of CaseSystemExchangeService that other components call, so they depend
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

use OCA\Decidiq\Exception\CaseSystemException;

/**
 * Contract of CaseSystemExchangeService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseSystemExchangeServiceInterface {

	/**
	 * Queue sending the failed lines of one record again.
	 *
	 * @param string $recordId The record.
	 * @param string $userId   Who asked.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @throws CaseSystemException 409 without a case system, 422 when nothing failed.
	 *
	 * @return array{queued:bool}
	 */
	public function requestResend(string $recordId, string $userId): array;

	/**
	 * Queue sending the meeting file.
	 *
	 * @param string $meetingId The meeting.
	 * @param string $userId    Who asked, or system.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException 409 without a case system, 422 before the minutes are approved.
	 *
	 * @return array{queued:bool}
	 */
	public function requestSend(string $meetingId, string $userId): array;

	/**
	 * Send the failed lines of one record again: runs in SendMeetingFileJob.
	 *
	 * @param string $recordId The record.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @throws CaseSystemException When the record does not exist.
	 *
	 * @return array<string,mixed> The record.
	 */
	public function resend(string $recordId): array;

	/**
	 * Send the meeting file now: runs in SendMeetingFileJob.
	 *
	 * @param string $meetingId The meeting.
	 * @param string $userId    Who asked.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException When the minutes are not approved or the meeting does not exist.
	 *
	 * @return list<array<string,mixed>> The records written.
	 */
	public function send(string $meetingId, string $userId): array;
}//end interface
