<?php

/**
 * Decidiq register descriptor guard.
 *
 * DECLARING A SCHEMA IS NOT ATTACHING IT, AND THE DIFFERENCE IS SILENT.
 * ---------------------------------------------------------------------
 * A schema in `components.schemas` gets created on the instance. A schema in
 * `components.registers.decidiq.schemas` gets LINKED to the register, and
 * OpenRegister's ImportHandler builds the register's schema ids from that list
 * alone. A schema in the first list and not the second therefore exists, owns a
 * definition, and is carried by nothing.
 *
 * Nothing reports it. `ObjectService::setSchema()` under a named register
 * resolves register-scoped and a scoped miss throws by design, so every caller
 * that names the register gets an exception rather than a wrong answer — which
 * would be the good outcome, except that callers routinely wrap that in a
 * `catch (\Throwable)` that downgrades it to a warning. Measured 2026-09-19 on
 * development: 106 schemas declared, 95 attached, eleven carried by nothing,
 * among them `decision-template`. `DecisionPublicationService::typeOf()` reads a
 * decision's type through exactly that path, logged a warning, returned null,
 * and so skipped BOTH the remedy guard and the remedy stamp. Every decision
 * published with no clause and a 200 OK.
 *
 * integriq has carried this guard since `sync_item_dead_letter` shipped in the
 * same state; decidiq had no equivalent. This is it.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Every schema this app declares must be carried by the register.
 */
class RegisterDescriptorTest extends TestCase {

	/**
	 * The register slug the descriptor declares.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The merged descriptor, base plus every ADR-037 fragment.
	 *
	 * @var array<string,mixed>
	 */
	private array $descriptor;

