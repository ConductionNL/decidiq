<?php

/**
 * Decidiq Process Template Controller
 *
 * Admin-gated CRUD + duplicate surface for governance process templates. Every
 * method is scoped to full/delegated admins via #[AuthorizedAdminSetting]; the
 * template management surface is an admin-settings concern (no per-user access).
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for process-template management (admin-only).
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplateController extends Controller {
	/**
	 * Constructor for ProcessTemplateController.
	 *
	 * @param IRequest $request The request object
	 * @param ProcessTemplateService $templateService The process-template service
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ProcessTemplateService $templateService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * List all process templates.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(['results' => $this->templateService->list()]);
	}//end index()

	/**
	 * Show a single process template.
	 *
	 * @param string $id The template UUID
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function show(string $id): JSONResponse {
		$template = $this->templateService->get(templateId: $id);
		if ($template === null) {
			return new JSONResponse(['message' => 'Process template not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($template);
	}//end show()

	/**
	 * Create a process template (validates the state machine, fail closed).
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function create(): JSONResponse {
		$params = $this->request->getParams();
		try {
			$created = $this->templateService->create(template: $params);
			return new JSONResponse($created, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: process template create failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to create process template.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end create()

	/**
	 * Validate a state-machine graph without persisting (editor pre-flight).
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function validate(): JSONResponse {
		$params = $this->request->getParams();
		return new JSONResponse($this->templateService->validateStateMachine(template: $params));
	}//end validate()

	/**
	 * Update a process template (refused for built-in templates).
	 *
	 * @param string $id The template UUID
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$params = $this->request->getParams();
		try {
			$updated = $this->templateService->update(templateId: $id, template: $params);
			return new JSONResponse($updated);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: process template update failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to update process template.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end update()

	/**
	 * Duplicate a process template into an editable copy.
	 *
	 * @param string $id The template UUID to duplicate
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function duplicate(string $id): JSONResponse {
		$params = $this->request->getParams();
		$newName = null;
		if (isset($params['name']) === true && is_string($params['name']) === true && $params['name'] !== '') {
			$newName = $params['name'];
		}

		try {
			$copy = $this->templateService->duplicate(templateId: $id, newName: $newName);
			return new JSONResponse($copy, Http::STATUS_CREATED);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: process template duplicate failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to duplicate process template.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end duplicate()

	/**
	 * Delete a process template (refused for built-in templates).
	 *
	 * @param string $id The template UUID
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		try {
			$this->templateService->delete(templateId: $id);
			return new JSONResponse(['success' => true]);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: process template delete failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to delete process template.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end destroy()
}//end class
