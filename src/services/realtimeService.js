import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getTenant, getToken, API_URL } from './apiClient.js'

/**
 * The Pusher socket, layered on top of the poll.
 *
 * This never delivers content. Pusher sends a bare "something is new" ping and
 * the client answers by calling /api/sync, so message bodies and notification
 * text never leave this server. It also means the socket and the poll converge
 * on one code path — whichever arrives first, the client does the same thing.
 *
 * The poll is not switched off while the socket is connected. It slows to a
 * heartbeat instead: a socket that has silently died looks exactly like a
 * quiet office, and the office RDP going busy is precisely when that happens.
 * The heartbeat is what turns a dead socket into a three-second delay rather
 * than an outage nobody notices.
 */
export function createRealtimeClient({ employeeId, onPing, onStatusChange, onPresence } = {}) {
  let echo = null
  let channel = null
  let presence = null

  function setStatus(status) {
    if (onStatusChange) onStatusChange(status)
  }

  function start() {
    const key = import.meta.env.VITE_PUSHER_APP_KEY

    // No key configured means this deployment runs on polling alone, which is
    // a supported mode rather than a failure — say nothing and let the poll
    // carry the feed.
    if (!key || !employeeId) return false

    try {
      window.Pusher = Pusher

      echo = new Echo({
        broadcaster: 'pusher',
        key,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
        forceTLS: true,
        // Channel authorisation runs against our own API so it can check the
        // subscriber really is this employee, in this tenant.
        authEndpoint: `${API_URL}/broadcasting/auth`,
        auth: {
          headers: {
            Authorization: `Bearer ${getToken()}`,
            'X-Tenant': getTenant(),
            Accept: 'application/json'
          }
        }
      })

      const tenant = getTenant()
      channel = echo.private(`tenant.${tenant}.employee.${employeeId}`)

      channel.listen('.feed.updated', payload => {
        // The ping carries only a type. Everything real comes from our API.
        if (onPing) onPing(payload?.type || 'message')
      })

      // Who is online, company-wide. A presence channel rather than a stored
      // flag: Pusher already knows which browsers are subscribed, and pushes
      // joins and leaves to everyone else. A last_seen column would need a job
      // to expire it and would still be wrong when someone shut a laptop lid.
      presence = echo.join(`tenant.${tenant}.presence`)

      // 'live' only once the channel has actually SUBSCRIBED, not merely when
      // the TCP connection opened. A socket that connects but whose private
      // channel is rejected (bad auth, wrong tenant) would otherwise report
      // healthy while delivering nothing — and the poll would drop to its slow
      // heartbeat on the strength of that false signal.
      channel.subscribed(() => setStatus('live'))
      channel.error(() => setStatus('polling'))

      presence
        .here(members => onPresence?.({ type: 'here', members }))
        .joining(member => onPresence?.({ type: 'joined', member }))
        .leaving(member => onPresence?.({ type: 'left', member }))
        // A person who stays connected but switches to Busy: the channel
        // itself only reports joins and leaves, so the change comes as an
        // event on the same channel.
        .listen('.presence.changed', payload => onPresence?.({ type: 'changed', member: payload }))

      const pusher = echo.connector?.pusher
      if (pusher) {
        // Deliberately NOT binding 'connected' to 'live' — see above. A
        // transport that is up says nothing about whether our channel is.
        // Not an error state: the poll is still running underneath, so a
        // dropped socket degrades latency rather than stopping delivery.
        pusher.connection.bind('unavailable', () => setStatus('polling'))
        pusher.connection.bind('failed', () => setStatus('polling'))
        pusher.connection.bind('disconnected', () => setStatus('polling'))
      }

      return true
    } catch {
      // A misconfigured key must not take the app down — polling continues.
      setStatus('polling')
      return false
    }
  }

  function stop() {
    try {
      if (channel) channel.stopListening('.feed.updated')
      // Leaving announces the departure to everyone else, so their roster
      // updates rather than showing a ghost until the socket times out.
      if (presence && echo) echo.leave(`tenant.${getTenant()}.presence`)
      if (echo) echo.disconnect()
    } catch {
      /* already gone */
    }
    channel = null
    presence = null
    echo = null
  }

  return { start, stop }
}
