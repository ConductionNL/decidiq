import { defineStore } from 'pinia'
import { getRequestToken } from '@nextcloud/auth'

/**
 * OpenRegister object store — provided by the platform.
 *
 * Re-exports the canonical useObjectStore from @conduction/nextcloud-vue
 * per ADR-001: no custom CRUD stores; use the platform factory instead.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-2
 */
export const useObjectStore = defineStore('object', {
	state: () => ({
		baseUrl: '',
		schemaBaseUrl: '',
		objectTypes: {},
		objects: {},
		loading: {},
	}),

	actions: {
		configure({ baseUrl, schemaBaseUrl }) {
			this.baseUrl = baseUrl
			this.schemaBaseUrl = schemaBaseUrl
		},

		registerObjectType(type, schema, register) {
			this.objectTypes[type] = { schema, register }
			if (!this.objects[type]) {
				this.objects[type] = []
			}
		},

		async fetchObjects(type, params = {}) {
			if (!this.objectTypes[type]) {
				console.warn(`Object type "${type}" is not registered`)
				return []
			}

			this.loading[type] = true
			const { schema, register } = this.objectTypes[type]

			try {
				const url = new URL(this.baseUrl, window.location.origin)
				url.searchParams.set('register', register)
				url.searchParams.set('schema', schema)
				Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))

				const response = await fetch(url.toString(), {
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					const data = await response.json()
					this.objects[type] = data.results || data
					return this.objects[type]
				}
			} catch (error) {
				console.error(`Failed to fetch ${type} objects:`, error)
			} finally {
				this.loading[type] = false
			}
			return []
		},

		/**
		 * Alias for fetchObjects — canonical name used across all views.
		 *
		 * @param {string} type The object type key
		 * @param {object} params Optional query parameters
		 * @return {Promise<Array>} Array of objects
		 */
		async fetchCollection(type, params = {}) {
			return this.fetchObjects(type, params)
		},

		/**
		 * Create or update an object of the given type.
		 *
		 * @param {string} type The object type key
		 * @param {object} data Object data; must contain `id` or `uuid` for updates
		 * @return {Promise<object|null>} The saved object, or null on failure
		 */
		async saveObject(type, data = {}) {
			if (!this.objectTypes[type]) {
				console.warn(`Object type "${type}" is not registered`)
				return null
			}

			this.loading[type] = true
			const { schema, register } = this.objectTypes[type]
			const id = data.id || data.uuid

			try {
				const base = id ? `${this.baseUrl}/${id}` : this.baseUrl
				const url = new URL(base, window.location.origin)
				url.searchParams.set('register', register)
				url.searchParams.set('schema', schema)

				const response = await fetch(url.toString(), {
					method: id ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					const saved = await response.json()
					const idx = (this.objects[type] || []).findIndex(
						(o) => (o.id || o.uuid) === (saved.id || saved.uuid),
					)
					if (idx >= 0) {
						this.objects[type][idx] = saved
					}
					return saved
				}
			} catch (error) {
				console.error(`Failed to save ${type} object:`, error)
			} finally {
				this.loading[type] = false
			}
			return null
		},
	},
})
