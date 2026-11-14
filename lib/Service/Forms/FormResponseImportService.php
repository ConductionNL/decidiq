<?php

/**
 * Decidiq Form Response Import Service
 *
 * Imports the responses of a consultation's intake form as reactions.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Forms;

use InvalidArgumentException;
use OCA\Decidiq\Service\ReactionIntakeService;
use Psr\Log\LoggerInterface;

/**
 * Turns the responses of the forms linked to a consultation into pending
 * ConsultationReaction objects, through the reaction intake service, so they
 * enter the moderation queue. Re-running the import creates nothing twice.
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */
class FormResponseImportService {

	/**
	 * Constructor.
	 *
	 * @param FormResponseReader    $reader The linked forms and their responses.
	 * @param ReactionIntakeService $intake The reaction intake and moderation path.
	 * @param LoggerInterface       $logger The logger.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function __construct(
		private readonly FormResponseReader $reader,
		private readonly ReactionIntakeService $intake,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import the responses of every form linked to a consultation.
	 *
	 * A response with no answer text, or one longer than a reaction may be, is
	 * not imported and counted as refused.
	 *
	 * @param string $consultationId The consultation UUID.
	 *
	 * @return array{forms: int, imported: int, skipped: int, refused: int} Linked forms, new reactions,
	 *                                                                     responses imported before, refused responses.
	 *
	 * @throws \RuntimeException When the consultation is not found or the form links cannot be read.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function import(string $consultationId): array {
		$counts = ['forms' => 0, 'imported' => 0, 'skipped' => 0, 'refused' => 0];
		foreach ($this->reader->linkedFormIds(objectId: $consultationId) as $formId) {
			$counts['forms']++;
			foreach ($this->reader->responses(formId: $formId) as $response) {
				$outcome = $this->importOne(consultationId: $consultationId, response: $response);
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end import()

	/**
	 * Import one response.
	 *
	 * @param string                                                                                $consultationId The consultation UUID.
	 * @param array{formId: int, submissionId: int, userId: string, timestamp: int, body: string} $response       The response.
	 *
	 * @return string One of imported, skipped, refused.
	 */
	private function importOne(string $consultationId, array $response): string {
		$sourceKey = 'forms:' . $response['formId'] . ':' . $response['submissionId'];
		try {
			$reaction = $this->intake->importReaction(
				consultationId: $consultationId,
				body: $response['body'],
				sourceKey: $sourceKey,
				submitter: $response['userId'],
				submittedAt: $response['timestamp']
			);
		} catch (InvalidArgumentException $e) {
			$this->logger->info('Decidiq: a form response was not imported as a reaction', ['source' => $sourceKey, 'reason' => $e->getMessage()]);
			return 'refused';
		}

		if ($reaction === null) {
			return 'skipped';
		}

		return 'imported';
	}//end importOne()
}//end class
