<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'

const router = useRouter()
const { state, availableRoles, roleLabel, setCurrentUserRole, setCurrentUser } = useStore()
const selectedRole = ref('employee')
const selectedUser = ref('1')
const loginError = ref('')
const typedHeadline = ref('')

const superAdminUser = { id: 99, name: 'Admin User', email: 'admin@company.com', status: 'active' }
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

const currentUsers = computed(() => {
  if (selectedRole.value === 'super_admin') return [superAdminUser]
  return state.employees.filter(employee => employee.role === selectedRole.value)
})

const selectedUserRecord = computed(() =>
  currentUsers.value.find(user => user.id.toString() === selectedUser.value)
)

function onRoleChange() {
  loginError.value = ''
  selectedUser.value = currentUsers.value[0]?.id?.toString() || ''
}

function login() {
  loginError.value = ''
  const user = currentUsers.value.find(u => u.id.toString() === selectedUser.value)
  if (user) {
    if (user.status === 'blocked') {
      loginError.value = 'This account is blocked. Please contact HR or Super Admin.'
      return
    }
    setCurrentUser(user.id, user.name, selectedRole.value, user.email)
    setCurrentUserRole(selectedRole.value)
    router.push({ name: 'dashboard' })
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

onMounted(startBannerAnimation)

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
            <div class="login-env-pill">Demo</div>
          </div>

          <div class="login-intro">
            <p>Welcome back</p>
          </div>

          <div class="mt-7 space-y-4">
            <label class="block">
              <span class="login-label">Role</span>
              <select v-model="selectedRole" @change="onRoleChange" class="login-field">
                <option v-for="role in availableRoles" :key="role" :value="role">{{ roleLabel(role) }}</option>
              </select>
            </label>

            <label class="block">
              <span class="login-label">Account</span>
              <select v-model="selectedUser" class="login-field">
                <option v-for="user in currentUsers" :key="user.id" :value="user.id.toString()">
                  {{ user.name }}{{ user.status === 'blocked' ? ' (blocked)' : '' }}
                </option>
              </select>
            </label>
          </div>

          <div v-if="loginError" class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-bold text-rose-700">
            {{ loginError }}
          </div>

          <button @click="login" class="login-submit w-full">
            <span>Continue to dashboard</span>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>

          <div class="login-card-footer">
            Demo sign-in for frontend preview.
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

.login-submit {
  /* Spacing lives here, not on an `mt-8` utility in the markup: Tailwind's
     Preflight resets `button { margin: 0 }` at the same specificity as a margin
     utility, and won the cascade — the button sat flush against the Account
     field. A scoped class rule is outside that tie. */
  margin-top: 2rem;
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

.login-card-footer {
  margin-top: 1.25rem;
  border-top: 1px solid rgba(169, 223, 206, 0.5);
  padding-top: 1rem;
  color: var(--auth-muted);
  font-size: 0.69rem;
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
