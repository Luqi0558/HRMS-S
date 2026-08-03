<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { onboardingService } from '../services/onboardingService.js'
import { ApiError } from '../services/apiClient.js'

const router = useRouter()
const route = useRoute()

// 'loading' while the token is resolved, 'invalid' if it is not usable,
// 'form' for the wizard, 'success' once submitted.
const step = ref('loading')
const currentStep = ref(0)
const password = ref('')
const confirm = ref('')
const openDropdown = ref(null)

const inviteToken = ref('')
const invitedEmployee = ref(null)
const inviteError = ref('')
const submitError = ref('')
const fieldErrors = ref({})
const isSubmitting = ref(false)

const profile = ref({
  phone: '',
  cnic: '',
  address: '',
  emergencyContact: '',
  bankAccount: '',
  taxNumber: '',
  designation: '',
  stacks: [],
  experienceYears: '',
  skills: [],
  githubUrl: '',
  portfolioUrl: '',
  linkedinUrl: '',
  photo: null,
  cv: null,
  cnicFront: null,
  cnicBack: null,
  education: null,
  experience: null
})

const steps = [
  { title: 'Personal details', subtitle: 'Basic information' },
  { title: 'Technical stack', subtitle: 'Developer profile' },
  { title: 'Documents', subtitle: 'Upload records' },
  { title: 'Security', subtitle: 'Set password' }
]

const stackOptions = [
  '.NET Developer',
  'MERN Stack',
  'MEAN / Angular',
  'Vue / Nuxt',
  'React / Next.js',
  'Laravel / PHP',
  'Python / Django',
  'Java / Spring Boot',
  'Mobile App Developer',
  'QA Automation',
  'DevOps / Cloud',
  'UI/UX Design'
]

const skillOptions = [
  'C#',
  'ASP.NET Core',
  'Node.js',
  'Express',
  'React',
  'Angular',
  'Vue',
  'Laravel',
  'Python',
  'Java',
  'SQL',
  'MongoDB',
  'AWS',
  'Docker',
  'Selenium',
  'Figma'
]

const isLastStep = computed(() => currentStep.value === steps.length - 1)
const progressWidth = computed(() => `${(currentStep.value / (steps.length - 1)) * 100}%`)

/**
 * Resolves the token in the URL to the person actually invited.
 *
 * This previously scanned the mock store for any employee with status
 * 'invited', which picked an arbitrary one once two invitations were
 * outstanding — and let anyone who opened the page complete a stranger's
 * onboarding. The token is now the credential.
 */
onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''

  if (!token) {
    inviteError.value = 'This link is missing its invitation code. Please use the link from your invitation email.'
    step.value = 'invalid'
    return
  }

  inviteToken.value = token

  try {
    const data = await onboardingService.resolve(token)
    // The email lives on the invitation (pinned at send time), not the
    // employee — merged here so the template has one object to read.
    invitedEmployee.value = { ...data.employee, email: data.invitation.email }
    // Prefill what HR already recorded, so the invitee confirms rather than
    // retypes it.
    profile.value.designation = data.employee.designation || ''
    step.value = 'form'
  } catch (error) {
    inviteError.value = error instanceof ApiError
      ? error.message
      : 'We could not check this invitation. Please try again in a moment.'
    step.value = 'invalid'
  }
})

function toggleDropdown(name) {
  openDropdown.value = openDropdown.value === name ? null : name
}

function toggleSelection(collection, value) {
  const selected = profile.value[collection]
  if (selected.includes(value)) {
    profile.value[collection] = selected.filter(item => item !== value)
  } else {
    selected.push(value)
  }
}

function removeSelection(collection, value) {
  profile.value[collection] = profile.value[collection].filter(item => item !== value)
}

function nextStep() {
  if (!isLastStep.value) currentStep.value += 1
}

function previousStep() {
  if (currentStep.value > 0) currentStep.value -= 1
}

const MIN_PASSWORD_LENGTH = 8

const passwordProblem = computed(() => {
  if (!password.value) return 'Please choose a password.'
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    return `Use at least ${MIN_PASSWORD_LENGTH} characters.`
  }
  if (password.value !== confirm.value) return 'The two passwords do not match.'
  return ''
})

