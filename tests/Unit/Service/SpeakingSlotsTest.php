<?php

/**
 * The public projection of speakers is an allow-list: in every display
 * setting its keys are exactly the allowed ones, so a field added to a
 * request, or to the projection, fails this test. Pending and rejected
 * requests never appear publicly.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-008-anonymised-public-agenda-view-per-policy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SpeakingSlots;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\SpeakingSlots
 */
final class SpeakingSlotsTest extends TestCase {

	/**
	 * Requests carrying every field a request can have, private ones included.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function requests(): array {
		$private = [
			'contactDetails'  => ['name' => 'Geheim', 'email' => 'geheim@example.org', 'phone' => '0612345678', 'address' => 'Straat 1'],
			'rejectionReason' => 'Reden-geheim',
			'contribution'    => 'Bijdrage',
			'governanceBody'  => 'council',
			'registeredAfterDeadline' => true,
			'unknownFutureField' => 'Toekomst-geheim',
		];
		return [
			['id' => 'a', 'agendaItem' => 'i-1', 'lifecycle' => 'spoke', 'position' => 2, 'allottedMinutes' => 5, 'topic' => ['speakerName' => 'Anna  Jansen', 'organisation' => 'Org', 'topicText' => 'Parkeren', 'requestedMinutes' => 9]] + $private,
			['id' => 'b', 'agendaItem' => 'i-1', 'lifecycle' => 'approved', 'position' => 1, 'allottedMinutes' => 3, 'topic' => ['speakerName' => 'Kees', 'topicText' => 'Bomen']] + $private,
			['id' => 'c', 'agendaItem' => 'i-1', 'lifecycle' => 'registered', 'topic' => ['speakerName' => 'Wacht-geheim', 'topicText' => 'W']] + $private,
			['id' => 'd', 'agendaItem' => 'i-1', 'lifecycle' => 'rejected', 'topic' => ['speakerName' => 'Nee-geheim', 'topicText' => 'N']] + $private,
			['id' => 'e', 'agendaItem' => 'i-1', 'lifecycle' => 'no-show', 'position' => 3, 'topic' => ['speakerName' => 'Weg-geheim', 'topicText' => 'G']] + $private,
			'not a request',
		];
	}//end requests()

	/**
	 * Each display setting gives exactly its allowed keys, and nothing
	 * private, pending, rejected or absent reaches the payload.
	 *
	 * @return void
	 */
	public function testThePublicProjectionIsAnAllowList(): void {
		$slots = new SpeakingSlots();

		$this->assertSame(['i-1' => ['count' => 2]], $slots->public(requests: $this->requests(), display: 'count'));
		$this->assertSame(['i-1' => ['count' => 2]], $slots->public(requests: $this->requests(), display: 'everything'));
		$this->assertSame(
			['i-1' => ['count' => 2, 'speakers' => [['firstName' => 'Kees'], ['firstName' => 'Anna']]]],
			$slots->public(requests: $this->requests(), display: 'first-name')
		);
		$named = $slots->public(requests: $this->requests(), display: 'speaker-name');
		$this->assertSame(
			['i-1' => ['count' => 2, 'speakers' => [
				['speakerName' => 'Kees', 'organisation' => '', 'topicText' => 'Bomen'],
				['speakerName' => 'Anna  Jansen', 'organisation' => 'Org', 'topicText' => 'Parkeren'],
			]]],
			$named
		);
		foreach (SpeakingSlots::DISPLAYS as $display) {
			$json = json_encode($slots->public(requests: $this->requests(), display: $display));
			$this->assertDoesNotMatchRegularExpression('/geheim|Reden|Bijdrage|0612|Straat/', (string) $json, $display);
		}
	}//end testThePublicProjectionIsAnAllowList()

	/**
	 * Internally, approved and finished requests are slots in order, the
	 * registered one is pending and the rejected one is absent.
	 *
	 * @return void
	 */
	public function testTheInternalViewKeepsPendingApartAndDropsRejected(): void {
		$agenda = (new SpeakingSlots())->internal(requests: $this->requests());

		$this->assertSame(['b', 'a', 'e'], array_column($agenda['i-1']['slots'], 'id'));
		$this->assertSame(['approved', 'spoke', 'no-show'], array_column($agenda['i-1']['slots'], 'status'));
		$this->assertSame(['c'], array_column($agenda['i-1']['pending'], 'id'));
		$this->assertTrue($agenda['i-1']['slots'][0]['hasContribution']);
		$this->assertSame(
			['id', 'status', 'speakerName', 'organisation', 'topicText', 'minutes', 'position', 'hasContribution', 'transcriptSegment'],
			array_keys($agenda['i-1']['slots'][0])
		);
	}//end testTheInternalViewKeepsPendingApartAndDropsRejected()

	/**
	 * The queue has only approved speakers of the place asked for.
	 *
	 * @return void
	 */
	public function testTheQueueHasOnlyApprovedSpeakersOfThatPlace(): void {
		$slots = new SpeakingSlots();

		$this->assertSame(['b'], array_column($slots->queue(requests: $this->requests(), agendaItemId: 'i-1'), 'requestId'));
		$this->assertSame([], $slots->queue(requests: $this->requests(), agendaItemId: ''));
	}//end testTheQueueHasOnlyApprovedSpeakersOfThatPlace()
}//end class
