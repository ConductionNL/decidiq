<?php

/**
 * The works-council consultation request, after the real merge of every register fragment.
 *
 * @category  Tests
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-001-consultationrequest-schema-on-openregister
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Reads the MERGED register, so a fragment that stops reaching the schema fails here.
 *
 * @covers \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 */
final class WorksCouncilConsultationRegisterTest extends TestCase {

	/**
	 * The register JSON with every `register.d` fragment merged over it.
	 *
	 * @return array<mixed> The merged configuration.
	 */
	private function mergedRegister(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root.'/lib/Settings/decidesk_register.json'), true);
		$this->assertIsArray($base, 'the register JSON must parse');

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray($overlay, basename($fragment).' must parse');
			$base = RegisterFragmentMerger::deepMerge(base: $base, overlay: $overlay);
		}

		return $base;
	}//end mergedRegister()

	/**
	 * The merged consultation request.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function request(): array {
		return $this->mergedRegister()['components']['schemas']['ConsultationRequest'];
	}//end request()

	/**
	 * The schema reaches the merged register by its slug, with its required fields.
	 *
	 * @return void
	 */
	public function testTheRequestIsInTheMergedRegister(): void {
		$request = $this->request();

		$this->assertSame('consultation-request', $request['slug']);
		foreach (['type', 'subject', 'director', 'receivedDate'] as $field) {
			$this->assertContains($field, $request['required'], $field.' is required');
			$this->assertArrayHasKey($field, $request['properties']);
		}

		$this->assertSame(['adviceRequest', 'consent-request'], $request['properties']['type']['enum']);
		$this->assertContains('works-council', $this->mergedRegister()['components']['schemas']['GovernanceBody']['properties']['bodyType']['enum']);
	}//end testTheRequestIsInTheMergedRegister()

	/**
	 * The lifecycle starts at received, may loop back from the meeting and ends completed or withdrawn.
	 *
	 * @return void
	 */
	public function testTheLifecycleIsTheStatutoryTraject(): void {
		$lifecycle = $this->request()['x-openregister-lifecycle'];

		$this->assertSame('lifecycle', $lifecycle['field']);
		$this->assertSame('received', $lifecycle['initial']);
		$this->assertArrayNotHasKey('initialState', $lifecycle);
		$this->assertSame(['completed', 'withdrawn'], $lifecycle['terminal']);

		$edges = array_map(static fn (array $edge): string => $edge['from'].'>'.$edge['to'], $lifecycle['transitions']);
		$this->assertContains('consultationMeeting>in-progress', $edges, 'a second consultation meeting round');
		$this->assertContains('in-progress>constituencyConsultation', $edges, 'the optional constituency round');
		$this->assertContains('in-progress>consultationMeeting', $edges, 'skipping the constituency round');
		$this->assertNotContains('sent>withdrawn', $edges, 'a sent response cannot be withdrawn');
		$this->assertContains('decision-received>completed', $edges);
	}//end testTheLifecycleIsTheStatutoryTraject()

	/**
	 * Reminders and the deviating-decision notice are declared, not dispatched by code.
	 *
	 * @return void
	 */
	public function testTheNotificationsAreDeclared(): void {
		$notifications = $this->request()['x-openregister-notifications'];

		$this->assertSame('scheduled', $notifications['consultationRappelBeforeResponseDate']['trigger']['type']);
		$this->assertSame('scheduled', $notifications['consultationRappelOverdue']['trigger']['type']);
		$this->assertSame('scheduled', $notifications['consultationOpschortingExpiry']['trigger']['type']);
		$this->assertSame(
			['type' => 'updated', 'condition' => ['field' => 'decisionOutcome', 'operator' => 'equals', 'value' => 'contrary-to-advice']],
			$notifications['consultationBesluitAfwijkend']['trigger']
		);
		foreach ($notifications as $name => $notification) {
			$this->assertArrayHasKey('nl', $notification['subject'], $name.' has a Dutch subject');
			$this->assertArrayHasKey('en', $notification['subject'], $name.' has an English subject');
		}
	}//end testTheNotificationsAreDeclared()

	/**
	 * The suspension end is materialised as decision date + 1 month, only for an advice request decided against the advice.
	 *
	 * @return void
	 */
	public function testTheSuspensionEndIsDerivedOnlyForADeviatingAdviceDecision(): void {
		$request = $this->request();

		$this->assertSame(
			[
				'suspensionTo' => [
					'type' => 'date',
					'materialise' => true,
					'expression' => [
						'if' => [
							[
								'and' => [
									['eq' => [['prop' => 'type'], 'adviceRequest']],
									['eq' => [['prop' => 'decisionOutcome'], 'contrary-to-advice']],
								],
							],
							['dateAdd' => ['date' => ['prop' => 'decisionDate'], 'amount' => 1, 'unit' => 'months']],
							null,
						],
					],
				],
			],
			$request['x-openregister-calculations']
		);

		// A materialised calculation must name a property, with the type it writes.
		$this->assertSame(['string', 'date'], [$request['properties']['suspensionTo']['type'], $request['properties']['suspensionTo']['format']]);
		$this->assertContains('contrary-to-advice', $request['properties']['decisionOutcome']['enum']);
		$this->assertContains('adviceRequest', $request['properties']['type']['enum']);
	}//end testTheSuspensionEndIsDerivedOnlyForADeviatingAdviceDecision()
}//end class