async function submitRegistration() {
  if (isSubmitting.value) return

  submitError.value = ''
  fieldErrors.value = {}

  // Client-side first so the obvious mistakes do not cost a round trip. The
  // server re-checks all of it — this is convenience, not the real rule.
  if (passwordProblem.value) {
    submitError.value = passwordProblem.value
    return
  }

  isSubmitting.value = true

  try {
    await onboardingService.complete({
      token: inviteToken.value,
      password: password.value,
      passwordConfirmation: confirm.value,
      personal: {
        phone: profile.value.phone,
        cnic: profile.value.cnic,
        address: profile.value.address,
        emergencyContact: profile.value.emergencyContact,
        bankAccount: profile.value.bankAccount,
        taxNumber: profile.value.taxNumber
      },
      technical: {
        designation: profile.value.designation,
        experienceYears: profile.value.experienceYears,
        githubUrl: profile.value.githubUrl,
        portfolioUrl: profile.value.portfolioUrl,
        linkedinUrl: profile.value.linkedinUrl,
        stacks: [...profile.value.stacks],
        skills: [...profile.value.skills]
      },
      documents: {
        photo: profile.value.photo,
        cv: profile.value.cv,
        cnicFront: profile.value.cnicFront,
        cnicBack: profile.value.cnicBack,
        education: profile.value.education,
        experience: profile.value.experience
      }
    })

    step.value = 'success'
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = error.errors || {}

      // A dead token cannot be recovered from by resubmitting, so send the
      // user back to the invalid screen rather than leaving them retrying.
      if (error.code === 'invalid_invitation') {
        inviteError.value = error.message
        step.value = 'invalid'
        return
      }

      submitError.value = error.status === 422
        ? firstValidationMessage(error) || error.message
        : error.message
    } else {
      submitError.value = 'Cannot reach the server. Please check your connection and try again.'
    }
  } finally {
    isSubmitting.value = false
  }
}

/** Surfaces the first field error, since the wizard's last step has no room for a list. */
function firstValidationMessage(error) {
  const first = Object.values(error.errors || {})[0]
  return Array.isArray(first) ? first[0] : null
}
</script>

