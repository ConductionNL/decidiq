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

	/**
	 * A database whose queries return the given row sets in turn, recording each query's
	 * table and parameters and each closed cursor.
	 *
	 * @param list<list<array<string, mixed>>> $rowSets The rows per query, in query order.
	 * @param list<string>                     $tables  Receives "table:params" per query.
	 * @param int                              $closed  Receives the number of closed cursors.
	 *
	 * @return IDBConnection
	 */
	private function database(array $rowSets, array &$tables, int &$closed): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use (&$rowSets, &$tables, &$closed): IResult {
				preg_match('/FROM \*PREFIX\*(\w+)/', $sql, $match);
				$tables[] = $match[1] . ':' . var_export($params, true);
				$result   = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(array_shift($rowSets));
				$result->method('closeCursor')->willReturnCallback(
					static function () use (&$closed): bool {
						$closed++;
						return true;
					}
				);
				return $result;
			}
		);

		return $db;
	}//end database()

	/**
	 * Link rows given as objects are read too; zero, negative and non-numeric form ids are dropped.
	 *
	 * @return void
	 */
	public function testLinkRowsAsObjectsAreReadAndInvalidIdsDropped(): void {
		$links = new class {
			public function getLinkedForms(string $objectUuid): array {
				return [(object)['formId' => '7'], ['formId' => 0], ['formId' => -3], ['formId' => 'abc'], (object)['formId' => 2]];
			}
		};

		self::assertSame([7, 2], $this->reader(links: $links)->linkedFormIds(objectId: 'c1'));
	}//end testLinkRowsAsObjectsAreReadAndInvalidIdsDropped()

	/**
	 * A form-link service that fails is refused with its message, keeping the cause as previous.
	 *
	 * @return void
	 */
	public function testAFailingLinkServiceIsWrappedWithItsCause(): void {
		$cause = new \LogicException('table missing');
		$links = new class ($cause) {
			public function __construct(private \LogicException $cause) {
			}

			public function getLinkedForms(string $objectUuid): array {
				throw $this->cause;
			}
		};

		try {
			$this->reader(links: $links)->linkedFormIds(objectId: 'c1');
			self::fail('Expected the read to be refused');
		} catch (RuntimeException $e) {
			self::assertSame('The forms linked to this object could not be read: table missing', $e->getMessage());
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testAFailingLinkServiceIsWrappedWithItsCause()

	/**
	 * Equal question order and equal timestamps fall back to the id; missing user and time default; answers to
	 * unknown questions are ignored; the form id is bound as a string and every cursor is closed.
	 *
	 * @return void
	 */
	public function testTiesFallBackToIdsAndMissingFieldsDefault(): void {
		$tables = [];
		$closed = 0;
		$db     = $this->database(
			rowSets: [
				[['id' => 22, 'text' => 'Second', 'order' => 1], ['id' => 21, 'text' => 'First', 'order' => 1], ['id' => 20]],
				[
					['id' => 5, 'submission_id' => 201, 'question_id' => 22, 'text' => 'b'],
					['id' => 4, 'submission_id' => 201, 'question_id' => 21, 'text' => 'a2'],
					['id' => 3, 'submission_id' => 201, 'question_id' => 21, 'text' => 'a1'],
					['id' => 6, 'submission_id' => 201, 'question_id' => 99, 'text' => 'orphan'],
					['id' => 7, 'submission_id' => 200, 'question_id' => 20],
				],
				[['id' => 201, 'timestamp' => 5], ['id' => 200, 'timestamp' => 5]],
			],
			tables: $tables,
			closed: $closed
		);

		$responses = $this->reader(links: new \stdClass(), db: $db)->responses(formId: 8);

		self::assertSame(
			[
				['formId' => 8, 'submissionId' => 200, 'userId' => '', 'timestamp' => 5, 'body' => ''],
				['formId' => 8, 'submissionId' => 201, 'userId' => '', 'timestamp' => 5, 'body' => "First: a1, a2\nSecond: b"],
			],
			$responses
		);
		$bound = var_export(['8'], true);
		self::assertSame(['forms_v2_questions:' . $bound, 'forms_v2_answers:' . $bound, 'forms_v2_submissions:' . $bound], $tables);
		self::assertSame(3, $closed);
	}//end testTiesFallBackToIdsAndMissingFieldsDefault()

	/**
	 * A form without submissions has no responses.
	 *
	 * @return void
	 */
	public function testAFormWithoutSubmissionsHasNoResponses(): void {
		$tables = [];
		$closed = 0;
		$db     = $this->database(rowSets: [[['id' => 1, 'text' => 'Q']], [], []], tables: $tables, closed: $closed);

		self::assertSame([], $this->reader(links: new \stdClass(), db: $db)->responses(formId: 3));
		self::assertCount(3, $tables);
	}//end testAFormWithoutSubmissionsHasNoResponses()

	/**
	 * Answers without questions, or questions without answers, give an empty body.
	 *
	 * @return void
	 */
	public function testABodyWithoutMatchingQuestionsIsEmpty(): void {
		self::assertSame('', FormResponseReader::bodyOf(questions: [], answers: [1 => ['Yes']]));
		self::assertSame('', FormResponseReader::bodyOf(questions: [1 => 'Q'], answers: []));
	}//end testABodyWithoutMatchingQuestionsIsEmpty()

}//end class
