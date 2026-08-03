# Orbit HRMS — Frontend (Vue 3 + Vite + Tailwind)

Frontend-only build with mock data. Har page abhi `src/data/mock.js` aur
`src/store/index.js` se data leta hai — koi backend call nahi.

## Run karne ka tareeqa

```bash
npm install
npm run dev
```

Phir browser mein `http://localhost:5173` kholein. Direct in pages pe
jaane ke liye:
- `/login`
- `/accept-invite`
- `/dashboard`
- `/employees`
- `/attendance`
- `/projects`
- `/projects/1` (ticket board with timers)

## Pages included

1. **Login** — office email + password
2. **Accept Invite** — invitation link se aane wala set-password page
3. **Dashboard** — super admin overview: stats, punch ledger, hours-by-project
4. **Employees** — list + "Add employee" (invite) modal
5. **Attendance** — checkin/checkout demo + team punch ledger
6. **Projects** — project cards with today's logged hours
7. **Project Detail** — kanban board with a **start/stop timer per ticket**

## Ticket timer logic (`src/store/index.js`)

Har ticket ka time uske `timeLogs` array se calculate hota hai:

```js
timeLogs: [
  { start: '2026-07-14T04:15:00', end: '2026-07-14T06:05:00' },
  { start: '2026-07-14T08:10:00', end: null } // running
]
```

- `startTicketTimer(ticketId)` — naya open log push karta hai, purana
  running timer (agar koi ho) automatically stop kar deta hai
- `stopTicketTimer(ticketId)` — open log ka `end` set karta hai
- `ticketSeconds(ticket)` — ticket ka total time (sab logs ka sum)
- `ticketSecondsToday(ticket)` — sirf aaj ke logs ka time
- `projectSecondsToday(projectId)` — us project ke sab tickets ka
  aaj ka total time (managers/owners ke liye)
- `employeeSecondsToday(employeeId)` — kisi employee ne aaj kitna kaam
  kiya, sab assigned tickets milaake

Ye same shape backend se bhi expect ki ja sakti hai — Laravel mein
ek `ticket_time_logs` table (`ticket_id, started_at, ended_at`) bana kar
seedha map ho jayega.

## Laravel backend se connect karne ke liye (agla step)

1. `src/store/index.js` mein mock arrays ki jagah `axios` calls lagayein
   (`GET /api/tickets`, `POST /api/tickets/{id}/timer/start`, etc.)
2. `startTicketTimer` / `stopTicketTimer` ko async bana kar API hit karayein,
   phir local state update karein (optimistic UI) ya response se refresh karein
3. Login/Accept-Invite forms ko `POST /api/login` aur
   `POST /api/invitations/{token}/accept` se jodein
4. Employee invite (`Employees.vue`) ko `POST /api/employees` se jodein —
   backend Laravel Notification/Mailable se invitation email bhejega
   jisme signed URL (`accept-invite?token=...`) hogi

## Design tokens

Colors, fonts sab `tailwind.config.js` mein defined hain — agar palette
change karni ho to sirf wahi file edit karein, poore app mein reflect
ho jayega.
