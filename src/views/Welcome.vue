<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useStore } from '../store/index.js'
import { authService } from '../services/authService.js'

defineProps({ isNightMode: Boolean })
defineEmits(['toggle-night-mode'])

const route = useRoute()
const { isAuthenticated } = useStore()
const signedIn = computed(() => isAuthenticated() && authService.isAuthenticated())
const entryLink = computed(() => {
  if (signedIn.value) return { name: 'dashboard' }
  const redirect = route.query.redirect
  return {
    name: 'login',
    query: typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//')
      ? { redirect }
      : {}
  }
})
const year = new Date().getFullYear()
const logoUrl = '/favicon.svg'
const modules = [
  { label: 'People', detail: 'Your team, connected', icon: 'people' },
  { label: 'Attendance', detail: 'Office & remote', icon: 'clock' },
  { label: 'Payroll', detail: 'Clarity in every cycle', icon: 'payroll' },
  { label: 'Projects', detail: 'Keep work moving', icon: 'projects' }
]
const features = [
  { number: '01', title: 'People come first.', copy: 'From onboarding to leave requests, give your team one place for their everyday HR needs.' },
  { number: '02', title: 'Stay in the loop.', copy: 'Keep attendance, approvals, and payroll within reach, with access tailored to your role.' },
  { number: '03', title: 'Work together.', copy: 'Connect people and projects with shared schedules, team messages, and a clear view of the work.' }
]
</script>

<template>
  <div class="welcome-page" :class="{ 'welcome-page--dark': isNightMode }">
    <div class="welcome-beta" role="status">
      <span class="welcome-beta-tag">BETA</span>
      <p>You're exploring an early version of Orbit. Features are evolving, and you may encounter occasional issues.</p>
    </div>

    <div class="welcome-container">
      <header class="welcome-nav">
        <RouterLink :to="{ name: 'welcome' }" class="welcome-brand" aria-label="Orbit HRMS home">
          <img :src="logoUrl" width="42" height="42" alt="" />
          <span>Orbit<span class="welcome-brand-caption">HRMS</span></span>
        </RouterLink>
        <nav class="welcome-nav-actions" aria-label="Welcome navigation">
          <a href="#explore" class="welcome-explore-link">Explore Orbit</a>
          <button
            type="button"
            class="welcome-theme-toggle"
            :aria-label="isNightMode ? 'Switch to light mode' : 'Switch to dark mode'"
            :aria-pressed="isNightMode"
            @click="$emit('toggle-night-mode')"
          >
            <svg v-if="isNightMode" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
            <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z"/></svg>
          </button>
          <RouterLink :to="entryLink" class="welcome-nav-login">{{ signedIn ? 'Dashboard' : 'Log in' }} <span aria-hidden="true">↗</span></RouterLink>
        </nav>
      </header>

      <main>
        <section class="welcome-hero" aria-labelledby="welcome-heading">
          <div class="welcome-copy">
            <div class="welcome-eyebrow"><span></span> PEOPLE. WORK. IN SYNC.</div>
            <h1 id="welcome-heading">A better space<br />for <span>your people.</span></h1>
            <p class="welcome-intro">Meet Orbit, your connected workspace for people, projects, and everyday HR. Less chasing updates. More moving forward together.</p>
            <div class="welcome-hero-actions">
              <RouterLink :to="entryLink" class="welcome-primary">
                {{ signedIn ? 'Open your workspace' : 'Log in to your workspace' }}
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
              </RouterLink>
              <a href="#explore" class="welcome-secondary">Take a look around <span aria-hidden="true">↓</span></a>
            </div>
            <p class="welcome-access-note"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> One workspace. Access built around your role.</p>
          </div>

          <div class="welcome-product" aria-label="Orbit connects people, attendance, payroll, and projects">
            <div class="welcome-product-top"><span class="welcome-product-eyebrow">THE ORBIT WORKSPACE</span><span class="welcome-product-beta">Beta preview</span></div>
            <div class="welcome-orbit-art" aria-hidden="true">
              <div class="welcome-orbit-ring welcome-orbit-ring--outer"></div>
              <div class="welcome-orbit-ring welcome-orbit-ring--inner"></div>
              <span class="welcome-orbit-dot"></span>
              <div class="welcome-orbit-center"><img :src="logoUrl" width="64" height="64" alt="" /></div>
            </div>
            <div class="welcome-module-grid">
              <div v-for="module in modules" :key="module.icon" class="welcome-module">
                <span class="welcome-module-icon">
                  <svg v-if="module.icon === 'people'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M6 20v-2a6 6 0 0 1 12 0v2M19 5a3 3 0 0 1 0 6M22 19v-2a5 5 0 0 0-3-4"/></svg>
                  <svg v-else-if="module.icon === 'clock'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                  <svg v-else-if="module.icon === 'payroll'" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 7h8M8 11h2m4 0h2M8 15h2m4 0h2M8 18h2m4 0h2"/></svg>
                  <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h5l3 4h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><path d="M8 13h8m-8 4h5"/></svg>
                </span>
                <div><h2>{{ module.label }}</h2><p>{{ module.detail }}</p></div>
              </div>
            </div>
            <div class="welcome-product-bottom"><span></span> Everything connected. Everyone in the loop.</div>
          </div>
        </section>

        <section id="explore" class="welcome-features" aria-label="Explore Orbit">
          <article v-for="feature in features" :key="feature.number" class="welcome-feature">
            <span class="welcome-feature-number">{{ feature.number }} /</span>
            <h2>{{ feature.title }}</h2>
            <p>{{ feature.copy }}</p>
          </article>
        </section>
      </main>

      <footer class="welcome-footer"><span>© {{ year }} Orbit HRMS</span><span>Built around people.<span class="welcome-footer-dot">·</span>Beta version</span></footer>
    </div>
  </div>
