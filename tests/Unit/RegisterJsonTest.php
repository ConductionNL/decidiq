<?php

/**
 * Unit tests for the Decidesk register JSON (decidesk_register.json).
 *
 * Validates that all 17 schemas are defined with correct properties, types,
 * required fields, enum values, relations, and seed data.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for decidesk_register.json schema definitions.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */
class RegisterJsonTest extends TestCase
{

    /**
     * The decoded register JSON data.
     *
     * @var array<string,mixed>
     */
    private array $register;

    /**
     * The schemas from the register.
     *
     * @var array<string,mixed>
     */
    private array $schemas;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__.'/../../lib/Settings/decidesk_register.json';
        $json = file_get_contents(filename: $path);
        self::assertNotFalse(condition: $json, message: 'Register JSON file must exist');
        $this->register = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        $this->schemas  = ($this->register['components']['schemas'] ?? []);

    }//end setUp()

    /**
     * Test that the register JSON is valid OpenAPI 3.0.0 with x-openregister extensions.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testRegisterIsValidOpenApi(): void
    {
        self::assertSame(expected: '3.0.0', actual: $this->register['openapi']);
        self::assertArrayHasKey(key: 'x-openregister', array: $this->register);
        self::assertSame(expected: 'decidesk', actual: $this->register['x-openregister']['app']);
        self::assertSame(expected: 'application', actual: $this->register['x-openregister']['type']);

    }//end testRegisterIsValidOpenApi()

    /**
     * Test that all required schemas are defined.
     *
     * P1-schemas-and-data-model defined 17 core schemas. P3-citizen-participation
     * extended the register with 7 additional schemas for citizen engagement
     * (BudgetProposal, CitizenPanel, CitizenVote, Deliberation, Notification,
     * ParticipatoryBudget, PublicConsultation), bringing the total to 24.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     * @spec openspec/changes/p3-citizen-participation/tasks.md
     */
    public function testAllSeventeenSchemasExist(): void
    {
        $expected = [
            // P1 core schemas (17).
            'GovernanceBody',
            'Meeting',
            'Participant',
            'AgendaItem',
            'Motion',
            'Amendment',
            'VotingRound',
            'Vote',
            'Decision',
            'ActionItem',
            'Minutes',
            'DigitalDocument',
            'MonetaryAmount',
            'Offer',
            'Order',
            'Product',
            'Report',
            // P3 citizen participation schemas (7).
            'BudgetProposal',
            'CitizenPanel',
            'CitizenVote',
            'Deliberation',
            'Notification',
            'ParticipatoryBudget',
            'PublicConsultation',
        ];

        self::assertCount(
            expectedCount: 25,
            haystack: $this->schemas,
            message: 'Register must contain exactly 25 schemas (17 p1 core + 7 p3 citizen participation + 1 retired)'
        );

        foreach ($expected as $name) {
            self::assertArrayHasKey(key: $name, array: $this->schemas, message: "Schema '{$name}' must exist");
        }

    }//end testAllSeventeenSchemasExist()

    /**
     * Test that schema.org type annotations are present on all schemas.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testSchemaOrgTypeAnnotations(): void
    {
        $expectedTypes = [
            'GovernanceBody'  => 'schema:Organization',
            'Meeting'         => 'schema:Event',
            'Participant'     => 'schema:Person',
            'AgendaItem'      => 'custom:AgendaItem',
            'Motion'          => 'custom:Motion',
            'Amendment'       => 'custom:Amendment',
            'VotingRound'     => 'custom:VotingRound',
            'Vote'            => 'custom:Vote',
            'Decision'        => 'custom:Decision',
            'ActionItem'      => 'custom:ActionItem',
            'Minutes'         => 'custom:Minutes',
            'DigitalDocument' => 'schema:DigitalDocument',
            'MonetaryAmount'  => 'schema:MonetaryAmount',
            'Offer'           => 'schema:Offer',
            'Order'           => 'schema:Order',
            'Product'         => 'schema:Product',
            'Report'          => 'schema:Report',
        ];

        foreach ($expectedTypes as $name => $type) {
            $schemaType = ($this->schemas[$name]['x-openregister']['schemaType'] ?? null);
            self::assertSame(
                expected: $type,
                actual: $schemaType,
                message: "Schema '{$name}' must have schemaType '{$type}'"
            );
        }

    }//end testSchemaOrgTypeAnnotations()

    /**
     * Test GovernanceBody schema has correct required fields and bodyType enum.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testGovernanceBodySchema(): void
    {
        $schema = $this->schemas['GovernanceBody'];
        self::assertSame(expected: ['name', 'bodyType', 'domain'], actual: $schema['required']);

        $bodyTypeEnum = $schema['properties']['bodyType']['enum'];
        self::assertContains(needle: 'legislative', haystack: $bodyTypeEnum);
        self::assertContains(needle: 'association', haystack: $bodyTypeEnum);
        self::assertContains(needle: 'corporate-board', haystack: $bodyTypeEnum);
        self::assertContains(needle: 'operational', haystack: $bodyTypeEnum);
        self::assertContains(needle: 'citizen-panel', haystack: $bodyTypeEnum);
        self::assertCount(expectedCount: 5, haystack: $bodyTypeEnum);

    }//end testGovernanceBodySchema()

    /**
     * Test Meeting schema has correct lifecycle enum values.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testMeetingLifecycleEnum(): void
    {
        $lifecycle = $this->schemas['Meeting']['properties']['lifecycle']['enum'];
        $expected  = ['draft', 'scheduled', 'opened', 'paused', 'adjourned', 'closed'];
        self::assertSame(expected: $expected, actual: $lifecycle);

    }//end testMeetingLifecycleEnum()

    /**
     * Test Motion schema has correct required fields and lifecycle enum.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testMotionSchema(): void
    {
        $schema = $this->schemas['Motion'];
        self::assertSame(
            expected: ['title', 'text', 'motionType', 'proposer', 'lifecycle', 'submittedAt'],
            actual: $schema['required']
        );

        $lifecycle = $schema['properties']['lifecycle']['enum'];
        self::assertContains(needle: 'submitted', haystack: $lifecycle);
        self::assertContains(needle: 'withdrawn', haystack: $lifecycle);
        self::assertCount(expectedCount: 6, haystack: $lifecycle);

        self::assertSame(expected: 'array', actual: $schema['properties']['coSigners']['type']);

    }//end testMotionSchema()

    /**
     * Test VotingRound has votingMethod enum and isSecret boolean.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testVotingRoundSchema(): void
    {
        $schema = $this->schemas['VotingRound'];
        self::assertSame(expected: ['votingMethod', 'isSecret'], actual: $schema['required']);

        $methods = $schema['properties']['votingMethod']['enum'];
        self::assertContains(needle: 'for-against-abstain', haystack: $methods);
        self::assertContains(needle: 'ranked-choice', haystack: $methods);
        self::assertContains(needle: 'weighted', haystack: $methods);
        self::assertContains(needle: 'show-of-hands', haystack: $methods);

        self::assertSame(expected: 'boolean', actual: $schema['properties']['isSecret']['type']);

    }//end testVotingRoundSchema()

    /**
     * Test Decision schema has mailEnabled for email-to-decision linking.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testDecisionMailEnabled(): void
    {
        $schema = $this->schemas['Decision'];
        self::assertTrue(
            condition: $schema['x-openregister']['mailEnabled'],
            message: 'Decision schema must have mailEnabled=true for _mail metadata'
        );

        self::assertSame(expected: ['adopted', 'rejected'], actual: $schema['properties']['outcome']['enum']);
        // P3-citizen-participation: isPublished is now a string enum (internal | public | confidential)
        // controlling citizen transparency portal visibility, not a boolean ORI publish flag.
        self::assertSame(expected: 'string', actual: $schema['properties']['isPublished']['type']);
        self::assertSame(
            expected: ['internal', 'public', 'confidential'],
            actual: $schema['properties']['isPublished']['enum']
        );

    }//end testDecisionMailEnabled()

    /**
     * Test that core schemas have seed data with @self envelopes.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-2
     */
    public function testSeedDataPresent(): void
    {
        $coreSchemas = [
            'GovernanceBody',
            'Meeting',
            'Participant',
            'AgendaItem',
            'Motion',
            'Amendment',
            'VotingRound',
            'Vote',
            'Decision',
            'ActionItem',
            'Minutes',
        ];

        foreach ($coreSchemas as $name) {
            $seeds = ($this->schemas[$name]['x-openregister-seeds'] ?? []);
            self::assertGreaterThanOrEqual(
                expected: 3,
                actual: count($seeds),
                message: "Schema '{$name}' must have at least 3 seed objects"
            );

            foreach ($seeds as $seed) {
                self::assertArrayHasKey(
                    key: '@self',
                    array: $seed,
                    message: "Seed in '{$name}' must have @self envelope"
                );
                self::assertSame(
                    expected: 'decidesk',
                    actual: $seed['@self']['register'],
                    message: "Seed register must be 'decidesk'"
                );
                self::assertSame(
                    expected: $name,
                    actual: $seed['@self']['schema'],
                    message: "Seed schema must match '{$name}'"
                );
                self::assertNotEmpty(
                    actual: $seed['@self']['slug'],
                    message: 'Seed must have a non-empty slug'
                );
            }//end foreach
        }//end foreach

    }//end testSeedDataPresent()

    /**
     * Test that relations are declared with x-openregister-relations.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testRelationsAreConfigured(): void
    {
        $expectedRelations = [
            'Meeting'     => ['governanceBody'],
            'Participant' => ['governanceBody'],
            'AgendaItem'  => ['meeting'],
            'Motion'      => ['agendaItem'],
            'Amendment'   => ['motion'],
            'VotingRound' => ['motion'],
            'Vote'        => ['votingRound', 'participant'],
            'Decision'    => ['motion'],
            'ActionItem'  => ['decision', 'meeting'],
            'Minutes'     => ['meeting'],
        ];

        foreach ($expectedRelations as $schemaName => $relations) {
            $schemaRelations = ($this->schemas[$schemaName]['x-openregister-relations'] ?? []);
            foreach ($relations as $relation) {
                self::assertArrayHasKey(
                    key: $relation,
                    array: $schemaRelations,
                    message: "Schema '{$schemaName}' must have relation '{$relation}'"
                );
                self::assertSame(
                    expected: 'decidesk',
                    actual: $schemaRelations[$relation]['register'],
                    message: "Relation '{$relation}' on '{$schemaName}' must target 'decidesk' register"
                );
            }//end foreach
        }//end foreach

    }//end testRelationsAreConfigured()

    /**
     * Test that each schema has a slug and version.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testSchemasHaveSlugsAndVersions(): void
    {
        foreach ($this->schemas as $name => $schema) {
            self::assertNotEmpty(
                actual: ($schema['slug'] ?? ''),
                message: "Schema '{$name}' must have a slug"
            );
            self::assertNotEmpty(
                actual: ($schema['version'] ?? ''),
                message: "Schema '{$name}' must have a version"
            );
            self::assertSame(
                expected: 'object',
                actual: ($schema['type'] ?? ''),
                message: "Schema '{$name}' must be type 'object'"
            );
        }//end foreach

    }//end testSchemasHaveSlugsAndVersions()

    /**
     * Test Participant has role enum with all required values.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testParticipantRoleEnum(): void
    {
        $roles    = $this->schemas['Participant']['properties']['role']['enum'];
        $expected = ['chair', 'vice-chair', 'secretary', 'member', 'observer', 'guest'];
        self::assertSame(expected: $expected, actual: $roles);

    }//end testParticipantRoleEnum()
}//end class
