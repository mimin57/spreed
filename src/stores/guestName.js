/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author Dorra Jaouad <dorra.jaoued1@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
import { defineStore } from 'pinia'
import Vue from 'vue'

import { emit } from '@nextcloud/event-bus'

import { setGuestUserName } from '../services/participantsService.js'
import store from '../store/index.js'

export const useGuestNameStore = defineStore('guestName', {
	state: () => ({
		guestNames: {},
	}),

	actions: {
		/**
		 * Gets the participant display name
		 *
		 * @param {string} token the conversation's token
		 * @param {string} actorId the participant actorId
		 * @return {string} the participant name
		 */
		getGuestName(token, actorId) {
			return this.guestNames[token]?.[actorId] ?? t('spreed', 'Guest')
		},

		/**
		 * Gets the participant display name with suffix
		 * if the display name is not default translatable Guest
		 *
		 * @param {string} token the conversation's token
		 * @param {string} actorId the participant actorId
		 * @return {string} the participant name with/without suffix
		 */
		getGuestNameWithGuestSuffix(token, actorId) {
			const displayName = this.getGuestName(token, actorId)
			if (displayName === t('spreed', 'Guest')) {
				return displayName
			}
			return t('spreed', '{guest} (guest)', {
				guest: displayName,
			})
		},

		/**
		 * Adds a guest name to the store
		 *
		 * @param {object} data the wrapping object
		 * @param {string} data.token the token of the conversation
		 * @param {string} data.actorId the guest
		 * @param {string} data.actorDisplayName the display name to set
		 * @param {object} options options
		 * @param {boolean} options.noUpdate Override the display name or set it if it is empty
		 */
		addGuestName({ token, actorId, actorDisplayName }, { noUpdate }) {
			if (!this.guestNames[token]) {
				Vue.set(this.guestNames, token, {})

			}
			if (!this.guestNames[token][actorId] || actorDisplayName === '') {
				Vue.set(this.guestNames[token], actorId, t('spreed', 'Guest'))
			} else if (noUpdate) {
				return
			}

			if (actorDisplayName) {
				Vue.set(this.guestNames[token], actorId, actorDisplayName)
			}
		},

		/**
		 * Add the submitted guest name to the store
		 *
		 * @param {string} token the token of the conversation
		 * @param {string} name the new guest name
		 */
		async submitGuestUsername(token, name) {
			const actorId = store.getters.getActorId()
			const previousName = this.getGuestName(token, actorId)

			try {
				store.dispatch('setDisplayName', name)
				this.addGuestName({
					token,
					actorId,
					actorDisplayName: name,
				}, { noUpdate: false })

				await setGuestUserName(token, name)

				if (name !== '') {
					localStorage.setItem('nick', name)
				} else {
					localStorage.removeItem('nick')
				}
				emit('talk:guest-name:added')

			} catch (error) {
				store.dispatch('setDisplayName', previousName)
				this.addGuestName({
					token,
					actorId,
					actorDisplayName: previousName,
				}, { noUpdate: false })
				console.debug(error)
			}
		},
	},
})
