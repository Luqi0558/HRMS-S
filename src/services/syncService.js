import { apiClient } from './apiClient.js'

/**
 * The real-time feed: new messages and notifications, by polling.
 *
 * Polling rather than WebSockets is a deliberate choice for this deployment.
 * A socket server is a second long-running process, and when the office RDP
 * spikes its CPU that process is exactly what dies — leaving a dead
 * connection that looks identical to "no new messages", which is the failure
 * this is meant to solve. A poll is stateless: a stalled request fails, backs
 * off, and the next one works, with nothing to restart.
 *
 * That self-healing depends on two things that are easy to get wrong, and both
 * were wrong here at first:
 *
 *   - Every request must be able to FAIL. fetch() has no timeout, so a server
 *     that accepts a connection and then stalls leaves the promise pending
 *     forever — and every recovery path below sits after that await. The
 *     timeout lives in apiClient; without it this loop stops dead rather than
 *     slowing down.
 *
 *   - There must be exactly ONE chain. Every tick schedules the next one, so
 *     anything that calls tick() while one is in flight forks a second chain
 *     that never merges. Two chains poll twice as fast, four chains four
 *     times — hammering a server that is already struggling.
 */

const FOCUSED_INTERVAL = 3000
const BACKGROUND_INTERVAL = 30000
// Used while a Pusher socket is carrying the feed. The poll does not stop, it
// slows: a socket that has silently died is indistinguishable from a quiet
// office, and this heartbeat is what turns that into a 30-second delay rather
// than an outage nobody notices.
const HEARTBEAT_INTERVAL = 30000
const MAX_BACKOFF = 60000

export function createSyncClient({ onData, onStatusChange } = {}) {
  let timer = null
  let running = false
  let failures = 0
  let cursors = { message: null, notification: null, receipt: null }
  let status = 'idle'
  let socketLive = false

  // Exactly one tick may be in flight. Anything wanting an immediate poll
  // while one is running sets pendingPoke instead of starting a second chain.
  let inFlight = false
  let pendingPoke = false

  function setStatus(next) {
    if (status === next) return
    status = next
    // Surfaced so the UI can show a stalled connection rather than letting
    // silence be mistaken for "nothing new" — the exact ambiguity that makes
    // a dead service hard to notice.
    if (onStatusChange) onStatusChange(next)
  }

  /** Slower in the background; backoff takes over after a failure. */
  function nextDelay() {
    if (failures > 0) {
      return Math.min(FOCUSED_INTERVAL * 2 ** failures, MAX_BACKOFF)
    }
    if (socketLive) return HEARTBEAT_INTERVAL
    return document.visibilityState === 'visible' ? FOCUSED_INTERVAL : BACKGROUND_INTERVAL
  }

  /** Schedules the one and only next tick, replacing any pending timer. */
  function scheduleNext(delay = nextDelay()) {
    clearTimeout(timer)
    if (!running) return
    timer = setTimeout(runTick, delay)
  }

  async function runTick() {
    if (!running) return

    // A tick is already awaiting: remember the request and let the running one
    // honour it when it finishes, rather than forking a second chain.
    if (inFlight) {
      pendingPoke = true
      return
    }

    inFlight = true

    try {
      const params = new URLSearchParams()
      if (cursors.message != null) params.set('sinceMessage', cursors.message)
      if (cursors.notification != null) params.set('sinceNotification', cursors.notification)
      if (cursors.receipt != null) params.set('sinceReceipt', cursors.receipt)

      const query = params.toString()
      const data = await apiClient.get(`/sync${query ? `?${query}` : ''}`)

      failures = 0
      setStatus('live')

      // 204 -> null body: nothing changed, so there is nothing to hand on.
      if (data) {
        if (data.cursors) cursors = data.cursors
        if (onData) onData(data)
      }
    } catch (error) {
      // 401 means the session is over. apiClient has already cleared the
      // token and signalled it; retrying would loop against a dead session.
      if (error?.status === 401) {
        inFlight = false
        stop()
        return
      }

      failures += 1
      setStatus('reconnecting')
    } finally {
      inFlight = false
    }

    if (!running) return

    // A poke that arrived mid-flight is honoured now, but still through
    // scheduleNext — never by calling runTick() directly, which is what would
    // fork a chain. A short delay rather than zero so a burst of pokes cannot
    // become a tight loop.
    if (pendingPoke) {
      pendingPoke = false
      scheduleNext(failures > 0 ? nextDelay() : 250)
      return
    }

    scheduleNext()
  }

  function onVisibilityChange() {
    if (!running) return
    // Coming back to the tab should feel immediate rather than waiting out a
    // background-length interval.
    if (document.visibilityState === 'visible') poke()
  }

  function start() {
    if (running) return
    running = true
    failures = 0
    pendingPoke = false
    document.addEventListener('visibilitychange', onVisibilityChange)
    scheduleNext(0)
  }

  function stop() {
    running = false
    clearTimeout(timer)
    timer = null
    pendingPoke = false
    document.removeEventListener('visibilitychange', onVisibilityChange)
    document.removeEventListener('online', poke)
    setStatus('idle')
  }

  /**
   * Asks for an immediate poll — after sending a message, or on regaining
   * focus. Safe to call at any time: it never starts a second chain, and while
   * backing off it respects the backoff rather than hammering a server that is
   * already failing.
   */
  function poke() {
    if (!running) return

    if (inFlight) {
      pendingPoke = true
      return
    }

    scheduleNext(failures > 0 ? nextDelay() : 0)
  }

  /**
   * Tells the poll whether a socket is carrying the feed.
   *
   * Live -> drop to a heartbeat. Dropped -> resume fast polling and fetch once
   * now, closing the gap while the socket was dead.
   */
  function setSocketLive(live) {
    const wasLive = socketLive
    socketLive = live

    if (wasLive && !live) {
      poke()
      return
    }

    // Going live re-times the pending tick to the slower heartbeat rather than
    // leaving a fast one already scheduled.
    if (!wasLive && live && !inFlight) scheduleNext()
  }

  /** Whether the poll itself is healthy, independent of any socket. */
  function isHealthy() {
    return running && failures === 0
  }

  return { start, stop, poke, setSocketLive, isHealthy }
}
