function dateKey(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const mockToday = dateKey()

export const employees = [
  { id: 1, name: 'Ayesha Khan', email: 'ayesha.khan@company.com', role: 'employee', department: 'Engineering', status: 'active', birthday: '1998-07-22', joiningDate: '2024-08-05' },
  { id: 2, name: 'Bilal Raza', email: 'bilal.raza@company.com', role: 'employee', department: 'Design', status: 'active', birthday: '1997-07-19', joiningDate: '2023-07-24' },
  { id: 3, name: 'Zara Nadeem', email: 'zara.nadeem@company.com', role: 'manager', department: 'Engineering', status: 'active', birthday: '1995-08-03', joiningDate: '2022-07-18' },
  { id: 4, name: 'Usman Tariq', email: 'usman.tariq@company.com', role: 'employee', department: 'QA', status: 'active', birthday: '1999-07-28', joiningDate: '2025-01-13' },
  { id: 5, name: 'Hina Malik', email: 'hina.malik@company.com', role: 'employee', department: 'Engineering', status: 'invited', birthday: '2000-08-09', joiningDate: '' },
  { id: 6, name: 'Sara Ahmed', email: 'sara.ahmed@company.com', role: 'hr', department: 'Human Resources', status: 'active', birthday: '1996-07-31', joiningDate: '2023-09-01' },
  // Demonstrates a custom role driven entirely by the permission table.
  { id: 7, name: 'Imran Shah', email: 'imran.shah@company.com', role: 'team_lead', department: 'Team Lead', status: 'active', birthday: '1994-05-12', joiningDate: '2022-03-14' }
]

export const attendance = [
  { id: 1, employeeId: 1, date: mockToday, checkIn: '09:02', checkOut: '17:10', device: 'laptop', status: 'present' },
  { id: 2, employeeId: 2, date: mockToday, checkIn: '09:40', checkOut: '15:45', device: 'mobile', status: 'present' },
  { id: 3, employeeId: 3, date: mockToday, checkIn: '08:55', checkOut: null, device: 'laptop', status: 'present' },
  { id: 4, employeeId: 4, date: mockToday, checkIn: '10:20', checkOut: '13:30', device: 'laptop', status: 'late' },
  { id: 5, employeeId: 6, date: mockToday, checkIn: '09:10', checkOut: null, device: 'laptop', status: 'present' },
  { id: 6, employeeId: 7, date: mockToday, checkIn: '09:05', checkOut: null, device: 'laptop', status: 'present' }
]

export const projects = [
  {
    id: 1,
    name: 'Internal',
    slug: 'internal',
    // description: 'Parent section for company-owned internal products and operational tools',
    managerId: 3,
    teamMembers: [1, 2, 4, 7],
    modules: [
      {
        id: 11,
        name: 'Orbit HRMS',
        slug: 'orbit-hrms',
        // description: 'Internal HR management system with onboarding, attendance, payroll, and employee operations.',
        status: 'active',
        deliveredAt: '',
        teamMembers: [1, 2],
        budget: 1800000,
        commission: { type: 'percent', value: 8, status: 'approved' },
        payments: [
          { id: 1, amount: 650000, date: '2026-07-04', method: 'Bank transfer', reference: 'BT-ORB-650', payer: 'Orbit client account', loggedById: 3 },
          { id: 2, amount: 350000, date: '2026-07-15', method: 'Cheque', reference: 'CHQ-1029', payer: 'Orbit client account', loggedById: 3 }
        ]
      },
      {
        id: 12,
        name: 'Attendance System',
        slug: 'attendance-system',
        description: 'Check-in, WebAuthn, camera capture, and punch ledger work.',
        status: 'active',
        deliveredAt: '',
        // leadId: the Team Lead running this squad. Optional — modules without
        // one are managed directly by the project manager.
        leadId: 7,
        teamMembers: [1, 7],
        budget: 0,
        commission: { type: 'percent', value: 0, status: 'pending' },
        payments: []
      },
      {
        id: 13,
        name: 'Project Management Workspace',
        slug: 'project-management-workspace',
        description: 'Project boards, ticketing, timers, and delivery workflow.',
        status: 'active',
        deliveredAt: '',
        leadId: 7,
        teamMembers: [4, 7],
        budget: 0,
        commission: { type: 'percent', value: 0, status: 'pending' },
        payments: []
      }
    ]
  },
  {
    id: 2,
    name: 'Freelance',
    slug: 'freelance',
    // description: 'Parent section for client projects received through freelance work',
    managerId: 3,
    teamMembers: [2, 4],
    modules: [
      {
        id: 21,
        name: 'Client Portal Revamp',
        slug: 'client-portal-revamp',
        description: 'Redesign of the client-facing dashboard.',
        status: 'active',
        deliveredAt: '',
        teamMembers: [2, 4],
        budget: 950000,
        commission: { type: 'fixed', value: 70000, status: 'pending' },
        payments: [
          { id: 1, amount: 300000, date: '2026-07-10', method: 'JazzCash', reference: 'JC-PORTAL-300', payer: 'Client Portal finance', loggedById: 3 }
        ]
      },
      {
        id: 22,
        name: 'Website for Client X',
        slug: 'website-for-client-x',
        // description: 'Marketing website build for a freelance client.',
        status: 'active',
        deliveredAt: '',
        teamMembers: [2],
        budget: 450000,
        commission: { type: 'percent', value: 10, status: 'pending' },
        payments: []
      }
    ]
  }
]

export const tickets = [
  {
    id: 101, projectId: 1, moduleId: 11, title: 'Invitation email template', status: 'in_progress',
    priority: 'medium', assigneeId: 2, deadline: '2026-07-18',
    comments: [
      { id: 1, authorId: 3, text: 'Please keep the copy shorter and align it with the brand tone.', createdAt: '2026-07-14T09:10:00' },
      { id: 2, authorId: 2, text: 'Updated the first draft and shared it for review.', createdAt: '2026-07-14T10:25:00' }
    ],
    timeLogs: [
      { start: '2026-07-14T04:15:00', end: '2026-07-14T06:05:00' }
    ]
  },
  {
    id: 102, projectId: 1, moduleId: 12, title: 'WebAuthn checkin flow', status: 'review',
    priority: 'high', assigneeId: 1, deadline: '2026-07-16',
    comments: [
      { id: 1, authorId: 1, text: 'Current flow works on desktop, but I still need to confirm mobile fallback handling.', createdAt: '2026-07-14T11:05:00' }
    ],
    timeLogs: [
      { start: '2026-07-14T03:30:00', end: '2026-07-14T07:45:00' },
      { start: '2026-07-14T08:10:00', end: '2026-07-14T09:00:00' }
    ]
  },
  {
    id: 103, projectId: 1, moduleId: 13, title: 'Kanban drag and drop', status: 'todo',
    priority: 'medium', assigneeId: 4, deadline: '2026-07-25',
    comments: [],
    timeLogs: []
  },
  {
    id: 104, projectId: 2, moduleId: 21, title: 'New sidebar navigation', status: 'in_progress',
    priority: 'low', assigneeId: 2, deadline: '2026-07-20',
    comments: [
      { id: 1, authorId: 3, text: 'Make sure the navigation stays stable on smaller screens.', createdAt: '2026-07-14T08:40:00' }
    ],
    timeLogs: [
      { start: '2026-07-14T05:00:00', end: '2026-07-14T06:20:00' }
    ]
  },
  // Team Lead's own hands-on work — leads remain individual contributors.
  {
    id: 105, projectId: 1, moduleId: 12, title: 'Refactor punch ledger queries', status: 'in_progress',
    priority: 'high', assigneeId: 7, deadline: mockToday,
    comments: [],
    timeLogs: [
      { start: `${mockToday}T04:00:00`, end: `${mockToday}T06:30:00` }
    ]
  },
  // Squad ticket waiting on the lead's review.
  {
    id: 106, projectId: 1, moduleId: 13, title: 'Ticket timer edge cases', status: 'review',
    priority: 'medium', assigneeId: 4, deadline: '2026-07-30',
    comments: [
      { id: 1, authorId: 4, text: 'Handled the overnight timer case, ready for lead review.', createdAt: `${mockToday}T05:20:00` }
    ],
    timeLogs: [
      { start: `${mockToday}T03:10:00`, end: `${mockToday}T05:15:00` }
    ]
  },
  // Overdue squad ticket so the blocked/overdue widget has real data.
  {
    id: 107, projectId: 1, moduleId: 12, title: 'Camera permission fallback', status: 'todo',
    priority: 'high', assigneeId: 1, deadline: '2026-07-20',
    comments: [],
    timeLogs: []
  }
]

export const statusMeta = {
  todo: { label: 'To do', bg: 'bg-slate-100', text: 'text-slate-700' },
  in_progress: { label: 'In progress', bg: 'bg-amber-bg', text: 'text-amber-dark' },
  review: { label: 'Review', bg: 'bg-teal-bg', text: 'text-teal-dark' },
  done: { label: 'Done', bg: 'bg-teal-bg', text: 'text-teal-dark' },
  blocked: { label: 'Blocked', bg: 'bg-coral-bg', text: 'text-coral-dark' }
}

export const medicalClaims = [
  { id: 1, employeeId: 1, type: 'Medical reimbursement', amount: 18500, status: 'approved', submittedAt: '2026-07-12' },
  { id: 2, employeeId: 4, type: 'Pharmacy claim', amount: 4200, status: 'approved', submittedAt: '2026-07-10' }
]

export const leaveRequests = [
  { id: 1, employeeId: 2, type: 'Annual leave', from: '2026-07-22', to: '2026-07-24', status: 'pending' },
  { id: 2, employeeId: 1, type: 'Sick leave', from: '2026-07-15', to: '2026-07-15', status: 'approved' }
]

export const salarySlipRequests = [
  { id: 1, employeeId: 1, month: 'June 2026', status: 'pending' },
  { id: 2, employeeId: 3, month: 'June 2026', status: 'approved' }
]
