import './setup.js'

import { describe, it, expect, beforeEach } from 'vitest'
import { useStore } from '../src/store/index.js'

/**
 * Store mapping for two features that used to live only in browser memory.
 *
 * Client payments: every received / pending / commission figure read
 * `module.payments`, which the API never sent, so all of them were zero.
 * They now come from the payments ledger, keyed by module id.
 *
 * Claim review stages: 'view' and 'processing' were set on the object in
 * memory. The API now keeps them beside the status; the store shows the stage
 * as the status while the claim is undecided and builds the timeline from the
 * server's timestamps.
 */
describe('client payments', () => {
  beforeEach(() => {
    const { setModulePayments, setCurrentUser } = useStore()
    setCurrentUser(1, 'Admin', 'super_admin', 'admin@test.com', 1)
    setModulePayments([])
  })

  it('derives received and commission from the ledger by module id', () => {
    const { setModulePayments, projectReceived, projectPending, projectCommissionEarned } = useStore()
    const module = { id: 5, budget: '100000', commission: { type: 'percent', value: '10', status: 'pending' } }

    setModulePayments([
      { id: 1, moduleId: 5, amount: '25000.00', date: '2026-10-01' },
      { id: 2, moduleId: 5, amount: '15000.00', date: '2026-10-03' },
      { id: 3, moduleId: 9, amount: '99999.00', date: '2026-10-03' }
    ])

    expect(projectReceived(module)).toBe(40000)
    expect(projectPending(module)).toBe(60000)
    expect(projectCommissionEarned(module)).toBe(4000)
  })

  it('puts a newly logged payment first and removes one by id', () => {
    const { state, setModulePayments, addModulePayment, removeModulePayment, paymentsForModule } = useStore()
    setModulePayments([{ id: 1, moduleId: 5, amount: '100.00', date: '2026-10-01' }])

    addModulePayment({ id: 2, moduleId: 5, amount: '50.00', date: '2026-10-05' })
    expect(paymentsForModule(5).map(payment => payment.id)).toEqual([2, 1])

    removeModulePayment(1)
    expect(state.modulePayments.map(payment => payment.id)).toEqual([2])
  })

  it('forgets payments on sign-out', () => {
    const { state, setModulePayments, clearCurrentUser } = useStore()
    setModulePayments([{ id: 1, moduleId: 5, amount: '100.00', date: '2026-10-01' }])

    clearCurrentUser()

    expect(state.modulePayments).toEqual([])
  })
})

/**
 * The onboarding queues used to test `employee.onboarding`, which every
 * employee with a loaded profile has — so they listed the whole company.
 */
describe('onboarding queue membership', () => {
  const { isAwaitingOnboardingReview } = useStore()

  it('includes invitees and unreviewed submissions only', () => {
    const submitted = { status: 'active', onboardingReview: { status: 'pending' }, onboarding: { documents: [{ type: 'cv', status: 'submitted' }] } }

    expect(isAwaitingOnboardingReview({ status: 'invited' })).toBe(true)
    expect(isAwaitingOnboardingReview(submitted)).toBe(true)
  })

  it('leaves out verified, correction-requested and long-standing staff', () => {
    const documents = [{ type: 'cv', status: 'submitted' }]

    expect(isAwaitingOnboardingReview({ status: 'active', onboardingReview: { status: 'verified' }, onboarding: { documents } })).toBe(false)
    expect(isAwaitingOnboardingReview({ status: 'active', onboardingReview: { status: 'correction_requested' }, onboarding: { documents } })).toBe(false)
    expect(isAwaitingOnboardingReview({ status: 'active', onboardingReview: { status: 'pending' }, onboarding: { personal: {}, documents: [] } })).toBe(false)
  })
})

/**
 * The API sends documents as a list; every screen read them as an object, so
 * `documents.cv` was undefined and CV/contract read "missing" for everyone.
 */
describe('employee documents', () => {
  const { documentsByKey } = useStore()

  it('keys the API list by the names the screens read', () => {
    const employee = { onboarding: { documents: [
      { key: 'cv', type: 'cv', name: 'cv.pdf', hasFile: true, status: 'submitted' },
      { key: 'offerLetter', type: 'offer_letter', name: 'offer.pdf', hasFile: true, status: 'verified' },
      { key: 'contract', type: 'contract', name: null, hasFile: false, status: 'pending' }
    ] } }

    const documents = documentsByKey(employee)

    expect(documents.cv.name).toBe('cv.pdf')
    expect(documents.offerLetter.status).toBe('verified')
    // A row with no file is not an upload.
    expect(documents.contract).toBeUndefined()
  })
})

describe('leave timeline', () => {
  it('is built from the server approvals, newest first', () => {
    const { state, setLeaveRequests } = useStore()

    setLeaveRequests([{
      id: 1, employeeId: 2, days: 1, status: 'approved',
      approvals: [
        { stage: 'lead', decision: 'approved', approverName: 'Lead', decidedAt: '2026-10-01T09:00:00+00:00', comment: null },
        { stage: 'hr', decision: 'approved', approverName: 'HR', decidedAt: '2026-10-02T09:00:00+00:00', comment: 'Enjoy' }
      ]
    }])

    expect(state.leaveRequests[0].timeline.map(entry => entry.status)).toEqual(['hr approved', 'team lead approved'])
    expect(state.leaveRequests[0].timeline[0].note).toBe('Enjoy')
  })
})

describe('medical claim review stages', () => {
  beforeEach(() => {
    useStore().setMedicalClaims([])
  })

  const pendingClaim = overrides => ({
    id: 1,
    employeeId: 2,
    type: 'Consultation',
    amount: '4000.00',
    approvedAmount: null,
    status: 'pending',
    reviewStage: null,
    ...overrides
  })

  it('shows the review stage as the status while undecided', () => {
    const { state, upsertMedicalClaim } = useStore()

    upsertMedicalClaim(pendingClaim({
      reviewStage: 'processing',
      viewedAt: '2026-10-05T09:00:00+00:00',
      viewedByName: 'HR',
      processingAt: '2026-10-05T10:00:00+00:00',
      processingByName: 'HR'
    }))

    const claim = state.medicalClaims[0]
    expect(claim.status).toBe('processing')
    expect(claim.serverStatus).toBe('pending')
    expect(claim.amount).toBe(4000)
    expect(claim.timeline.map(step => step.status)).toEqual(['processing', 'view'])
  })

  it('puts the decision at the top of the timeline once decided', () => {
    const { state, setMedicalClaims } = useStore()

    setMedicalClaims([pendingClaim({
      status: 'rejected',
      reviewStage: null,
      viewedAt: '2026-10-05T09:00:00+00:00',
      reviewedAt: '2026-10-05T11:00:00+00:00',
      reviewerName: 'HR',
      reviewComment: 'Not covered'
    })])

    const claim = state.medicalClaims[0]
    expect(claim.status).toBe('rejected')
    expect(claim.timeline[0]).toMatchObject({ status: 'rejected', note: 'Not covered', by: 'HR' })
  })
})