<template>
  <div class="invite-page">
    <div class="invite-heading">
      <div class="invite-title">Welcome to Orbit HRMS</div>
      <div v-if="invitedEmployee" class="invite-subtitle">
        <span>{{ invitedEmployee.name }}</span> has been invited to join <span>Company Inc.</span>
      </div>
    </div>

    <!-- Resolving the token. Brief, but without it the wizard would flash on
         screen before we know the invitation is even valid. -->
    <section v-if="step === 'loading'" class="invite-card invite-state-card">
      <div class="invite-state-title">Checking your invitation…</div>
    </section>

    <!-- Missing, expired, or already-used link. There is no way forward from
         here, so no form is shown — only the reason and a way to sign in. -->
    <section v-else-if="step === 'invalid'" class="invite-card invite-state-card">
      <div class="invite-state-icon" aria-hidden="true">!</div>
      <div class="invite-state-title">This invitation cannot be used</div>
      <p class="invite-state-message">{{ inviteError }}</p>
      <button type="button" class="link-btn" @click="router.push({ name: 'login' })">Go to sign in</button>
    </section>

    <section v-else-if="step === 'form'" class="invite-card">
      <div class="stepper-wrap">
        <div class="step-line">
          <div class="step-line-active" :style="{ width: progressWidth }"></div>
        </div>
        <button
          v-for="(item, index) in steps"
          :key="item.title"
          class="step-item"
          type="button"
          @click="currentStep = index"
        >
          <span class="step-dot" :class="{ active: index <= currentStep }">{{ index + 1 }}</span>
          <span class="step-title" :class="{ active: index === currentStep }">{{ item.title }}</span>
          <span class="step-subtitle">{{ item.subtitle }}</span>
        </button>
      </div>

      <div class="form-body">
        <div v-if="currentStep === 0" class="form-section">
          <div class="form-grid two">
            <label class="field">
              <span>Office email</span>
              <input type="email" disabled :value="invitedEmployee?.email || ''" />
            </label>
            <label class="field">
              <span>Phone number</span>
              <input v-model="profile.phone" type="tel" placeholder="+92 300 0000000" />
            </label>
            <label class="field">
              <span>CNIC / National ID</span>
              <input v-model="profile.cnic" type="text" placeholder="00000-0000000-0" />
            </label>
            <label class="field">
              <span>Emergency contact</span>
              <input v-model="profile.emergencyContact" type="tel" placeholder="Name and phone number" />
            </label>
            <label class="field">
              <span>Bank account / IBAN</span>
              <input v-model="profile.bankAccount" type="text" placeholder="PK00..." />
            </label>
            <label class="field">
              <span>Tax number</span>
              <input v-model="profile.taxNumber" type="text" placeholder="Optional if not issued" />
            </label>
          </div>
          <label class="field">
            <span>Residential address</span>
            <textarea v-model="profile.address" rows="5" placeholder="Enter complete current address"></textarea>
          </label>
        </div>

        <div v-else-if="currentStep === 1" class="form-section">
          <div class="form-grid two">
            <label class="field">
              <span>Designation</span>
              <input v-model="profile.designation" type="text" placeholder="Software Engineer" />
            </label>
            <label class="field">
              <span>Experience</span>
              <select v-model="profile.experienceYears">
                <option value="">Select years</option>
                <option value="0-1">0-1 years</option>
                <option value="1-3">1-3 years</option>
                <option value="3-5">3-5 years</option>
                <option value="5+">5+ years</option>
              </select>
            </label>
          </div>

          <div class="form-grid two">
            <div class="field">
              <span>Stack selection</span>
              <div class="select2-field" @click="toggleDropdown('stacks')">
                <div v-if="profile.stacks.length" class="select2-tags">
                  <button v-for="stack in profile.stacks" :key="stack" type="button" class="select2-tag" @click.stop="removeSelection('stacks', stack)">
                    {{ stack }} <span>x</span>
                  </button>
                </div>
                <span v-else class="select2-placeholder">Select stacks</span>
                <span class="select2-arrow">⌄</span>
              </div>
              <div v-if="openDropdown === 'stacks'" class="select2-menu">
                <button
                  v-for="stack in stackOptions"
                  :key="stack"
                  type="button"
                  class="select2-option"
                  :class="{ selected: profile.stacks.includes(stack) }"
                  @click="toggleSelection('stacks', stack)"
                >
                  <span>{{ stack }}</span>
                  <span v-if="profile.stacks.includes(stack)">✓</span>
                </button>
              </div>
            </div>
            <div class="field">
              <span>Core technologies</span>
              <div class="select2-field" @click="toggleDropdown('skills')">
                <div v-if="profile.skills.length" class="select2-tags">
                  <button v-for="skill in profile.skills" :key="skill" type="button" class="select2-tag" @click.stop="removeSelection('skills', skill)">
                    {{ skill }} <span>x</span>
                  </button>
                </div>
                <span v-else class="select2-placeholder">Select technologies</span>
                <span class="select2-arrow">⌄</span>
              </div>
              <div v-if="openDropdown === 'skills'" class="select2-menu">
                <button
                  v-for="skill in skillOptions"
                  :key="skill"
                  type="button"
                  class="select2-option"
                  :class="{ selected: profile.skills.includes(skill) }"
                  @click="toggleSelection('skills', skill)"
                >
                  <span>{{ skill }}</span>
                  <span v-if="profile.skills.includes(skill)">✓</span>
                </button>
              </div>
            </div>
          </div>

          <div class="form-grid three">
            <label class="field">
              <span>GitHub</span>
              <input v-model="profile.githubUrl" type="url" placeholder="https://github.com/..." />
            </label>
            <label class="field">
              <span>Portfolio</span>
              <input v-model="profile.portfolioUrl" type="url" placeholder="https://..." />
            </label>
            <label class="field">
              <span>LinkedIn</span>
              <input v-model="profile.linkedinUrl" type="url" placeholder="https://linkedin.com/in/..." />
            </label>
          </div>
        </div>

        <div v-else-if="currentStep === 2" class="form-section">
          <div class="form-grid two">
            <label class="field">
              <span>Profile photo <small>(optional)</small></span>
              <input type="file" accept="image/*" @change="profile.photo = $event.target.files[0]" />
            </label>
            <label class="field">
              <span>CV / Resume <small>(optional)</small></span>
              <input type="file" accept=".pdf,.doc,.docx" @change="profile.cv = $event.target.files[0]" />
            </label>
            <label class="field">
              <span>CNIC front</span>
              <input type="file" accept="image/*,.pdf" @change="profile.cnicFront = $event.target.files[0]" />
            </label>
            <label class="field">
              <span>CNIC back</span>
              <input type="file" accept="image/*,.pdf" @change="profile.cnicBack = $event.target.files[0]" />
            </label>
            <label class="field">
              <span>Education documents</span>
              <input type="file" accept=".pdf,image/*" @change="profile.education = $event.target.files[0]" />
            </label>
            <label class="field">
              <span>Experience letter</span>
              <input type="file" accept=".pdf,image/*" @change="profile.experience = $event.target.files[0]" />
            </label>
          </div>
        </div>

        <div v-else class="form-section">
          <div class="form-grid two">
            <label class="field">
              <span>Create password</span>
              <input v-model="password" type="password" placeholder="********" />
            </label>
            <label class="field">
              <span>Confirm password</span>
              <input v-model="confirm" type="password" placeholder="********" />
            </label>
          </div>
          <div v-if="password && password.length < MIN_PASSWORD_LENGTH" class="error-text">
            Password must be at least {{ MIN_PASSWORD_LENGTH }} characters.
          </div>
          <div v-else-if="password && confirm && password !== confirm" class="error-text">Passwords do not match.</div>
        </div>
      </div>

      <!-- Server-side failures: validation the client did not catch, or a
           rejected file. Field errors are keyed like "personal.phone". -->
      <div v-if="submitError" role="alert" class="submit-error">{{ submitError }}</div>

      <div class="actions">
        <button v-if="currentStep > 0" type="button" class="secondary-btn" :disabled="isSubmitting" @click="previousStep">Back</button>
        <button v-if="!isLastStep" type="button" class="primary-btn" @click="nextStep">Next</button>
        <button v-else type="button" class="primary-btn" :disabled="isSubmitting" @click="submitRegistration">
          {{ isSubmitting ? 'Submitting…' : 'Submit registration' }}
        </button>
      </div>
    </section>

    <section v-else class="invite-card success-card">
      <div class="success-icon">✓</div>
      <div class="success-title">Account activated</div>
      <div class="success-copy">You can now sign in with your office email.</div>
      <button type="button" class="link-btn" @click="router.push({ name: 'login' })">Go to sign in</button>
    </section>
  </div>
