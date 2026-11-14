// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Annotations of the document open in the annotator, and the counts per
 * agenda item of a meeting (document-annotations). Every read goes through
 * decidiq's annotation API, which returns only what the caller may see.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */

import { getRequestToken } from '@nextcloud/auth'
import { defineStore } from 'pinia'
import {
	annotationsUrl,
	annotationUrl,
	countsUrl,
	ownUrl,
} from '../utils/annotations.js'

/**
 * A JSON request with the Nextcloud request token.
 *
 * @param {string} url The address.
 * @param {string} method The HTTP method.
 * @param {object|undefined} body The body, if any.
 * @return {Promise<object>} The decoded answer.
 * @throws {Error} With the server's message when the answer is not OK.
 */
async function request(url, method = 'GET', body = undefined) {
	const response = await fetch(url, {
		method,
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			requesttoken: getRequestToken(),
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		const error = new Error(
			data?.message || `Request failed (${response.status})`,
		)
		error.status = response.status
		throw error
	}
	return data
}

export const useAnnotationStore = defineStore('annotation', {
	state: () => ({
		fileId: null,
		annotations: [],
		fileVersion: '',
		versionLabel: '',
		name: '',
		path: '',
		canShareWithFaction: false,
		loading: false,
		error: '',
		counts: {},
	}),

	actions: {
		/**
		 * Read the annotations of a document.
		 *
		 * @param {number} fileId The Nextcloud file id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
		 */
		async load(fileId) {
			this.fileId = Number(fileId)
			this.loading = true
			this.error = ''
			try {
				const data = await request(annotationsUrl(this.fileId))
				this.annotations = Array.isArray(data.results) ? data.results : []
				this.fileVersion = String(data.fileVersion || '')
				this.versionLabel = String(data.versionLabel || '')
				this.name = String(data.name || '')
				this.path = String(data.path || '')
				this.canShareWithFaction = data.canShareWithFaction === true
			} catch (error) {
				this.annotations = []
				this.error = error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save a new annotation on the open document.
		 *
		 * @param {object} annotation The anchor and content.
		 * @return {Promise<object>} The stored annotation.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
		 */
		async create(annotation) {
			const data = await request(
				annotationsUrl(this.fileId),
				'POST',
				annotation,
			)
			this.annotations = [...this.annotations, data.annotation]
			return data.annotation
		},

		/**
		 * Change an annotation's note, colour or visibility.
		 *
		 * @param {string} id The annotation.
		 * @param {object} changes The changes.
		 * @return {Promise<object>} The stored annotation.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
		 */
		async update(id, changes) {
			const data = await request(annotationUrl(id), 'PUT', changes)
			const stored = {
				...data.annotation,
				current: String(data.annotation?.fileVersion) === this.fileVersion,
			}
			this.annotations = this.annotations.map((annotation) =>
				annotation.id === id ? stored : annotation,
			)
			return stored
		},

		/**
		 * Delete an annotation.
		 *
		 * @param {string} id The annotation.
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
		 */
		async remove(id) {
			await request(annotationUrl(id), 'DELETE')
			this.annotations = this.annotations.filter(
				(annotation) => annotation.id !== id,
			)
		},

		/**
		 * Read a meeting's counts per agenda item, in one request.
		 *
		 * @param {string} meetingId The meeting.
		 * @return {Promise<object>} Agenda item id to count; empty on failure.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-005-annotation-counts-on-agenda-item-rows
		 */
		async loadCounts(meetingId) {
			try {
				const data = await request(countsUrl(meetingId))
				this.counts =
					data.counts && typeof data.counts === 'object' ? data.counts : {}
			} catch {
				this.counts = {}
			}
			return this.counts
		},

		/**
		 * Delete every annotation the user wrote.
		 *
		 * @return {Promise<number>} How many were deleted.
		 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
		 */
		async deleteOwn() {
			const data = await request(ownUrl(), 'DELETE')
			this.annotations = []
			this.counts = {}
			return Number(data.deleted || 0)
		},
	},
})
