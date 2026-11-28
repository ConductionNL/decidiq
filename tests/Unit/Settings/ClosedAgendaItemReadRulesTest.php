<?php

/**
 * The read rules of a non-public agenda item and its papers, as the merged register ships them.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-002-only-authorised-readers-can-read-a-non-public-item-and-its-papers
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may read a non-public agenda item and its papers; who may write them stays the register baseline.
 *
 * OpenRegister's own evaluator is not loaded in this suite; the live run of the rules
 * (body member yes, member of another body no, named reader yes, anonymous nothing)
 * is recorded in the change's tasks.md and the e2e spec.
 *
 * @coversNothing
 */
class ClosedAgendaItemReadRulesTest extends TestCase {

	/**
	 * The two schemas the rules guard.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function guardedSchemas(): array {
		return ['agenda item' => ['agenda-item'], 'paper' => ['digital-document']];

	}//end guardedSchemas()

	/**
	 * The rules every read starts from: staff, then public rows of the caller's organisation, the members of
	 * the row's body and the accounts it names, each with the extra conditions given.
	 *
	 * @param array<string, mixed> $extra Conditions added to each member rule.
	 *
	 * @return list<mixed>
	 */
	private static function memberRules(array $extra): array {
		$memberOf = ['$memberOf' => ['schema' => 'participant', 'register' => 'decidiq', 'property' => 'governanceBody', 'match' => 'nextcloudUserId']];
		return [
			'decidiq-administrators',
			'decidesk-administrators',
			'decidiq-secretariat',
			['group' => 'authenticated', 'match' => ['public' => ['$ne' => false], '_organisation' => '$organisation'] + $extra],
			['group' => 'authenticated', 'match' => ['governanceBody' => $memberOf] + $extra],
			['group' => 'authenticated', 'match' => ['authorisedReaders' => ['$contains' => '$userId']] + $extra],
		];

	}//end memberRules()

	/**
	 * An agenda item admits the secretariat, the administrators, public rows of the caller's organisation,
	 * the members of its body and the accounts it names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	public function testAnItemAdmitsOnlyItsAuthorisedReaders(): void {
		$authorization = MergedRegisterSchema::forSlug(slug: 'agenda-item')['authorization'];

		self::assertSame(expected: self::memberRules(extra: []), actual: $authorization['read']);
		self::assertSame(expected: $authorization['read'], actual: $authorization['list']);

	}//end testAnItemAdmitsOnlyItsAuthorisedReaders()

	/**
	 * A paper admits the same readers once it is not under embargo; under embargo only staff and its
	 * embargo audience, and those still only within the item's audience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	public function testAPaperUnderEmbargoAdmitsOnlyItsAudience(): void {
		$authorization = MergedRegisterSchema::forSlug(slug: 'digital-document')['authorization'];
		$audience = ['embargoAudience' => ['$in' => '$user.groups']];
		$memberOf = ['$memberOf' => ['schema' => 'participant', 'register' => 'decidiq', 'property' => 'governanceBody', 'match' => 'nextcloudUserId']];

		self::assertSame(
			expected: array_merge(
				self::memberRules(extra: ['embargoActive' => ['$ne' => true]]),
				[
					['group' => 'authenticated', 'match' => ['public' => ['$ne' => false], '_organisation' => '$organisation'] + $audience],
					['group' => 'authenticated', 'match' => ['governanceBody' => $memberOf] + $audience],
				]
			),
			actual: $authorization['read']
		);
		self::assertSame(expected: $authorization['read'], actual: $authorization['list']);

	}//end testAPaperUnderEmbargoAdmitsOnlyItsAudience()

	/**
	 * Declaring a schema block replaces the register baseline for every action, so writing repeats it unchanged.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	#[DataProvider('guardedSchemas')]
	public function testWritingKeepsTheRegisterBaseline(string $slug): void {
		$baseline = array_values(SettingsService::shippedRegisterDescriptor()['components']['registers'])[0]['authorization'];
		$authorization = MergedRegisterSchema::forSlug(slug: $slug)['authorization'];

		foreach (['create', 'update', 'delete'] as $action) {
			self::assertSame(expected: $baseline[$action], actual: $authorization[$action], message: $action);
		}

	}//end testWritingKeepsTheRegisterBaseline()

	/**
	 * Every field a rule matches is declared, and the membership the body rule reads exists.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	#[DataProvider('guardedSchemas')]
	public function testEveryMatchedFieldIsDeclared(string $slug): void {
		$schema = MergedRegisterSchema::forSlug(slug: $slug);
		foreach (['public', 'governanceBody', 'authorisedReaders'] as $field) {
			self::assertArrayHasKey(key: $field, array: $schema['properties'], message: $field);
		}

		self::assertSame(expected: 'governance-body', actual: $schema['properties']['governanceBody']['$ref']);
		self::assertSame(expected: 'array', actual: $schema['properties']['authorisedReaders']['type']);

		$participant = MergedRegisterSchema::forSlug(slug: 'participant')['properties'];
		self::assertArrayHasKey(key: 'governanceBody', array: $participant);
		self::assertArrayHasKey(key: 'nextcloudUserId', array: $participant);

	}//end testEveryMatchedFieldIsDeclared()

	/**
	 * The fragment's versions win the merge, so OpenRegister re-imports both schemas.
	 *
	 * @return void
	 */
	public function testTheFragmentVersionsWin(): void {
		self::assertSame(expected: '1.7.0', actual: MergedRegisterSchema::forSlug(slug: 'agenda-item')['version']);
		self::assertSame(expected: '0.6.0', actual: MergedRegisterSchema::forSlug(slug: 'digital-document')['version']);

	}//end testTheFragmentVersionsWin()
}//end class
