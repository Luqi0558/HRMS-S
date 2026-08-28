<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { authService } from '../services/authService.js'
import { ApiError } from '../services/apiClient.js'

const router = useRouter()
const route = useRoute()
const { setCurrentUser, setRolePermissions } = useStore()

const email = ref('')
const password = ref('')
const loginError = ref('')
const fieldErrors = ref({})
const isSubmitting = ref(false)
const typedHeadline = ref('')
const showDemoCredentials = ref(false)
// These accounts are created by the API's DemoUserSeeder. Keep this list in
// sync with that fixture so every role can be explored from the public demo.
const demoAccounts = [
  { role: 'Super Admin', email: 'admin@company.com' },
  { role: 'HR', email: 'sara.ahmed@company.com' },
  { role: 'Manager', email: 'zara.nadeem@company.com' },
  { role: 'Team Lead', email: 'imran.shah@company.com' },
  { role: 'QA', email: 'fatima.iqbal@company.com' },
  { role: 'Intern', email: 'hamza.sheikh@company.com' },
  { role: 'Employee', email: 'ayesha.khan@company.com' }
]
const demoPassword = 'password123'
const bannerHeadlines = [
  'Run HR operations with clarity.',
  'Manage teams without the clutter.',
  'Keep projects and people aligned.',
  'Make every approval traceable.'
]

let typeTimer = null
let headlineIndex = 0
let charIndex = 0
let isDeleting = false
let reduceMotionQuery = null

function chooseDemoAccount(account) {
  email.value = account.email
  password.value = demoPassword
  loginError.value = ''
  fieldErrors.value = {}
  showDemoCredentials.value = false
}

function closeDemoCredentials() {
  showDemoCredentials.value = false
}

async function login() {
  if (isSubmitting.value) return

  loginError.value = ''
  fieldErrors.value = {}
  isSubmitting.value = true

  try {
    const { user } = await authService.login(email.value.trim(), password.value)
    setCurrentUser(user.id, user.name, user.role, user.email, user.employeeId)

    // Load the authoritative grid before navigating: the destination route's
    // guard calls can(), and doing this after the redirect would evaluate it
    // against the seeded fallback for one frame.
    try {
      setRolePermissions(await authService.permissions())
    } catch {
      // Non-fatal. The seeded grid stays in place, which may be narrower or
      // wider than the server's — but the API enforces the real rules on every
      // request regardless, so this cannot grant access, only misrender a menu.
    }

    // Return them to whatever they were trying to reach before the guard
    // intercepted. Only relative paths, so a crafted ?redirect=https://evil
    // cannot turn the login form into an open redirect.
    const redirect = route.query.redirect
    if (typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//')) {
      router.replace(redirect)
    } else {
      router.replace({ name: 'dashboard' })
    }
  } catch (error) {
    if (error instanceof ApiError) {
      // 422 carries per-field messages; everything else (bad credentials,
      // blocked account, unknown tenant) is a single message for the banner.
      if (error.status === 422) {
        fieldErrors.value = error.errors
      }
      loginError.value = error.message
    } else {
      // Network failure or the API being down — `error.message` here is a
      // browser string like "Failed to fetch", which tells the user nothing.
      loginError.value = 'Cannot reach the server. Please check your connection and try again.'
    }
  } finally {
    isSubmitting.value = false
  }
}

function clearTypeTimer() {
  if (typeTimer) window.clearTimeout(typeTimer)
  typeTimer = null
}

function stepTypewriter() {
  const current = bannerHeadlines[headlineIndex]
  typedHeadline.value = current.slice(0, charIndex)

  if (!isDeleting && charIndex < current.length) {
    charIndex += 1
    typeTimer = window.setTimeout(stepTypewriter, 70)
    return
  }

  if (!isDeleting && charIndex === current.length) {
    isDeleting = true
    typeTimer = window.setTimeout(stepTypewriter, 2000)
    return
  }

  if (isDeleting && charIndex > 0) {
    charIndex -= 1
    typeTimer = window.setTimeout(stepTypewriter, 40)
    return
  }

  isDeleting = false
  headlineIndex = (headlineIndex + 1) % bannerHeadlines.length
  typeTimer = window.setTimeout(stepTypewriter, 350)
}