</template>

<style scoped>
.welcome-page {
  --welcome-bg: #f5f8fa;
  --welcome-text: #123044;
  --welcome-muted: #64788a;
  --welcome-line: #dde7ed;
  --welcome-accent: #087e78;
  --welcome-button: #098b80;
  min-height: 100svh;
  color: var(--welcome-text);
  background: radial-gradient(ellipse at 12% 36%, #e7f5f2 0, transparent 46%), var(--welcome-bg);
}
.welcome-page--dark {
  --welcome-bg: #0e1a28;
  --welcome-text: #edf5f9;
  --welcome-muted: #9ab0c0;
  --welcome-line: #2c3e4e;
  --welcome-accent: #52d2c0;
  --welcome-button: #0e9d8c;
  background: radial-gradient(ellipse at 12% 36%, #102f35 0, transparent 46%), var(--welcome-bg);
}
.welcome-beta { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 12px 24px; background: #fff6e3; border-bottom: 1px solid #f0dfba; color: #7a581c; }
.welcome-beta p { margin: 0; font-size: 11px; line-height: 1.6; }
.welcome-beta-tag { flex-shrink: 0; font-size: 9px; font-weight: 800; letter-spacing: .1em; border: 1px solid #dfc78d; border-radius: 5px; padding: 3px 6px; }
.welcome-page--dark .welcome-beta { background: #30291d; border-color: #51432b; color: #e5ca90; }
.welcome-page--dark .welcome-beta-tag { border-color: #74613b; }
.welcome-container { max-width: 1220px; margin: 0 auto; padding: 0 40px; }
.welcome-nav { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 26px 0; }
.welcome-brand { display: inline-flex; align-items: center; gap: 11px; color: var(--welcome-text); text-decoration: none; font-size: 22px; font-weight: 750; line-height: 1.1; }
.welcome-brand img { border-radius: 12px; }
.welcome-brand-caption { display: block; color: var(--welcome-accent); font-size: 9px; letter-spacing: .21em; font-weight: 700; margin-top: 5px; }
.welcome-nav-actions { display: flex; align-items: center; gap: 26px; }
.welcome-explore-link, .welcome-nav-login { color: var(--welcome-text); text-decoration: none; font-size: 12px; font-weight: 600; }
.welcome-nav-login { display: inline-flex; align-items: center; gap: 14px; border: 1px solid var(--welcome-line); padding: 10px 17px; border-radius: 9px; }
.welcome-theme-toggle { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; color: var(--welcome-muted); border: 1px solid var(--welcome-line); background: transparent; cursor: pointer; }
.welcome-page svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.welcome-hero { display: grid; grid-template-columns: 1.1fr 1fr; align-items: center; gap: 56px; padding: 45px 0 55px; }
.welcome-eyebrow { display: flex; align-items: center; gap: 9px; font-size: 10px; font-weight: 700; letter-spacing: .14em; color: var(--welcome-accent); }
.welcome-eyebrow span { width: 6px; height: 6px; border-radius: 50%; background: var(--welcome-accent); box-shadow: 0 0 0 4px #0b9b8512; }
.welcome-copy h1 { margin: 22px 0 20px; font-size: clamp(42px, 4.9vw, 64px); line-height: 1.08; font-weight: 600; letter-spacing: -.045em; }
.welcome-copy h1 span { color: var(--welcome-accent); }
.welcome-intro { margin: 0; max-width: 420px; color: var(--welcome-muted); font-size: 14px; line-height: 1.9; }
.welcome-hero-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 20px; margin-top: 30px; }
.welcome-primary { display: inline-flex; align-items: center; justify-content: center; gap: 18px; padding: 15px 20px; border-radius: 10px; color: #fff; background: var(--welcome-button); text-decoration: none; font-size: 12px; font-weight: 650; box-shadow: 0 7px 20px #078a7926; }
.welcome-primary:hover { background: #08766d; }
.welcome-secondary { color: var(--welcome-muted); font-size: 11px; font-weight: 600; text-decoration: none; }
.welcome-secondary span { margin-left: 7px; }
.welcome-access-note { display: flex; align-items: center; gap: 7px; margin: 19px 0 0; color: var(--welcome-muted); font-size: 10px; }
.welcome-access-note svg { width: 13px; height: 13px; }
.welcome-product { position: relative; overflow: hidden; padding: 24px; border-radius: 24px; border: 1px solid #215266; background: radial-gradient(ellipse at 90% 0%, #176573 0, transparent 58%), linear-gradient(145deg, #113749, #0b2033); color: #e8f7fa; box-shadow: 0 24px 50px #153e521a; }
.welcome-product-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.welcome-product-eyebrow { font-size: 8px; font-weight: 650; letter-spacing: .16em; color: #a6c9d2; }
.welcome-product-beta { border: 1px solid #99e5d52e; color: #b9eade; border-radius: 50px; font-size: 9px; padding: 5px 9px; }
.welcome-orbit-art { position: relative; height: 175px; display: grid; place-items: center; }
.welcome-orbit-ring { position: absolute; border: 1px solid #65cfc22e; border-radius: 50%; }
.welcome-orbit-ring--outer { width: 260px; height: 140px; transform: rotate(-24deg); }
.welcome-orbit-ring--inner { width: 185px; height: 110px; transform: rotate(25deg); }
.welcome-orbit-center { display: grid; place-items: center; width: 86px; height: 86px; border: 1px solid #5ad3c045; border-radius: 24px; background: #132f40; transform: rotate(-8deg); box-shadow: 0 0 50px #20b6a928; }
.welcome-orbit-center img { transform: rotate(8deg); border-radius: 16px; }
.welcome-orbit-dot { position: absolute; width: 9px; height: 9px; border-radius: 50%; background: #58dac3; left: calc(50% + 101px); top: 30px; box-shadow: 0 0 0 5px #52d2c015; }
.welcome-module-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.welcome-module { display: flex; align-items: center; gap: 10px; background: #d8f3fa08; border: 1px solid #a2ced61c; border-radius: 12px; padding: 16px 12px; }
.welcome-module-icon { display: grid; place-items: center; width: 32px; height: 32px; flex-shrink: 0; color: #7ad7c9; background: #30c5af13; border-radius: 9px; }
.welcome-module-icon svg { width: 17px; height: 17px; }
.welcome-module h2 { margin: 0; font-family: inherit; font-size: 12px; letter-spacing: 0; font-weight: 650; color: #edf8fa; }
.welcome-module p { margin: 5px 0 0; font-size: 9px; color: #a5bbc9; }
.welcome-product-bottom { display: flex; justify-content: center; align-items: center; gap: 7px; margin-top: 20px; font-size: 9px; color: #acc6cf; }
.welcome-product-bottom span { width: 5px; height: 5px; border-radius: 50%; background: #61cbb7; }
.welcome-features { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid var(--welcome-line); border-bottom: 1px solid var(--welcome-line); padding: 28px 0; gap: 30px; scroll-margin-top: 24px; }
.welcome-feature + .welcome-feature { border-left: 1px solid var(--welcome-line); padding-left: 30px; }
.welcome-feature-number { font-size: 9px; letter-spacing: .12em; font-weight: 700; color: var(--welcome-accent); }
.welcome-feature h2 { margin: 10px 0 7px; font-size: 17px; font-weight: 600; }
.welcome-feature p { margin: 0; color: var(--welcome-muted); font-size: 11px; line-height: 1.8; max-width: 290px; }
.welcome-footer { display: flex; justify-content: space-between; gap: 16px; padding: 23px 0; color: var(--welcome-muted); font-size: 10px; }
.welcome-footer-dot { margin: 0 10px; }
.welcome-page a:focus-visible, .welcome-page button:focus-visible { outline: 3px solid var(--welcome-accent); outline-offset: 5px; }
.welcome-secondary:hover, .welcome-explore-link:hover, .welcome-nav-login:hover { color: var(--welcome-accent); }
@media (min-width: 1600px) { .welcome-hero { padding-top: 80px; padding-bottom: 80px; } }
@media (max-width: 960px) {
  .welcome-container { padding: 0 28px; }
  .welcome-hero { gap: 28px; }
  .welcome-copy h1 { font-size: 46px; }
  .welcome-product { padding: 20px; }
  .welcome-module { padding: 13px 9px; gap: 7px; }
}
@media (max-width: 720px) {
  .welcome-container { padding: 0 22px; }
  .welcome-nav { padding: 20px 0; }
  .welcome-nav-actions { gap: 12px; }
  .welcome-explore-link { display: none; }
  .welcome-hero { grid-template-columns: 1fr; gap: 36px; padding: 30px 0 35px; }
  .welcome-copy h1 { font-size: clamp(40px, 8vw, 56px); }
  .welcome-intro { max-width: 510px; }
  .welcome-product { max-width: 540px; width: 100%; justify-self: center; }
  .welcome-features { grid-template-columns: 1fr; gap: 22px; }
  .welcome-feature + .welcome-feature { border-left: 0; border-top: 1px solid var(--welcome-line); padding: 22px 0 0; }
  .welcome-feature p { max-width: 100%; }
  .welcome-beta { padding: 10px 16px; align-items: flex-start; gap: 10px; }
  .welcome-beta p { font-size: 10px; }
  .welcome-footer { flex-wrap: wrap; }
}
</style>
