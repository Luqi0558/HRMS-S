/**
 * How each ticket status is labelled and coloured.
 *
 * Static presentation config, not data: the API returns the status key and
 * this decides how it reads on screen. It lived in data/mock.js alongside the
 * sample records, which made it look like something the backend would
 * eventually replace — it is not, and every other export in that file has now
 * been removed in favour of the real API.
 */
export const statusMeta = {
  todo: { label: 'To do', bg: 'bg-slate-100', text: 'text-slate-700' },
  in_progress: { label: 'In progress', bg: 'bg-amber-bg', text: 'text-amber-dark' },
  review: { label: 'Review', bg: 'bg-teal-bg', text: 'text-teal-dark' },
  done: { label: 'Done', bg: 'bg-teal-bg', text: 'text-teal-dark' },
  blocked: { label: 'Blocked', bg: 'bg-coral-bg', text: 'text-coral-dark' }
}