function startBannerAnimation() {
  reduceMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  if (reduceMotionQuery.matches) {
    typedHeadline.value = bannerHeadlines[0]
    return
  }

  stepTypewriter()
}

onMounted(() => {
  startBannerAnimation()

  // Set by the 401 handler when a token is rejected mid-session, so the user
  // understands why they were returned here.
  if (route.query.expired) {
    loginError.value = 'Your session has ended. Please sign in again.'
  }
})

onBeforeUnmount(() => {
  clearTypeTimer()
})
</script>

<template>
  <div class="login-page min-h-screen text-ink">
    <div class="login-shell">
      <main class="login-auth-panel">
        <div class="login-auth-glow" aria-hidden="true"></div>
        <section class="login-card">
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
              <div class="login-logo">O</div>
              <div>
                <div class="font-display text-lg font-black tracking-tight">Orbit HRMS</div>
                <div class="login-brand-caption">Secure workspace</div>
              </div>
            </div>
            <button
              type="button"
              class="login-env-pill"
              :aria-expanded="showDemoCredentials"
              aria-controls="demo-credentials-dialog"
              @click="showDemoCredentials = true"
            >
              Click me for login access
            </button>
          </div>

          <div class="login-intro">
            <p>Welcome back</p>
          </div>

          <form class="mt-7 space-y-4" @submit.prevent="login">
            <label class="block">
              <span class="login-label">Email</span>
              <input
                v-model="email"
                type="email"
                autocomplete="username"
                required
                placeholder="you@company.com"
                class="login-field"
              />
              <span v-if="fieldErrors.email" class="login-field-error">{{ fieldErrors.email[0] }}</span>
            </label>

            <label class="block">
              <span class="login-label">Password</span>
              <input
                v-model="password"
                type="password"
                autocomplete="current-password"
                required
                placeholder="••••••••"
                class="login-field"
              />
              <span v-if="fieldErrors.password" class="login-field-error">{{ fieldErrors.password[0] }}</span>
            </label>

            <div v-if="loginError" role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-bold text-rose-700">
              {{ loginError }}
            </div>

            <!-- Inside the form so Enter submits, and type=submit rather than a
                 click handler for the same reason. -->
            <button type="submit" :disabled="isSubmitting" class="login-submit w-full">
              <span>{{ isSubmitting ? 'Signing in…' : 'Sign in' }}</span>
              <svg v-if="!isSubmitting" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M5 12h14m-6-6 6 6-6 6" />
              </svg>
            </button>
          </form>

          <div class="login-card-footer">
            Trouble signing in? Contact your HR administrator.
          </div>
        </section>
      </main>

      <aside class="login-product-panel">
        <div class="banner-aurora" aria-hidden="true"></div>
        <div class="banner-grain" aria-hidden="true"></div>
        <div class="banner-orb banner-orb-one" aria-hidden="true"></div>
        <div class="banner-orb banner-orb-two" aria-hidden="true"></div>
        <div class="banner-orb banner-orb-three" aria-hidden="true"></div>

        <div class="banner-shell">
          <div class="banner-brand">
            <div class="banner-logo" aria-hidden="true">O</div>
            <div>
              <div class="banner-brand-name">Orbit HRMS</div>
              <div class="banner-brand-tagline">People operations, refined.</div>
            </div>
          </div>

          <section class="banner-copy" aria-labelledby="banner-static-heading">
            <h2 id="banner-static-heading" class="banner-sr-only">{{ bannerHeadlines[0] }}</h2>
            <div class="banner-type-wrap" aria-hidden="true">
              <span class="banner-type-text">{{ typedHeadline }}</span>
              <span class="banner-cursor"></span>
            </div>
            <p class="banner-support">
              A premium role-aware HRMS experience for attendance, onboarding, projects, payroll, and approvals.
            </p>
          </section>
        </div>
      </aside>
    </div>

    <div
      v-if="showDemoCredentials"
      class="demo-modal-backdrop"
      @click.self="closeDemoCredentials"
    >
      <section
        id="demo-credentials-dialog"
        class="demo-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="demo-credentials-heading"
      >
        <div class="demo-modal-header">
          <div>
            <p class="demo-modal-kicker">Orbit HRMS demo</p>
            <h2 id="demo-credentials-heading">Choose a role to explore</h2>
          </div>
          <button type="button" class="demo-modal-close" aria-label="Close demo credentials" @click="closeDemoCredentials">
            ×
          </button>
        </div>
        <p class="demo-modal-copy">Each account uses the password <strong>{{ demoPassword }}</strong>. Select one to fill the login form.</p>
        <div class="demo-account-grid">
          <button
            v-for="account in demoAccounts"
            :key="account.role"
            type="button"
            class="demo-account"
            :aria-label="`Use ${account.role} demo account`"
            @click="chooseDemoAccount(account)"
          >
            <span class="demo-account-role">{{ account.role }}</span>
            <span class="demo-account-email">{{ account.email }}</span>
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  /* Brand tokens mirror the ones the banner panel declares, so both halves of
     the split screen resolve to the same palette instead of the left side
     drifting onto stock Tailwind teal and blue-grey. */
  --auth-accent: #0F8B76;
  --auth-accent-strong: #0B6B5B;
  --auth-accent-deep: #085041;
  --auth-accent-soft: #ECF8F4;
  --auth-accent-line: #A9DFCE;
  --auth-surface: #ffffff;
  --auth-text: #12161C;
  --auth-muted: #6B7280;
  min-width: 0;
  overflow-x: hidden;
  background: linear-gradient(135deg, #F6FBF9 0%, #E1F5EE 100%);
}

