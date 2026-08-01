#Dashboard— Frontend (HTML / CSS / JS)

Pure static frontend — no build step, no framework. Bootstrap 5 + vanilla JS +
jQuery/DataTables (tables) + Chart.js (charts) + SheetJS (Excel export), all via CDN.
Your PHP backend can serve these files directly (as-is, or rename `.html` → `.php`
later if you ever want server-side rendering — nothing here depends on that).

## Structure
```
adsdash/
├── index.html            Dashboard
├── customers.html         Customers — list + add/edit
├── customer-view.html     Customer profile + full quotation/invoice/payment history
├── quotations.html        Quotations — list + 4-step builder wizard + approve/reject
├── invoices.html          Invoices (auto-created from approved quotations)
├── campaigns.html         Campaigns
├── screens.html            Billboard / TV screen inventory
├── payments.html           Payments
├── reports.html            Reports + Excel export
├── users.html               User management + roles
├── settings.html            Company / quotation / tax / notification settings
├── login.html                Sign-in page
├── partials/
│   ├── sidebar.html          Shared nav (injected into every page by layout.js)
│   └── topbar.html           Shared topbar (search, notifications, user chip)
└── assets/
    ├── css/style.css         Design tokens + all component styles
    └── js/
        ├── layout.js         Injects sidebar/topbar partials, highlights active nav
        └── script.js         DataTables init, sidebar toggle helpers, CSV export helper
```

## How the frontend expects to talk to PHP
Every page has a `<!-- BACKEND NOTE -->` comment near the data it needs, plus a
`/* BACKEND HOOK */` comment showing the exact `fetch()` call to wire in. In short,
build these endpoints (JSON in, JSON out) and call them from the marked spots:

| Endpoint | Used by |
|---|---|
| `api/login.php` | login.html |
| `api/dashboard.php` | index.html |
| `api/customers.php` (GET list / GET ?id= / POST / PUT) | customers.html, customer-view.html |
| `api/quotations.php` (GET / POST / POST ?id=&action=approve|reject) | quotations.html |
| `api/invoices.php` | invoices.html |
| `api/campaigns.php` | campaigns.html |
| `api/screens.php` | screens.html, quotation wizard step 2 |
| `api/payments.php` | payments.html |
| `api/reports.php?type=..&from=..&to=..` | reports.html |
| `api/users.php` | users.html |
| `api/settings.php` | settings.html |

### API Connector (`assets/js/api.js`)
All API calls are wrapped inside `assets/js/api.js` (available globally as `window.AdsDashAPI`). Backend developers can update `API_CONFIG.API_BASE_URL` in `api.js` to point to their REST API.

## Frontend & UI Features
- **Smooth Page Transitions**: Page navigation includes smooth CSS fade/slide effects without jarring screen reloads.
- **Clean Sidebar**: The sidebar scrollbar is hidden (`scrollbar-width: none`), keeping a clean minimalist look while retaining full scrolling capability.
- **Responsive Mobile Toggle**: Clicking the sidebar toggle smooth-slides the menu with a backdrop blur overlay on mobile viewports.

## Business workflow this maps to
Lead/Enquiry → **Customers** (register) → **Quotations** (select service →
choose locations/screens from **Screens** inventory → pick pricing package →
generate PDF) → customer approves → quotation auto-converts to an **Invoice**
(the quotation stays out of the Invoices list until approved) → rejected
quotations are edited in place and regenerated, never duplicated → **Payments**
recorded against invoices → everything rolls up into **Campaigns**, **Reports**,
and each customer's history tab.

## Notes for the backend dev
- Session/auth check: redirect to `login.html` if not authenticated (can be done
  via PHP if you rename pages to `.php`, or via a `fetch('api/me.php')` check in JS).
- `partials/sidebar.html` and `partials/topbar.html` are fetched client-side —
  keep them reachable at that relative path.
- All amounts are shown pre-formatted (₹) in the mock markup; format server-side
  or client-side once real data is wired in.
- DataTables is initialized on any `<table class="datatable">` automatically —
  just add that class to any new table and it gets search/pagination for free.
