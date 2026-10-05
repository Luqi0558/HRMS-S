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
const logoUrl = '/favicon.svg'
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
              <img :src="logoUrl" class="login-logo" width="44" height="44" alt="" />
              <div>
                <div class="font-display text-lg font-semibold tracking-tight">Orbit HRMS</div>
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
            <RouterLink :to="{ name: 'welcome' }" class="login-back-home">&larr; Back to Orbit</RouterLink>
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
            <img :src="logoUrl" class="banner-logo" width="48" height="48" alt="" />
            <div>
              <div class="banner-brand-name">Orbit HRMS</div>
              <div class="banner-brand-tagline">People operations, refined.</div>
            </div>
          </div>

          <section class="banner-copy" aria-labelledby="banner-static-heading">
            <h2 id="banner-static-heading" class="banner-sr-only">{{ bannerHeadlines[0] }}</h2>
            <div class="banner-type-wrap" aria-hidden="true">
              <span class="banner-type-text">{{ typedHeadline }}<span class="banner-cursor"></span></span>
            </div>
            <p class="banner-support">
              Your connected workspace for people, projects, and everyday HR. One place to stay in sync and keep work moving.
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
  /* Shared visual palette with the welcome page: navy, teal, cool surfaces. */
  --auth-accent: #098b80;
  --auth-accent-strong: #087e78;
  --auth-accent-deep: #123044;
  --auth-accent-soft: #eaf6f4;
  --auth-accent-line: #b6dcd7;
  --auth-text: #123044;
  --auth-muted: #64788a;
  --auth-border: #dde7ed;
  min-width: 0;
  overflow-x: hidden;
  color: var(--auth-text);
  background: #f5f8fa;
}
.login-shell {
  display: grid;
  grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr);
  min-height: 100svh;
  width: 100%;
  min-width: 0;
}
.login-auth-panel {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 0;
  min-height: 100svh;
  padding: 2rem;
  overflow: hidden;
  background: radial-gradient(ellipse at 12% 36%, #e7f5f2, transparent 65%), #f5f8fa;
}
.login-auth-glow {
  position: absolute;
  left: 8%;
  top: 16%;
  width: 18rem;
  height: 18rem;
  border-radius: 50%;
  background: rgba(9, 139, 128, .06);
  filter: blur(32px);
  pointer-events: none;
  animation: authGlowFloat 10s ease-in-out infinite alternate;
}
.login-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 460px;
  border: 1px solid var(--auth-border);
  border-radius: 24px;
  background: #fff;
  box-shadow: 0 24px 60px rgba(21, 62, 82, .09);
  padding: 1.65rem;
  animation: authCardIn 520ms ease both;
}
.login-logo { height: 44px; width: 44px; border-radius: 12px; flex-shrink: 0; }
.login-brand-caption {
  margin-top: .15rem;
  color: var(--auth-accent-strong);
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
}
.login-env-pill {
  border-radius: 999px;
  border: 1px solid var(--auth-accent-line);
  background: var(--auth-accent-soft);
  color: var(--auth-accent-deep);
  padding: .42rem .75rem;
  font-size: .7rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s ease, box-shadow .15s ease;
}
.login-env-pill:hover { background: #dcefeb; box-shadow: 0 7px 16px rgba(21, 62, 82, .08); }
.login-intro { margin-top: 2.15rem; margin-bottom: -.25rem; }
.login-intro p {
  color: var(--auth-accent-strong);
  font-size: .72rem;
  font-weight: 650;
  letter-spacing: .18em;
  text-transform: uppercase;
}
.login-label {
  display: block;
  margin-bottom: .45rem;
  color: var(--auth-muted);
  font-size: .66rem;
  font-weight: 600;
  letter-spacing: .11em;
  text-transform: uppercase;
}
.login-field {
  width: 100%;
  border-radius: 12px;
  border: 1px solid var(--auth-border);
  background: #fff;
  color: var(--auth-text);
  font-size: .9rem;
  font-weight: 400;
  padding: .9rem 1rem;
  outline: none;
  transition: border-color .15s ease, box-shadow .15s ease;
}
.login-field:hover { border-color: var(--auth-accent-line); }
.login-field:focus { border-color: var(--auth-accent); box-shadow: 0 0 0 4px rgba(9, 139, 128, .12); }
.login-field-error { display: block; margin-top: .4rem; color: #b93636; font-size: .7rem; font-weight: 600; }
.login-submit {
  margin-top: .75rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .65rem;
  border-radius: 12px;
  background: var(--auth-accent);
  color: #fff;
  padding: .95rem 1rem;
  font-size: .92rem;
  font-weight: 650;
  box-shadow: 0 7px 20px rgba(7, 138, 121, .15);
  transition: background .15s ease, box-shadow .15s ease;
}
.login-submit svg { height: 1.1rem; width: 1.1rem; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2; }
.login-submit:hover { background: #08766d; box-shadow: 0 10px 24px rgba(7, 138, 121, .2); }
.login-submit:disabled { opacity: .65; cursor: not-allowed; }
.login-card-footer {
  margin-top: 1.25rem;
  border-top: 1px solid var(--auth-border);
  padding-top: 1rem;
  color: var(--auth-muted);
  font-size: .69rem;
  font-weight: 400;
}
.login-back-home { display: block; width: fit-content; margin: 14px auto 0; color: var(--auth-accent-strong); text-decoration: none; font-weight: 600; }
.login-back-home:hover { text-decoration: underline; }
.login-env-pill:focus-visible,
.login-submit:focus-visible,
.login-back-home:focus-visible,
.demo-account:focus-visible,
.demo-modal-close:focus-visible { outline: 3px solid rgba(9, 139, 128, .35); outline-offset: 3px; }
.demo-account-grid { display: grid; grid-template-columns: 1fr; gap: .5rem; margin-top: .8rem; }
.demo-account {
  min-width: 0;
  border: 1px solid var(--auth-border);
  border-radius: 12px;
  background: #f5f8fa;
  padding: .6rem .7rem;
  text-align: left;
  transition: border-color .15s ease, background .15s ease;
}
.demo-account:hover { border-color: var(--auth-accent); background: var(--auth-accent-soft); }
.demo-account-role, .demo-account-email { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.demo-account-role { color: var(--auth-text); font-size: .68rem; font-weight: 600; }
.demo-account-email { margin-top: .2rem; color: var(--auth-muted); font-size: .6rem; font-weight: 400; }
.demo-modal-backdrop {
  position: fixed;
  z-index: 50;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(11, 32, 51, .48);
  backdrop-filter: blur(5px);
}
.demo-modal {
  width: min(100%, 28rem);
  max-height: min(43rem, calc(100svh - 2rem));
  overflow-y: auto;
  border: 1px solid var(--auth-border);
  border-radius: 20px;
  background: #fff;
  box-shadow: 0 28px 80px rgba(11, 32, 51, .28);
  padding: 1.25rem;
}
.demo-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.demo-modal-kicker { color: var(--auth-accent-strong); font-size: .63rem; font-weight: 600; letter-spacing: .13em; text-transform: uppercase; }
.demo-modal h2 { margin-top: .22rem; color: var(--auth-text); font-size: 1.1rem; font-weight: 600; }
.demo-modal-close {
  display: grid;
  width: 2rem;
  height: 2rem;
  flex: none;
  place-items: center;
  border-radius: 50%;
  background: var(--auth-accent-soft);
  color: var(--auth-accent-deep);
  font-size: 1.4rem;
  line-height: 1;
  transition: background .15s ease;
}
.demo-modal-close:hover { background: #dcefeb; }
.demo-modal-copy { margin-top: .8rem; color: var(--auth-muted); font-size: .73rem; font-weight: 400; line-height: 1.55; }
.demo-modal-copy strong { color: var(--auth-accent-strong); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
.login-product-panel {
  --banner-accent: #52d2c0;
  --banner-text: #e8f7fa;
  --banner-muted: #a6c9d2;
  position: relative;
  display: flex;
  align-items: center;
  min-width: 0;
  min-height: 100svh;
  padding: clamp(2rem, 5vw, 4.5rem);
  color: var(--banner-text);
  overflow: hidden;
  isolation: isolate;
  background: radial-gradient(ellipse at 90% 0%, #176573, transparent 58%), linear-gradient(145deg, #113749, #0b2033);
}
.banner-aurora {
  position: absolute;
  inset: -22%;
  z-index: -3;
  background: conic-gradient(from 120deg at 50% 50%, rgba(23, 101, 115, .16), rgba(82, 210, 192, .08), rgba(17, 55, 73, .12), rgba(23, 101, 115, .16));
  filter: blur(56px);
  opacity: .45;
  animation: auroraDrift 18s ease-in-out infinite alternate;
}
.banner-grain {
  position: absolute;
  inset: 0;
  z-index: -1;
  opacity: .05;
  pointer-events: none;
  background-image: radial-gradient(circle at 20% 30%, rgba(255, 255, 255, .28) 0 1px, transparent 1px), radial-gradient(circle at 70% 80%, rgba(255, 255, 255, .18) 0 1px, transparent 1px);
  background-size: 18px 18px, 27px 27px;
}
.banner-orb { position: absolute; z-index: -2; border-radius: 50%; filter: blur(18px); opacity: .3; pointer-events: none; animation: orbFloat 12s ease-in-out infinite alternate; }
.banner-orb-one { top: 12%; right: 13%; width: 15rem; height: 15rem; background: rgba(82, 210, 192, .16); }
.banner-orb-two { left: 7%; bottom: 12%; width: 18rem; height: 18rem; background: rgba(23, 101, 115, .2); animation-delay: -4s; }
.banner-orb-three { right: 30%; bottom: 25%; width: 10rem; height: 10rem; background: rgba(82, 210, 192, .08); animation-delay: -8s; }
.banner-shell { position: relative; z-index: 1; width: 100%; max-width: 760px; animation: bannerFadeUp 700ms ease both; }
.banner-brand { display: flex; align-items: center; gap: .9rem; margin-bottom: clamp(2rem, 5vh, 3.4rem); animation: bannerFadeUp 700ms 80ms ease both; }
.banner-logo { width: 48px; height: 48px; flex-shrink: 0; border-radius: 14px; }
.banner-brand-name { color: #fff; font-size: .94rem; font-weight: 650; }
.banner-brand-tagline { margin-top: .15rem; color: var(--banner-muted); font-size: .72rem; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; }
.banner-copy { animation: bannerFadeUp 700ms 180ms ease both; }
.banner-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
/* Keep the supporting line stable as the typewriter headline wraps. */
.banner-type-wrap { display: flex; align-items: flex-start; min-height: calc(3 * 1.1 * clamp(2rem, 3.8vw, 3.6rem)); margin-bottom: clamp(1rem, 2vh, 1.75rem); }
.banner-type-text { color: var(--banner-text); font-family: 'Space Grotesk', 'Inter', sans-serif; font-size: clamp(2rem, 3.8vw, 3.6rem); font-weight: 600; line-height: 1.1; letter-spacing: -.035em; max-width: 15ch; }
.banner-cursor { display: inline-block; width: 3px; height: .85em; margin-left: .08em; vertical-align: -.05em; border-radius: 999px; background: var(--banner-accent); animation: cursorBlink 800ms steps(2, start) infinite; }
.banner-support { max-width: 620px; color: var(--banner-muted); font-size: clamp(.92rem, 1.35vw, 1.08rem); font-weight: 400; line-height: 1.7; }
@media (max-width: 1180px) { .login-product-panel { padding: 2rem; } }
@media (max-width: 1023px) { .login-shell { grid-template-columns: 1fr; } .login-product-panel { display: none; } }
@media (max-width: 640px) { .login-auth-panel { padding: 1rem; } .login-card { border-radius: 20px; padding: 1rem; } }
@media (prefers-reduced-motion: reduce) {
  .login-field, .login-submit, .login-env-pill, .demo-account, .demo-modal-close,
  .login-card, .login-auth-glow, .banner-shell, .banner-brand, .banner-copy,
  .banner-aurora, .banner-orb, .banner-cursor { transition: none; animation: none; }
  .banner-cursor { opacity: 1; }
}
@keyframes authCardIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes authGlowFloat { from { transform: translate3d(-8px, 6px, 0) scale(1); } to { transform: translate3d(10px, -8px, 0) scale(1.04); } }
@keyframes cursorBlink { 0%, 45% { opacity: 1; } 46%, 100% { opacity: 0; } }
@keyframes bannerFadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@keyframes auroraDrift { from { transform: translate3d(-2%, -1%, 0) rotate(0deg) scale(1); } to { transform: translate3d(2%, 2%, 0) rotate(8deg) scale(1.05); } }
@keyframes orbFloat { from { transform: translate3d(0, 0, 0) scale(1); } to { transform: translate3d(18px, -24px, 0) scale(1.06); } }
</style>