.login-shell {
  display: grid;
  grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
  min-height: 100vh;
  width: 100%;
  min-width: 0;
}

.login-auth-panel {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 0;
  min-height: 100vh;
  padding: 2rem;
  overflow: hidden;
  background:
    radial-gradient(circle at 12% 18%, rgba(15, 139, 118, 0.10), transparent 34%),
    radial-gradient(circle at 88% 82%, rgba(111, 199, 174, 0.12), transparent 38%),
    linear-gradient(135deg, rgba(246, 251, 249, 0.98), rgba(225, 245, 238, 0.92));
}

.login-auth-glow {
  position: absolute;
  left: 8%;
  top: 16%;
  width: 18rem;
  height: 18rem;
  border-radius: 999px;
  background: rgba(53, 169, 140, 0.16);
  filter: blur(32px);
  pointer-events: none;
  animation: authGlowFloat 10s ease-in-out infinite alternate;
}

.login-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 460px;
  border: 1px solid rgba(169, 223, 206, 0.55);
  border-radius: 1.75rem;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(246, 251, 249, 0.94));
  box-shadow: 0 28px 80px rgba(8, 80, 65, 0.14);
  padding: 1.65rem;
  animation: authCardIn 520ms ease both;
}

.login-logo {
  display: grid;
  height: 2.75rem;
  width: 2.75rem;
  place-items: center;
  border-radius: 1rem;
  /* Matches .banner-logo on the right panel — the two marks sit on the same
     screen, so they must be the same mark. */
  background: linear-gradient(135deg, #12977E, #0B6B5B);
  color: #fff;
  font-weight: 900;
  box-shadow: 0 14px 28px rgba(11, 107, 91, 0.28);
}

/* Deliberately mirrors .banner-brand-tagline so the two brand lockups read as
   one, just inverted for the light panel. */
.login-brand-caption {
  margin-top: 0.15rem;
  color: var(--auth-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.login-env-pill {
  border-radius: 999px;
  border: 1px solid rgba(15, 139, 118, 0.22);
  background: var(--auth-accent-soft);
  color: var(--auth-accent-deep);
  padding: 0.42rem 0.75rem;
  font-size: 0.7rem;
  font-weight: 900;
  cursor: pointer;
  animation: demoPromptBlink 1.35s ease-in-out infinite;
  transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}

.login-env-pill:hover {
  background: #dff4ec;
  box-shadow: 0 7px 16px rgba(8, 80, 65, 0.11);
  transform: translateY(-1px);
}

.login-env-pill:focus-visible {
  outline: 3px solid rgba(15, 139, 118, 0.3);
  outline-offset: 2px;
}

.login-intro {
  margin-top: 2.15rem;
  margin-bottom: -0.25rem;
}

.login-intro p {
  color: var(--auth-accent-strong);
  font-size: 0.72rem;
  font-weight: 950;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.login-label {
  display: block;
  margin-bottom: 0.45rem;
  color: var(--auth-muted);
  font-size: 0.66rem;
  font-weight: 900;
  letter-spacing: 0.11em;
  text-transform: uppercase;
}

.login-field {
  width: 100%;
  border-radius: 0.95rem;
  border: 1px solid #CDE5DC;
  background: rgba(255, 255, 255, 0.92);
  color: var(--auth-text);
  font-size: 0.9rem;
  font-weight: 750;
  padding: 0.9rem 1rem;
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.login-field:hover {
  border-color: var(--auth-accent-line);
}

.login-field:focus {
  border-color: var(--auth-accent);
  box-shadow: 0 0 0 4px rgba(15, 139, 118, 0.14);
}

.login-field-error {
  display: block;
  margin-top: 0.4rem;
  color: #b93636;
  font-size: 0.7rem;
  font-weight: 800;
}

.login-submit {
  /* Spacing lives here, not on an `mt-8` utility in the markup: Tailwind's
     Preflight resets `button { margin: 0 }` at the same specificity as a margin
     utility, and won the cascade. Smaller than before because the button now
     sits inside a space-y-4 form that already provides a gap. */
  margin-top: 0.75rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.65rem;
  border-radius: 1rem;
  /* Primary action = brand teal, same gradient the sidebar's active item and
     the banner logo use. It was near-black, which read as a third colour. */
  background: linear-gradient(135deg, #12977E, #0B6B5B);
  color: #fff;
  padding: 0.95rem 1rem;
  font-size: 0.92rem;
  font-weight: 900;
  box-shadow: 0 18px 34px rgba(11, 107, 91, 0.28);
  transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.login-submit svg {
  height: 1.1rem;
  width: 1.1rem;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 2;
}

.login-submit:hover {
  background: linear-gradient(135deg, #0F8B76, #085041);
  box-shadow: 0 22px 38px rgba(11, 107, 91, 0.34);
  transform: translateY(-1px);
}

.login-submit:focus-visible {
  outline: 3px solid rgba(15, 139, 118, 0.35);
  outline-offset: 2px;
}

.login-submit:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
}

.login-card-footer {
  margin-top: 1.25rem;
  border-top: 1px solid rgba(169, 223, 206, 0.5);
  padding-top: 1rem;
  color: var(--auth-muted);
  font-size: 0.69rem;
  font-weight: 700;
}

.demo-account-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.5rem;
  margin-top: 0.8rem;
}

.demo-account {
  min-width: 0;
  border: 1px solid #CDE5DC;
  border-radius: 0.75rem;
  background: rgba(255, 255, 255, 0.74);
  padding: 0.6rem 0.7rem;
  text-align: left;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}

.demo-account:hover {
  border-color: var(--auth-accent);
  box-shadow: 0 8px 18px rgba(8, 80, 65, 0.09);
  transform: translateY(-1px);
}

.demo-account:focus-visible {
  outline: 3px solid rgba(15, 139, 118, 0.3);
  outline-offset: 2px;
}

.demo-account-role,
.demo-account-email {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.demo-modal-backdrop {
  position: fixed;
  z-index: 50;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(5, 47, 42, 0.48);
  backdrop-filter: blur(5px);
}

.demo-modal {
  width: min(100%, 28rem);
  max-height: min(43rem, calc(100vh - 2rem));
  overflow-y: auto;
  border: 1px solid rgba(169, 223, 206, 0.8);
  border-radius: 1.25rem;
  background: #fff;
  box-shadow: 0 28px 80px rgba(5, 47, 42, 0.28);
  padding: 1.25rem;
}

.demo-modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.demo-modal-kicker {
  color: var(--auth-accent-strong);
  font-size: 0.63rem;
  font-weight: 900;
  letter-spacing: 0.13em;
  text-transform: uppercase;
}

.demo-modal h2 {
  margin-top: 0.22rem;
  color: var(--auth-text);
  font-size: 1.1rem;
  font-weight: 950;
}

.demo-modal-close {
  display: grid;
  width: 2rem;
  height: 2rem;
  flex: none;
  place-items: center;
  border-radius: 999px;
  background: var(--auth-accent-soft);
  color: var(--auth-accent-deep);
  font-size: 1.4rem;
  line-height: 1;
  transition: background 0.15s ease;
}

.demo-modal-close:hover {
  background: #dff4ec;
}

.demo-modal-close:focus-visible {
  outline: 3px solid rgba(15, 139, 118, 0.3);
  outline-offset: 2px;
}

.demo-modal-copy {
  margin-top: 0.8rem;
  color: var(--auth-muted);
  font-size: 0.73rem;
  font-weight: 650;
  line-height: 1.55;
}

.demo-modal-copy strong {
  color: var(--auth-accent-deep);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.demo-account-role {
  color: var(--auth-accent-deep);
  font-size: 0.68rem;
  font-weight: 900;
}

.demo-account-email {
  margin-top: 0.2rem;
  color: var(--auth-muted);
  font-size: 0.6rem;
  font-weight: 700;
}

.login-product-panel {
  --banner-bg: #052f2a;
  --banner-bg-soft: #074a3e;
  --banner-accent: #12977E;
  --banner-accent-2: #6FD9BC;
  --banner-accent-3: #C8F5E7;
  --banner-text: #f8fafc;
  --banner-muted: #d7eee8;
  position: relative;
  display: flex;
  align-items: center;
  min-width: 0;
  min-height: 100vh;
  padding: clamp(2rem, 5vw, 4.5rem);
  color: var(--banner-text);
  overflow: hidden;
  isolation: isolate;
  background:
    radial-gradient(circle at 10% 5%, rgba(111, 217, 188, 0.24), transparent 34%),
    radial-gradient(circle at 95% 18%, rgba(18, 151, 126, 0.24), transparent 30%),
    linear-gradient(135deg, var(--banner-bg) 0%, var(--banner-bg-soft) 52%, #0b6b5b 100%);
}

.banner-aurora {
  position: absolute;
  inset: -22%;
  z-index: -3;
  background:
    conic-gradient(from 120deg at 50% 50%, rgba(18, 151, 126, 0.22), rgba(111, 217, 188, 0.18), rgba(200, 245, 231, 0.14), rgba(18, 151, 126, 0.22));
  filter: blur(56px);
  opacity: 0.76;
  animation: auroraDrift 18s ease-in-out infinite alternate;
}

.banner-grain {
  position: absolute;
  inset: 0;
  z-index: -1;
  opacity: 0.16;
  pointer-events: none;
  background-image:
    radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.28) 0 1px, transparent 1px),
    radial-gradient(circle at 70% 80%, rgba(255, 255, 255, 0.18) 0 1px, transparent 1px);
  background-size: 18px 18px, 27px 27px;
}

.banner-orb {
  position: absolute;
  z-index: -2;
  border-radius: 999px;
  filter: blur(18px);
  opacity: 0.62;
  pointer-events: none;
  animation: orbFloat 12s ease-in-out infinite alternate;
}

.banner-orb-one {
  top: 12%;
  right: 13%;
  width: 15rem;
  height: 15rem;
  background: rgba(111, 217, 188, 0.24);
}

.banner-orb-two {
  left: 7%;
  bottom: 12%;
  width: 18rem;
  height: 18rem;
  background: rgba(18, 151, 126, 0.2);
  animation-delay: -4s;
}

.banner-orb-three {
  right: 30%;
  bottom: 25%;
  width: 10rem;
  height: 10rem;
  background: rgba(200, 245, 231, 0.16);
  animation-delay: -8s;
}

.banner-shell {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 760px;
  animation: bannerFadeUp 700ms ease both;
}

.banner-brand {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  margin-bottom: clamp(2rem, 5vh, 3.4rem);
  animation: bannerFadeUp 700ms 80ms ease both;
}

.banner-logo {
  display: grid;
  width: 3rem;
  height: 3rem;
  place-items: center;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 1rem;
  background: linear-gradient(135deg, #12977E, #0B6B5B);
  color: #fff;
  font-weight: 950;
  box-shadow: 0 20px 48px rgba(0, 0, 0, 0.22);
  backdrop-filter: blur(14px);
}

.banner-brand-name {
  color: #fff;
  font-size: 0.94rem;
  font-weight: 950;
}

.banner-brand-tagline {
  margin-top: 0.15rem;
  color: var(--banner-muted);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/* The headline and supporting line sit directly on the aurora background now.
   The glass card drew a container around copy that needed none, and its
   padding pushed the two lines apart. */
.banner-copy {
  animation: bannerFadeUp 700ms 180ms ease both;
}

.banner-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* Reserves the height of the tallest headline so the paragraph below does not
   jump each time the typewriter wraps to a new line. Sized to three lines at
   the current line-height rather than the four the glass card allowed for. */
.banner-type-wrap {
  display: flex;
  align-items: flex-start;
  min-height: calc(3 * 0.98 * clamp(2rem, 4.25vw, 4.05rem));
  margin-bottom: clamp(1rem, 2vh, 1.75rem);
}

.banner-type-text {
  color: var(--banner-text);
  font-family: var(--font-display, inherit);
  font-size: clamp(2rem, 4.25vw, 4.05rem);
  font-weight: 950;
  line-height: 0.98;
  letter-spacing: 0;
  max-width: 11ch;
}

.banner-cursor {
  display: inline-block;
  width: 0.12em;
  height: 0.92em;
  margin-left: 0.12em;
  margin-top: 0.08em;
  border-radius: 999px;
  background: var(--banner-accent);
  animation: cursorBlink 800ms steps(2, start) infinite;
}

.banner-support {
  max-width: 620px;
  color: var(--banner-muted);
  font-size: clamp(0.92rem, 1.35vw, 1.08rem);
  font-weight: 500;
  line-height: 1.7;
}

@media (max-width: 1023px) {
  .login-shell {
    grid-template-columns: 1fr;
  }

  .login-product-panel {
    display: none;
  }
}

@media (max-width: 1180px) {
  .login-product-panel {
    padding: 2rem;
  }
}

@media (max-width: 640px) {
  .login-auth-panel {
    padding: 1rem;
  }

  .login-card {
    border-radius: 1.25rem;
    padding: 1rem;
  }

}

@media (prefers-reduced-motion: reduce) {
  .login-field,
  .login-submit,
  .login-env-pill,
  .login-card,
  .login-auth-glow,
  .banner-shell,
  .banner-brand,
  .banner-copy {
    transition: none;
    animation: none;
  }

  .banner-aurora,
  .banner-orb,
  .banner-cursor {
    animation: none;
  }

  .banner-cursor {
    opacity: 1;
  }
}

@keyframes authCardIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes authGlowFloat {
  from {
    transform: translate3d(-8px, 6px, 0) scale(1);
  }
  to {
    transform: translate3d(10px, -8px, 0) scale(1.04);
  }
}

@keyframes cursorBlink {
  0%, 45% {
    opacity: 1;
  }
  46%, 100% {
    opacity: 0;
  }
}

@keyframes demoPromptBlink {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.45;
  }
}

@keyframes bannerFadeUp {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes auroraDrift {
  from {
    transform: translate3d(-2%, -1%, 0) rotate(0deg) scale(1);
  }
  to {
    transform: translate3d(2%, 2%, 0) rotate(8deg) scale(1.05);
  }
}

@keyframes orbFloat {
  from {
    transform: translate3d(0, 0, 0) scale(1);
  }
  to {
    transform: translate3d(18px, -24px, 0) scale(1.06);
  }
}
</style>