</template>

<style scoped>
.invite-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: flex-start;
  padding: 22px 24px;
  overflow-y: auto;
  background-color: #f8fafc;
  background-image: radial-gradient(#d9dee8 1px, transparent 1px);
  background-size: 18px 18px;
}

.invite-page > .invite-card {
  margin-left: auto;
  margin-right: auto;
}

.invite-heading {
  width: min(100%, 1060px);
  margin: 0 auto 18px;
  text-align: center;
}

.invite-title {
  color: #0f172a;
  font-size: 22px;
  font-weight: 800;
}

.invite-subtitle {
  margin-top: 6px;
  color: #64748b;
  font-size: 14px;
}

.invite-subtitle span {
  color: #0f172a;
  font-weight: 700;
}

.invite-card {
  width: min(100%, 1060px);
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04);
  overflow: visible;
}

.stepper-wrap {
  position: relative;
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  column-gap: 24px;
  padding: 24px 58px 20px;
  border-bottom: 1px solid #f1f5f9;
}

.step-line {
  position: absolute;
  top: 38px;
  left: calc(58px + 12.5%);
  right: calc(58px + 12.5%);
  height: 5px;
  border-radius: 999px;
  background: #eef2f7;
  overflow: hidden;
}

.step-line-active {
  height: 100%;
  border-radius: 999px;
  background: #2563eb;
  transition: width 180ms ease;
}

.step-item {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  border: 0;
  background: transparent;
  cursor: pointer;
  min-width: 0;
}

.step-dot {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ffffff;
  border: 5px solid #f1f5f9;
  color: #94a3b8;
  font-size: 12px;
  font-weight: 700;
}

.step-dot.active {
  background: #2563eb;
  border-color: #dbeafe;
  color: #ffffff;
}

.step-title {
  margin-top: 14px;
  color: #334155;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.2;
}

.step-title.active {
  color: #2563eb;
}

.step-subtitle {
  margin-top: 4px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.2;
}

.form-body {
  padding: 24px 34px 18px;
  min-height: 0;
}

