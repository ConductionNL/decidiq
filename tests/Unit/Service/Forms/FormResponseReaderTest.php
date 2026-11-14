<?php

/**
 * Unit tests for FormResponseReader.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Forms;

use OCA\Decidiq\Service\Forms\FormResponseReader;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

#[CoversClass(FormResponseReader::class)]
final class FormResponseReaderTest extends TestCase {

	/**
	 * A reader over a container that returns the given form-link service.
	 *
	 * @param object             $links The form-link service.
	 * @param IDBConnection|null $db    The database.
	 *
	 * @return FormResponseReader
	 */
	private function reader(object $links, ?IDBConnection $db = null): FormResponseReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FormResponseReader::FORM_LINK_SERVICE)->willReturn($links);

		return new FormResponseReader(db: ($db ?? $this->createMock(IDBConnection::class)), container: $container);
	}//end reader()

	/**
	 * The linked form ids, once each, without rows that carry no form id.
	 *
	 * @return void
	 */
	public function testLinkedFormIdsAreTheFormsOfTheLinkRows(): void {
		$links = new class {
			public ?string $asked = null;

			public function getLinkedForms(string $objectUuid): array {
				$this->asked = $objectUuid;
				return [['formId' => 4, 'submissions' => []], ['formId' => 9], ['formId' => 4], ['title' => 'no id']];
			}
		};

		self::assertSame([4, 9], $this->reader(links: $links)->linkedFormIds(objectId: 'c1'));
		self::assertSame('c1', $links->asked);
	}//end testLinkedFormIdsAreTheFormsOfTheLinkRows()

	/**
	 * Without OpenRegister's form links the read fails loudly, it does not pretend there are no forms.
	 *
	 * @return void
	 */
	public function testLinksThatCannotBeReadAreRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));
		$reader = new FormResponseReader(db: $this->createMock(IDBConnection::class), container: $container);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The forms linked to this object could not be read: not registered');
		$reader->linkedFormIds(objectId: 'c1');
	}//end testLinksThatCannotBeReadAreRefused()

	/**
	 * The body has one line per answered question in question order, joining several answers.
	 *
	 * @return void
	 */
	public function testTheBodyListsAnsweredQuestionsInOrder(): void {
		$body = FormResponseReader::bodyOf(
			questions: [3 => 'Your view', 1 => 'Topics', 2 => 'Skipped'],
			answers: [1 => ['Traffic', ' Parking '], 2 => ['  '], 3 => ['More bike lanes']]
		);

		self::assertSame("Your view: More bike lanes\nTopics: Traffic, Parking", $body);
	}//end testTheBodyListsAnsweredQuestionsInOrder()

	/**
	 * Responses come from the Forms tables, one per submission oldest first, answers in question order.
	 *
	 * @return void
	 */
	public function testResponsesJoinSubmissionsAndAnswers(): void {
		$queries = [
			[['id' => 12, 'text' => 'Topics', 'order' => 2], ['id' => 11, 'text' => 'Your view', 'order' => 1]],
			[
				['id' => 3, 'submission_id' => 102, 'question_id' => 12, 'text' => 'Parking'],
				['id' => 1, 'submission_id' => 101, 'question_id' => 11, 'text' => 'Agree'],
				['id' => 2, 'submission_id' => 101, 'question_id' => 12, 'text' => 'Traffic'],
			],
			[['id' => 103, 'user_id' => 'bob', 'timestamp' => 1700000200], ['id' => 101, 'user_id' => 'alice', 'timestamp' => 1700000000], ['id' => 102, 'user_id' => 'anon-user-x', 'timestamp' => 1700000100]],
		];
		$tables = [];
		$db     = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use (&$queries, &$tables): IResult {
				preg_match('/FROM \*PREFIX\*(\w+)/', $sql, $match);
				$tables[] = $match[1] . ':' . implode(',', $params);
				$result   = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(array_shift($queries));
				return $result;
			}
		);

		$responses = $this->reader(links: new \stdClass(), db: $db)->responses(formId: 4);

		self::assertSame(['forms_v2_questions:4', 'forms_v2_answers:4', 'forms_v2_submissions:4'], $tables);
		self::assertSame(
			[
				['formId' => 4, 'submissionId' => 101, 'userId' => 'alice', 'timestamp' => 1700000000, 'body' => "Your view: Agree\nTopics: Traffic"],
				['formId' => 4, 'submissionId' => 102, 'userId' => 'anon-user-x', 'timestamp' => 1700000100, 'body' => 'Topics: Parking'],
				['formId' => 4, 'submissionId' => 103, 'userId' => 'bob', 'timestamp' => 1700000200, 'body' => ''],
			],
			$responses
		);
	}//end testResponsesJoinSubmissionsAndAnswers()

}//end class
