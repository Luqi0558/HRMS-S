import { apiClient } from './apiClient.js'

/**
 * Wraps the onboarding endpoints. Every call is unauthenticated — the
 * invitation token in the URL is the credential, so `auth: false` keeps the
 * client from attaching (and then invalidating) any stale Bearer token that
 * happens to be in storage.
 */
export const onboardingService = {
  /**
   * Resolves an invite link to the person it was sent to, for prefilling the
   * wizard's header.
   *
   * @returns {Promise<{invitation: {email: string, expiresAt: string}, employee: {name: string, designation: string|null, department: string|null}}>}
   */
  async resolve(token) {
    return apiClient.post('/onboarding/resolve', { token }, { auth: false })
  },

  /**
   * Submits the completed wizard. Files are sent as multipart, so the payload
   * is flattened into FormData with bracket notation that Laravel parses back
   * into the nested personal/technical/documents arrays the request expects.
   */
  async complete({ token, password, passwordConfirmation, personal, technical, documents }) {
    const form = new FormData()

    form.append('token', token)
    form.append('password', password)
    form.append('password_confirmation', passwordConfirmation)

    appendSection(form, 'personal', personal)
    appendSection(form, 'technical', technical)

    for (const [key, file] of Object.entries(documents || {})) {
      // Untouched file inputs are null; sending them would fail the `file` rule.
      if (file instanceof File) {
        form.append(`documents[${key}]`, file)
      }
    }

    return apiClient.post('/onboarding/complete', form, { auth: false })
  }
}

/**
 * Flattens one nested section into FormData. Arrays become `key[]`, plain
 * objects (skillLevels) become `key[subKey]`, and everything else is a scalar.
 */
function appendSection(form, section, values) {
  for (const [key, value] of Object.entries(values || {})) {
    const field = `${section}[${key}]`

    if (Array.isArray(value)) {
      // An empty array is simply omitted. Sending `field[]=''` as a placeholder
      // fails the `string` rule on the element, so the whole submission was
      // rejected whenever a user picked no stacks or no skills. The server
      // treats a missing array as empty, which is the same outcome.
      value.filter(item => item !== '' && item != null)
        .forEach(item => form.append(`${field}[]`, item))
      continue
    }

    if (value && typeof value === 'object') {
      for (const [subKey, subValue] of Object.entries(value)) {
        form.append(`${field}[${subKey}]`, subValue)
      }
      continue
    }

    // null/undefined would arrive as the strings "null"/"undefined".
    form.append(field, value ?? '')
  }
}