	/**
	 * Read what the app SHIPS, through the code that IMPORTS it.
	 *
	 * 🔴 NOT A SECOND MERGE. Re-implementing the fragment merge here would make
	 * this guard answer about something adjacent: it could pass over a register
	 * the importer never assembles that way. `shippedRegisterDescriptor()` is
	 * the same static `importConfiguration()` feeds to OpenRegister.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->descriptor = SettingsService::shippedRegisterDescriptor();
	}//end setUp()

	/**
	 * Every declared schema's slug, keyed by its definition name.
	 *
	 * A schema declares its slug; when it does not, OpenRegister derives one by
	 * kebab-casing the definition key, so the fallback mirrors that.
	 *
	 * @return array<string,string> Definition key => slug.
	 */
	private function declaredSlugs(): array {
		$declared = [];
		foreach (($this->descriptor['components']['schemas'] ?? []) as $name => $schema) {
			if (is_array($schema) === false) {
				continue;
			}

			$declared[(string)$name] = (string)($schema['slug'] ?? strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', (string)$name)));
		}

		return $declared;
	}//end declaredSlugs()

	/**
	 * The slugs the register actually carries.
	 *
	 * @return list<string> The attached slugs.
	 */
	private function attachedSlugs(): array {
		$attached = ($this->descriptor['components']['registers'][self::REGISTER]['schemas'] ?? []);
		self::assertIsArray($attached, 'The register must declare a schemas list');

		return array_values(array_map(static fn ($slug): string => (string)$slug, $attached));
	}//end attachedSlugs()

	/**
	 * THE GUARD. The register carries exactly the schemas the app declares.
	 *
	 * Detach any slug from `components.registers.decidiq.schemas` and this
	 * assertion reddens naming it. That is the mutation the whole file exists
	 * for, and it is the one the sibling below deliberately survives.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testRegisterDeclaresAllSchemaSlugs(): void {
		$expected = array_values($this->declaredSlugs());
		$actual = $this->attachedSlugs();

		sort($expected);
		sort($actual);

		self::assertSame(
			expected: $expected,
			actual: $actual,
			message: 'components.registers.decidiq.schemas[] MUST list exactly the slugs declared in '
				. 'components.schemas. A schema missing here is created on the instance and linked to '
				. 'nothing, so every register-scoped read and write against it throws and callers that '
				. 'catch Throwable fall silent.'
		);
	}//end testRegisterDeclaresAllSchemaSlugs()

	/**
	 * THE SIBLING CONTROL. Every attached slug resolves to a definition.
	 *
	 * This is the pair integriq runs, and the point of the pair is that the two
	 * fail in OPPOSITE directions. Detaching a slug reddens the guard above and
	 * leaves this one green, because the slugs that remain still resolve. What
	 * reddens THIS one is a register listing a slug nothing defines, which the
	 * guard above cannot distinguish from a definition somebody forgot.
	 *
	 * Without the pair, a guard that reddened under every mutation would tell
	 * you only that something moved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testEveryAttachedSlugHasADefinition(): void {
		$defined = array_values($this->declaredSlugs());

		$orphans = [];
		foreach ($this->attachedSlugs() as $slug) {
			if (in_array($slug, $defined, true) === false) {
				$orphans[] = $slug;
			}
		}

		self::assertSame(
			expected: [],
			actual: $orphans,
			message: 'The register lists a schema slug no components.schemas entry defines, so the '
				. 'import resolves it against whatever else on the instance answers to that name.'
		);
	}//end testEveryAttachedSlugHasADefinition()

	/**
	 * The register attaches each slug once.
	 *
	 * Fragments concatenate their lists rather than union them, so the same
	 * slug attached by two changes lands twice. Harmless on import and exactly
	 * the sort of drift that makes a count stop meaning anything.
	 *
	 * @return void
	 */
	public function testNoSlugIsAttachedTwice(): void {
		$attached = $this->attachedSlugs();
		$duplicates = array_values(array_unique(array_diff_assoc($attached, array_unique($attached))));

		self::assertSame(
			expected: [],
			actual: $duplicates,
			message: 'A schema slug is listed more than once in components.registers.decidiq.schemas[]'
		);
	}//end testNoSlugIsAttachedTwice()

	/**
	 * Every slug the code asks for by name is CARRIED, not merely declared.
	 *
	 * 🔴 THE EXISTING GUARD CHECKS THE WRONG LIST.
	 * `RegisterJsonTest::testEverySchemaSlugTheCodeAsksForIsDeclared()` compares
	 * `setSchema()` arguments against `components.schemas`, and it was green the
	 * whole time `setSchema('decision-template')` threw in production. Declared
	 * is not carried, and "carried" is the word in the exception OpenRegister
	 * raises: `Schema slug "x" is not carried by register "decidiq"`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function testEverySchemaSlugTheCodeAsksForIsCarriedByTheRegister(): void {
		$carried = array_flip($this->attachedSlugs());

		$asked = [];
		$phpFiles = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib')
		);
		foreach ($phpFiles as $phpFile) {
			if ($phpFile->isFile() === false || $phpFile->getExtension() !== 'php') {
				continue;
			}

			$body = (string)file_get_contents($phpFile->getPathname());
			if (preg_match_all("/setSchema\(\s*(?:schema:\s*)?'([a-zA-Z0-9_-]+)'\s*\)/", $body, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $slug) {
				$asked[$slug][] = basename($phpFile->getPathname());
			}
		}

		self::assertNotEmpty($asked, 'Some code must ask for a schema by slug, or this guard reads nothing');

		$uncarried = [];
		foreach ($asked as $slug => $callers) {
			if (isset($carried[$slug]) === false) {
				$uncarried[] = $slug . ' (asked for in ' . implode(', ', array_unique($callers)) . ')';
			}
		}

		self::assertSame(
			expected: [],
			actual: $uncarried,
			message: 'setSchema() names a slug the register does not CARRY. The lookup throws at runtime, '
				. 'and every caller that wraps it in catch(Throwable) then does nothing at all.'
		);
	}//end testEverySchemaSlugTheCodeAsksForIsCarriedByTheRegister()

	/**
	 * The eleven schemas this change attached stay attached.
	 *
	 * Named one by one rather than counted. A count goes green again the moment
	 * some other change adds a schema, which is how the original eleven could
	 * sit unattached across every branch that touched this descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testThePreviouslyUnattachedSchemasAreCarried(): void {
		$wereUnattached = [
			'decision-template',
			'approval-route',
			'approval-action',
			'meeting-type',
			'agenda-item-type',
			'position-type',
			'position-hold',
			'governance-body-composition',
			'body-governance-configuration',
			'audit-statement',
			'goal',
		];

		$carried = $this->attachedSlugs();
		foreach ($wereUnattached as $slug) {
			self::assertContains(
				needle: $slug,
				haystack: $carried,
				message: sprintf(
					'Schema "%s" was declared and never attached until 2026-09-19. It is detached again, '
					. 'so every register-scoped read of it throws.',
					$slug
				)
			);
		}
	}//end testThePreviouslyUnattachedSchemasAreCarried()

	/**
	 * A publication carries the remedy clause the reader needs.
	 *
	 * The payload schema is what an anonymous reader is served. OpenRegister
	 * DROPS a property the schema does not declare, in silence, so stamping the
	 * clause onto the payload without this property would store nothing and
	 * report success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testThePublicationPayloadDeclaresTheRemedyClause(): void {
		$payload = ($this->descriptor['components']['schemas']['PublicationPayload']['properties'] ?? []);

		self::assertArrayHasKey(
			key: 'legalRemedyClause',
			array: $payload,
			message: 'PublicationPayload does not declare legalRemedyClause, so the clause written to a '
				. 'publication is dropped on import and the reader is told the legal ground and not the remedy.'
		);
		self::assertArrayHasKey(
			key: 'legalBasis',
			array: $payload,
			message: 'PublicationPayload lost legalBasis while gaining the clause'
		);
	}//end testThePublicationPayloadDeclaresTheRemedyClause()
}//end class
