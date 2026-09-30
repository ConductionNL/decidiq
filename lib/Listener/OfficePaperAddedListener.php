<?php

/**
 * Queues the PDF conversion of an Office paper added to a meeting or an
 * agenda item.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * An Office file created or rewritten in the folder of a decidiq meeting or
 * agenda item queues one ConvertPaperToPdfJob. The listener converts nothing
 * itself: a Files event runs inside the upload request.
 *
 * OpenRegister names an object's folder after its uuid, so the parent folder's
 * name is the object id; the object is looked up before anything is queued,
 * so a user's own folder that happens to carry a uuid name is left alone.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 */
class OfficePaperAddedListener implements IEventListener {

	/**
	 * The app config key that switches automatic conversion on and off.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'convert_office_papers';

	/**
	 * The Office extensions that are converted.
	 *
	 * @var array<int, string>
	 */
	public const EXTENSIONS = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

	/**
	 * The schemas whose folders hold papers.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMAS = ['agenda-item', 'meeting'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param IJobList               $jobList       The background job list.
	 * @param IAppConfig             $appConfig     The app config.
	 * @param ListenerSchemaResolver $schemaResolver Reads an object's schema slug.
	 * @param LoggerInterface        $logger        The logger.
	 * @param PublicationEventRecorder $eventRecorder Reports a new paper to subscribers.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly PublicationEventRecorder $eventRecorder,
	) {
	}//end __construct()

	/**
	 * Queue a conversion for an Office paper in a meeting or agenda item folder,
	 * and report a new paper there to publication subscribers.
	 *
	 * @param Event $event The event.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof NodeCreatedEvent === false && $event instanceof NodeWrittenEvent === false) {
			return;
		}

		$node = $event->getNode();
		if ($node instanceof File === false) {
			return;
		}

		$isOffice    = self::isOfficeName(name: $node->getName());
		$converts    = ($isOffice === true && self::isSwitchedOn(value: $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, 'true')) === true);
		$isNewPaper  = ($event instanceof NodeCreatedEvent);
		// A converted Office paper is reported once, through the PDF the conversion creates.
		$reportPaper = ($isNewPaper === true && $converts === false);
		if ($converts === false && $reportPaper === false) {
			return;
		}

		try {
			$folderName = $node->getParent()->getName();
		} catch (\Throwable) {
			return;
		}

		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $folderName) !== 1) {
			return;
		}

		$schema = $this->ownerSchema(uuid: $folderName);
		if ($schema === null) {
			return;
		}

		if ($reportPaper === true) {
			$this->eventRecorder->paperAdded(schema: $schema, objectId: $folderName, fileName: $node->getName());
		}

		if ($converts === true) {
			$this->jobList->add(
				ConvertPaperToPdfJob::class,
				['fileId' => (int)$node->getId(), 'objectId' => $folderName, 'schema' => $schema]
			);
		}
	}//end handle()

	/**
	 * Whether the stored switch value means on. The admin page stores it as a
	 * string through SettingsService; anything but an explicit off is on.
	 *
	 * @param string $value The stored value.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off
	 *
	 * @return bool
	 */
	public static function isSwitchedOn(string $value): bool {
		return in_array(strtolower(trim($value)), ['false', '0', 'no', 'off'], true) === false;
	}//end isSwitchedOn()

	/**
	 * Whether a file name carries an Office extension that is converted.
	 *
	 * @param string $name The file name.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
	 *
	 * @return bool
	 */
	public static function isOfficeName(string $name): bool {
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

		return in_array($extension, self::EXTENSIONS, true);
	}//end isOfficeName()

	/**
	 * The schema of the decidiq object a folder belongs to, or null when the
	 * uuid names no meeting or agenda item.
	 *
	 * @param string $uuid The folder name.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
	 *
	 * @return string|null
	 */
	private function ownerSchema(string $uuid): ?string {
		try {
			$found = $this->objectService->find(id: $uuid, register: 'decidiq', _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: no decidiq object for a new paper folder', ['folder' => $uuid, 'error' => $e->getMessage()]);
			return null;
		}

		if ($found === null) {
			return null;
		}

		// OpenRegister's find() falls back to a lookup without the schema, so
		// the schema is read off the object, never assumed from the call.
		$slug = $this->schemaResolver->schemaSlug(entity: $found, row: $found->getObject());
		if (in_array($slug, self::SCHEMAS, true) === false) {
			return null;
		}

		return $slug;
	}//end ownerSchema()
}//end class
