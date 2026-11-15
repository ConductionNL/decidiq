<?php

/**
 * Decidiq Form Response Reader
 *
 * Reads the responses of the Nextcloud Forms forms linked to an object.
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

use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * The responses of the forms linked to an object, one entry per submission.
 *
 * The links come from OpenRegister's form-link service (the forms leaf writes
 * them); the submissions and answers are read from the Forms app's tables.
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */
class FormResponseReader {

	/**
	 * OpenRegister's form-link service, resolved from the container.
	 */
	public const FORM_LINK_SERVICE = 'OCA\OpenRegister\Service\FormLinkServiceInterface';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection      $db        The database the Forms tables live in.
	 * @param ContainerInterface $container Resolves OpenRegister's form-link service.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The ids of the forms linked to an object.
	 *
	 * @param string $objectId The object's UUID.
	 *
	 * @throws RuntimeException When the form links cannot be read.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function linkedFormIds(string $objectId): array {
		try {
			$rows = $this->container->get(self::FORM_LINK_SERVICE)->getLinkedForms($objectId);
		} catch (Throwable $e) {
			throw new RuntimeException('The forms linked to this object could not be read: ' . $e->getMessage(), 0, $e);
		}

		$ids = [];
		foreach ((array)$rows as $row) {
			$formId = (int)(((array)$row)['formId'] ?? 0);
			if ($formId > 0) {
				$ids[$formId] = $formId;
			}
		}

		return array_values($ids);
	}//end linkedFormIds()

	/**
	 * The submissions of a form, oldest first, each with its answers as text.
	 *
	 * @param int $formId The Forms form id.
	 *
	 * @return list<array{formId: int, submissionId: int, userId: string, timestamp: int, body: string}>
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function responses(int $formId): array {
		$questions = $this->questions(formId: $formId);
		$answers   = $this->answersBySubmission(formId: $formId);

		$responses = [];
		foreach ($this->submissions(formId: $formId) as $submission) {
			$id          = (int)$submission['id'];
			$responses[] = [
				'formId' => $formId,
				'submissionId' => $id,
				'userId' => (string)($submission['user_id'] ?? ''),
				'timestamp' => (int)($submission['timestamp'] ?? 0),
				'body' => self::bodyOf(questions: $questions, answers: ($answers[$id] ?? [])),
			];
		}

		return $responses;
	}//end responses()

	/**
	 * The response text: one "question: answer" line per answered question, in question order.
	 *
	 * @param array<int, string>       $questions Question text by question id, in order.
	 * @param array<int, list<string>> $answers   Answer texts by question id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public static function bodyOf(array $questions, array $answers): string {
		$lines = [];
		foreach ($questions as $questionId => $question) {
			$texts = array_filter(array_map('trim', ($answers[$questionId] ?? [])), static fn (string $text): bool => $text !== '');
			if ($texts !== []) {
				$lines[] = $question . ': ' . implode(', ', $texts);
			}
		}

		return implode("\n", $lines);
	}//end bodyOf()

	/**
	 * The form's questions, text by id, in the form's order.
	 *
	 * @param int $formId The form id.
	 *
	 * @return array<int, string>
	 */
	private function questions(int $formId): array {
		$rows = $this->rows(sql: 'SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id = ?', formId: $formId);
		usort($rows, static fn (array $a, array $b): int => [(int)($a['order'] ?? 0), (int)$a['id']] <=> [(int)($b['order'] ?? 0), (int)$b['id']]);

		$questions = [];
		foreach ($rows as $row) {
			$questions[(int)$row['id']] = (string)($row['text'] ?? '');
		}

		return $questions;
	}//end questions()

	/**
	 * The answer texts of the form's submissions, by submission id and question id.
	 *
	 * @param int $formId The form id.
	 *
	 * @return array<int, array<int, list<string>>>
	 */
	private function answersBySubmission(int $formId): array {
		$rows = $this->rows(
			sql: 'SELECT a.id, a.submission_id, a.question_id, a.text FROM *PREFIX*forms_v2_answers a'
			. ' INNER JOIN *PREFIX*forms_v2_submissions s ON a.submission_id = s.id WHERE s.form_id = ?',
			formId: $formId
		);
		usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

		$answers = [];
		foreach ($rows as $row) {
			$answers[(int)$row['submission_id']][(int)$row['question_id']][] = (string)($row['text'] ?? '');
		}

		return $answers;
	}//end answersBySubmission()

	/**
	 * The form's submissions, oldest first.
	 *
	 * @param int $formId The form id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function submissions(int $formId): array {
		$rows = $this->rows(sql: 'SELECT * FROM *PREFIX*forms_v2_submissions WHERE form_id = ?', formId: $formId);
		usort($rows, static fn (array $a, array $b): int => [(int)($a['timestamp'] ?? 0), (int)$a['id']] <=> [(int)($b['timestamp'] ?? 0), (int)$b['id']]);

		return $rows;
	}//end submissions()

	/**
	 * Run a query on one form and return its rows.
	 *
	 * @param string $sql    The query, with one placeholder for the form id.
	 * @param int    $formId The form id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $sql, int $formId): array {
		// The connection binds parameters as strings; the form id column compares them as integers.
		$result = $this->db->executeQuery($sql, [(string)$formId]);
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return array_values($rows);
	}//end rows()
}//end class
