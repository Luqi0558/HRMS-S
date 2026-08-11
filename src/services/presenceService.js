import { apiClient } from './apiClient.js'

/**
 * The half of presence a person chooses.
 *
 * Online and offline are not here: they come from the Pusher presence channel,
 * which knows which browsers are connected. This is for busy, away, and
 * appearing offline — states that cannot be inferred from a connection and
 * that outlive it, so setting Busy and closing the tab still reads Busy.
 */
export const presenceService = {
  /**
   * Chosen statuses for the company.
   *
   * Needed on load because the presence channel only reports people who are
   * currently connected — someone who set Busy yesterday and is offline today
   * would otherwise show as simply offline.
   */
  async list() {
    const data = await apiClient.get('/presence')

    return data.presence || []
  },

  /**
   * @param {'available'|'busy'|'away'|'invisible'|null} status null clears the
   *   override, so presence falls back to what the connection says
   * @param {string} [message] a short note, e.g. "In a meeting until 3"
   */
  async set(status, message) {
    const data = await apiClient.put('/presence', { status, message })

    return data.presence
  }
}

const IDLE_AFTER = 5 * 60 * 1000

/**
 * Marks someone away after five minutes without input.
 *
 * Only ever sets 'away' and clears it — it never touches an explicit choice.
 * Someone who said Busy should stay Busy while they read a long document, and
 * having the app quietly overwrite that would make the feature untrustworthy.
 */
export function createIdleWatcher({ onIdle, onActive } = {}) {
  let timer = null
  let idle = false

  function goActive() {
    if (idle) {
      idle = false
      onActive?.()
    }
    clearTimeout(timer)
    timer = setTimeout(() => {
      idle = true
      onIdle?.()
    }, IDLE_AFTER)
  }

  const events = ['mousedown', 'keydown', 'touchstart', 'focus']

  function start() {
    events.forEach(event => window.addEventListener(event, goActive, { passive: true }))
    // Switching tabs is not idleness — the laptop is still in use — but
    // returning to the tab is a strong signal of activity.
    document.addEventListener('visibilitychange', onVisible)
    goActive()
  }

  function onVisible() {
    if (document.visibilityState === 'visible') goActive()
  }

  function stop() {
    clearTimeout(timer)
    events.forEach(event => window.removeEventListener(event, goActive))
    document.removeEventListener('visibilitychange', onVisible)
    idle = false
  }

  return { start, stop }
}
