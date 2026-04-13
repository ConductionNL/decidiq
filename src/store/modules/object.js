import { defineStore } from 'pinia'

/**
 * Generic OpenRegister object store.
 * Configure it with baseUrl and schemaBaseUrl, then register object types.
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
					headers: { requesttoken: OC.requestToken },
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
		 * Save (PUT) an object by sending only the provided fields.
		 * Avoids mass-assignment by accepting an explicit fields object.
		 *
		 * @param {object} fields - Object containing at minimum `id` or `uuid` plus the fields to update
		 * @return {Promise<object|null>} The saved object, or null on failure
		 */
		async saveObject(fields = {}) {
			if (!this.baseUrl) {
				console.warn('objectStore.baseUrl is not configured')
				return null
			}

			const id = fields.id || fields.uuid
			if (!id) {
				console.warn('objectStore.saveObject: id or uuid is required')
				return null
			}

			try {
				const url = new URL(this.baseUrl + '/' + id, window.location.origin)
				const response = await fetch(url.toString(), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(fields),
				})
				if (response.ok) {
					return await response.json()
				}
			} catch (error) {
				console.error('Failed to save object:', error)
			}
			return null
		},
	},
})
