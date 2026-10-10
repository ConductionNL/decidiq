<?php

/**
 * Decidiq CaseSystemExchangeService
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\BackgroundJob\SendMeetingFileJob;
use OCA\Decidiq\Exception\CaseSystemException;
use OCP\BackgroundJob\IJobList;
use Throwable;

/**
 * Sends the meeting file to the case system: each item's decisions to the
 * item's own case, the whole file to one case created for the meeting. Every
 * send is a CaseExchangeRecord with a line per document; a failed line is
 * sent again only when the griffier asks.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */
class CaseSystemExchangeService {
	/**
	 * Constructor.
	 *
	 * @param CaseSystemClient    $client      The case system, through integriq.
	 * @param MeetingFileService  $meetingFile The meeting file.
	 * @param CaseExchangeRecords $records     The exchange records.
	 * @param IJobList            $jobList     The background job queue.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 */
	public function __construct(
		private readonly CaseSystemClient $client,
		private readonly MeetingFileService $meetingFile,
		private readonly CaseExchangeRecords $records,
		private readonly IJobList $jobList,
	) {
	}//end __construct()

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
	public function requestSend(string $meetingId, string $userId): array {
		$this->requireConnection();
		$this->meetingFile->approvedMinutes(meetingId: $meetingId);
		$this->jobList->add(SendMeetingFileJob::class, ['meeting' => $meetingId, 'uid' => $userId]);
		return ['queued' => true];
	}//end requestSend()

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
	public function requestResend(string $recordId, string $userId): array {
		$this->requireConnection();
		$record = $this->records->find(recordId: $recordId);
		if ($this->failedLines(record: $record) === 0) {
			throw new CaseSystemException(message: 'Nothing failed, so there is nothing to send again');
		}

		$this->jobList->add(SendMeetingFileJob::class, ['record' => $recordId, 'uid' => $userId]);
		return ['queued' => true];
	}//end requestResend()

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
	public function send(string $meetingId, string $userId): array {
		$file     = $this->meetingFile->assemble(meetingId: $meetingId);
		$bySource = array_column($file['documents'], null, 'source');
		$written  = [];
		foreach ($file['items'] as $itemId => $item) {
			$case      = (array)($item['caseReference'] ?? []);
			$decisions = array_values(
				array_filter(
					$file['documents'],
					static fn (array $doc): bool => $doc['kind'] === 'decision' && ($doc['agendaItem'] ?? '') === (string)$itemId
				)
			);
			if ((string)($case['url'] ?? '') === '' || $decisions === []) {
				continue;
			}

			$record    = $this->records->write(
				record: [
					'meeting' => $meetingId,
					'agendaItem' => (string)$itemId,
					'direction' => 'send',
					'target' => (string)$case['url'],
					'targetLabel' => (string)($case['identification'] ?? ''),
					'lines' => $this->lines(documents: $decisions),
				],
				userId: $userId
			);
			$written[] = $this->deliver(record: $record, bySource: $bySource);
		}

		$record    = $this->records->write(
			record: ['meeting' => $meetingId, 'direction' => 'send', 'lines' => $this->lines(documents: $file['documents'])],
			userId: $userId
		);
		$written[] = $this->deliver(record: $this->withMeetingCase(record: $record, meeting: $file['meeting']), bySource: $bySource);

		return $written;
	}//end send()

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
	public function resend(string $recordId): array {
		$record = $this->records->find(recordId: $recordId);
		$file   = $this->meetingFile->assemble(meetingId: (string)($record['meeting'] ?? ''));
		foreach ((array)$record['lines'] as $index => $line) {
			if (($line['status'] ?? '') === 'failed') {
				$record['lines'][$index]['status'] = 'pending';
			}
		}

		if ((string)($record['target'] ?? '') === '' && (string)($record['agendaItem'] ?? '') === '') {
			$record = $this->withMeetingCase(record: $record, meeting: $file['meeting']);
		}

		return $this->deliver(record: $record, bySource: array_column($file['documents'], null, 'source'));
	}//end resend()

	/**
	 * Give a meeting-file record its case, created once. When the case system
	 * refuses, every pending line fails with that reason.
	 *
	 * @param array<string,mixed> $record  The record.
	 * @param array<string,mixed> $meeting The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return array<string,mixed>
	 */
	private function withMeetingCase(array $record, array $meeting): array {
		try {
			$date                  = substr((string)($meeting['scheduledDate'] ?? ''), 0, 10);
			$case                  = $this->client->createMeetingCase(title: (string)($meeting['title'] ?? ''), date: $date);
			$record['target']      = $case['url'];
			$record['targetLabel'] = $case['identification'];
		} catch (Throwable $e) {
			foreach ((array)$record['lines'] as $index => $line) {
				if (($line['status'] ?? '') === 'pending') {
					$record['lines'][$index] = ['status' => 'failed', 'error' => $e->getMessage()] + $line;
				}
			}
		}

		return $record;
	}//end withMeetingCase()

	/**
	 * Send every pending line of a record and store the outcome of each.
	 *
	 * @param array<string,mixed>                $record   The record.
	 * @param array<string,array<string,mixed>> $bySource The meeting file's documents by source.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return array<string,mixed> The stored record.
	 */
	private function deliver(array $record, array $bySource): array {
		foreach ((array)$record['lines'] as $index => $line) {
			if (($line['status'] ?? '') !== 'pending') {
				continue;
			}

			unset($line['error']);
			$document = ($bySource[(string)($line['source'] ?? '')] ?? ['error' => 'The document is no longer in the meeting file']);
			try {
				if (isset($document['content']) === false) {
					throw new CaseSystemException(message: (string)($document['error'] ?? 'The document could not be produced'));
				}

				$line['remoteUrl'] = $this->client->addDocument(caseUrl: (string)$record['target'], document: $document);
				$line['status']    = 'sent';
			} catch (Throwable $e) {
				$line['status'] = 'failed';
				$line['error']  = $e->getMessage();
			}

			$record['lines'][$index] = $line;
		}//end foreach

		return $this->records->update(recordId: (string)$record['id'], record: $record);
	}//end deliver()

	/**
	 * Pending record lines for documents.
	 *
	 * @param list<array<string,mixed>> $documents The documents.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return list<array<string,mixed>>
	 */
	private function lines(array $documents): array {
		$lines = [];
		foreach ($documents as $document) {
			$line = ['name' => (string)$document['name'], 'kind' => (string)$document['kind'], 'source' => (string)$document['source'], 'status' => 'pending'];
			if (isset($document['fileId']) === true) {
				$line['fileId'] = (int)$document['fileId'];
			}

			if (($document['confidential'] ?? false) === true) {
				$line['confidential'] = true;
				$line['ground']       = (string)($document['ground'] ?? '');
			}

			$lines[] = $line;
		}

		return $lines;
	}//end lines()

	/**
	 * How many lines of a record failed.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return int
	 */
	private function failedLines(array $record): int {
		return count(array_filter((array)($record['lines'] ?? []), static fn (mixed $line): bool => (((array)$line)['status'] ?? '') === 'failed'));
	}//end failedLines()

	/**
	 * Refuse while no case system is connected.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @throws CaseSystemException 409.
	 *
	 * @return void
	 */
	private function requireConnection(): void {
		if ($this->client->isConnected() === false) {
			throw new CaseSystemException(message: CaseSystemClient::NOT_CONNECTED, status: 409);
		}
	}//end requireConnection()
}//end class