.form-section {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-grid {
  display: grid;
  gap: 16px;
}

.form-grid.two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.form-grid.three {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.field,
.choice-block {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field span,
.field-label {
  color: #334155;
  font-size: 13px;
  font-weight: 700;
}

.field small {
  color: #94a3b8;
  font-weight: 500;
}

.field input,
.field select,
.field textarea {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  color: #0f172a;
  font-size: 14px;
  line-height: 1.4;
  padding: 12px 14px;
  outline: none;
}

.field textarea {
  min-height: 88px;
  resize: vertical;
}

.field .multi-select {
  min-height: 148px;
  padding: 8px;
}

.field .multi-select option {
  border-radius: 4px;
  padding: 7px 8px;
}

.select2-field {
  position: relative;
  min-height: 48px;
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  padding: 7px 38px 7px 10px;
  cursor: pointer;
}

.select2-field:focus-within {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
}

.select2-placeholder {
  color: #94a3b8;
  font-size: 14px;
}

.select2-arrow {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #64748b;
  font-size: 18px;
  pointer-events: none;
}

.select2-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.select2-tag {
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 700;
  padding: 5px 8px;
  cursor: pointer;
}

.select2-tag span {
  margin-left: 5px;
  color: #2563eb;
}

.select2-menu {
  max-height: 220px;
  overflow-y: auto;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  box-shadow: 0 12px 25px rgba(15, 23, 42, 0.12);
  padding: 6px;
}

.select2-option {
  width: 100%;
  border: 0;
  border-radius: 5px;
  background: #ffffff;
  color: #334155;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 10px;
  font-size: 14px;
  text-align: left;
  cursor: pointer;
}

.select2-option:hover,
.select2-option.selected {
  background: #eff6ff;
  color: #1d4ed8;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
}

.field input:disabled {
  background: #f8fafc;
  color: #64748b;
}

.choice-grid {
  display: grid;
  gap: 12px;
}

.choice-grid.three {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.choice-grid.four {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.choice {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 44px;
  padding: 10px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  color: #334155;
  font-size: 13px;
  font-weight: 600;
}

.choice.selected {
  border-color: #2563eb;
  background: #eff6ff;
  color: #1d4ed8;
}

.choice input {
  width: 16px;
  height: 16px;
  accent-color: #2563eb;
}

.actions {
  position: sticky;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 12px;
  padding: 14px 34px 18px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.72), #ffffff 38%);
  border-top: 1px solid #eef2f7;
  z-index: 5;
}

.primary-btn,
.secondary-btn {
  border-radius: 6px;
  border: 1px solid transparent;
  font-size: 14px;
  font-weight: 700;
  line-height: 1;
  padding: 14px 22px;
  cursor: pointer;
}

.primary-btn {
  width: min(100%, 460px);
  background: #2563eb;
  color: #ffffff;
}

.secondary-btn {
  min-width: 140px;
  background: #ffffff;
  border-color: #cbd5e1;
  color: #334155;
}

.error-text {
  color: #dc2626;
  font-size: 13px;
}

/* Shown for the loading and invalid-invitation states, which replace the
   wizard entirely rather than appearing inside it. */
.invite-state-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
  padding: 48px 32px;
}

.invite-state-icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 999px;
  background: #FDF1EE;
  color: #B93636;
  font-size: 22px;
  font-weight: 900;
}

.invite-state-title {
  font-size: 17px;
  font-weight: 800;
  color: #12161C;
}

.invite-state-message {
  max-width: 420px;
  color: #6B7280;
  font-size: 14px;
  line-height: 1.6;
}

.submit-error {
  margin-top: 16px;
  border: 1px solid #F3C0B4;
  border-radius: 8px;
  background: #FDF1EE;
  padding: 12px 14px;
  color: #982C2C;
  font-size: 13px;
  font-weight: 700;
}

.primary-btn:disabled,
.secondary-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.success-card {
  padding: 36px;
  text-align: center;
}

.success-icon {
  width: 42px;
  height: 42px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 14px;
  background: #eff6ff;
  color: #2563eb;
  font-weight: 800;
}

.success-title {
  color: #0f172a;
  font-size: 16px;
  font-weight: 700;
}

.success-copy {
  color: #64748b;
  font-size: 14px;
  margin-top: 6px;
}

.link-btn {
  margin-top: 18px;
  border: 0;
  background: transparent;
  color: #2563eb;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}

@media (max-width: 820px) {
  .stepper-wrap {
    padding-left: 22px;
    padding-right: 22px;
    column-gap: 8px;
  }

  .step-line {
    left: calc(22px + 12.5%);
    right: calc(22px + 12.5%);
  }

  .step-title {
    font-size: 12px;
  }

  .step-subtitle {
    display: none;
  }

  .form-grid.two,
  .form-grid.three,
  .choice-grid.three,
  .choice-grid.four {
    grid-template-columns: 1fr;
  }

  .actions {
    flex-direction: column-reverse;
    align-items: stretch;
  }

  .primary-btn,
  .secondary-btn {
    width: 100%;
  }
}
</style>
