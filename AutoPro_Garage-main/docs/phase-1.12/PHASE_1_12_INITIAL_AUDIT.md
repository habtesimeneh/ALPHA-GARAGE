# ALPHA GARAGE — PHASE 1.12 INITIAL AUDIT REPORT
## Comprehensive Pre-Implementation Inspection of Application Architecture, Pages, and Interaction Surface

**Audit Date:** 2026-11-18  
**Phase:** 1.12 — Complete Website-Wide Scroll Animation & Premium Interaction Experience  
**Status:** COMPLETE (Stage A Pre-Implementation Audit)  
**Target Environment:** Vanilla PHP 8.3+, Vanilla CSS3, Vanilla ES6+ JavaScript, MySQL 8.0, Apache/MAMP  

---

### 1. EXECUTIVE SUMMARY & AUDIT SCOPE

This comprehensive initial audit was conducted prior to implementing the Phase 1.12 Global Scroll Animation & Premium Interaction Experience across ALPHA GARAGE. The objective is to map 100% of the existing user-facing surface, identify all layout structures, analyze CSS design tokens, assess JavaScript lifecycles, and architect a zero-dependency, high-performance scroll animation system that operates flawlessly without altering business logic, database structures, financial reporting, or RBAC security guarantees.

Every file, route, layout, and component referenced in this report was verified through direct inspection of the repository.

---

### 2. COMPREHENSIVE PAGE & ROUTE INVENTORY

#### 2.1 Public-Facing Marketing & Portal Pages
The application contains 14 distinct public-facing or client portal entry points:

| Route | File Path | Template / Layout Architecture | Primary Stylesheets | Associated JavaScript |
| :--- | :--- | :--- | :--- | :--- |
| `/index.php` | `public/index.php` | Standalone luxury landing page (`.landing-page`) with sticky `.luxury-navbar`, marquee announcement, and `.luxury-footer` | `variables.css`, `landing.css` | `luxury.js` (slider, typewriter, mobile quick actions, FAQ accordion) |
| `/home.php` | `public/home.php` | Alternative classic marketing home (`.home-body`) with `.home-nav`, hero slider, and `.home-footer` | `variables.css`, `layout.css`, `components.css`, `homepage.css`, `responsive.css`, `theme.css` | `homepage.js` |
| `/about.php` | `public/about.php` | About & FAQ presentation (`.landing-page .about-page`) with modern hero, approach grid, specialties explorer, values cards, and FAQ accordion | `variables.css`, `landing.css`, `about.css` | `luxury.js`, `about.js` (tabbed specialty explorer) |
| `/services.php` | `public/services.php` | Comprehensive services catalog (`.services-page`) with category cards, inspection guidance, and consultation booking | `variables.css`, `landing.css`, `services.css` | `luxury.js`, `services.js` (category filter & search) |
| `/contact.php` | `public/contact.php` | Public support, contact form, Telegram hotline, and operating hours | `variables.css`, `landing.css`, `contact.css` | `luxury.js` (CSRF & rate-limit feedback) |
| `/book.php` | `public/book.php` | Client-authenticated appointment booking portal (`.luxury-portal-body`) with existing booking lists and vehicle selector | `css_links()` (11 CSS bundle) | `app.js` |
| `/customer.php` | `public/customer.php` (delegates to `app/views/dashboards/customer.php`) | VIP Customer Dashboard (`.luxury-portal-body`) with live repair tracking, vehicle cards, service history charts, and billing summaries | `css_links()` (11 CSS bundle) | `chart.umd.min.js`, `dashboard.js`, `app.js` |
| `/login.php` | `public/login.php` | Luxury authentication gateway (`.luxury-auth-body`) with fullscreen automotive slider and centered glass card | `variables.css`, `luxury-auth.css` | `luxury.js` (password visibility toggle, slider) |
| `/register.php` | `public/register.php` | Luxury client registration portal (`.luxury-auth-body`) with real-time password strength meter and terms verification | `variables.css`, `luxury-auth.css` | `luxury.js` (strength meter, toggles, slider) |
| `/forgot-password.php`| `public/forgot-password.php`| Self-service password recovery request (`.luxury-auth-body`) | `variables.css`, `luxury-auth.css` | `luxury.js` |
| `/reset-password.php` | `public/reset-password.php` | Cryptographic password reset confirmation (`.luxury-auth-body`) | `variables.css`, `luxury-auth.css` | `luxury.js` |
| `/privacy.php` | `public/privacy.php` | Legal privacy policy document (`.landing-page`) with luxury navigation and structured policy sections | `variables.css`, `landing.css` | `luxury.js` |
| `/terms.php` | `public/terms.php` | Legal terms of service document (`.landing-page`) with warranty and repair authorization clauses | `variables.css`, `landing.css` | `luxury.js` |
| `/verify-customer.php`| `public/verify-customer.php`| Secure customer email ownership verification and historical service record linking | `ui_header()` / `ui_footer()` (`css_links()`) | `app.js` |

#### 2.2 Role-Specific Dashboards (6 Distinct Roles)
Dispatched centrally through `public/dashboard.php` based on authenticated database role:

1. **Administrator Dashboard (`app/views/dashboards/admin.php`):**
   - Command center: Period range filter with AJAX partial refresh, 8 financial & operational KPI cards, monthly targets progress bars, 7 Chart.js canvases (Revenue vs Expenses, Profit & Loss, Revenue Performance, Expense Distribution, Job Card Analysis, Inventory Analytics, Service Performance), stock alerts, top issued parts.
2. **Receptionist Dashboard (`app/views/dashboards/receptionist.php`):**
   - Front desk intake: 5 operational KPI cards, 2 Chart.js canvases (Appointment Status Breakdown, 14-Day Booking Trend), today's scheduled appointments table, active workshop job cards, recent client registrations.
3. **Mechanic Dashboard (`app/views/dashboards/mechanic.php`):**
   - Workstation queue (strictly scoped to `mechanic_id`): Assigned active jobs, urgent jobs, completed handoffs, 3 Chart.js canvases (Assigned Job Status, Active Task Urgency, 6-Month Repair Throughput), active repair queue table with status update shortcuts.
4. **Inventory Manager Dashboard (`app/views/dashboards/inventory.php`):**
   - Parts warehouse: Stock KPI cards (total items, low stock, out of stock, stock valuation, active suppliers), 3 Chart.js canvases (Stock Health Distribution, Physical Units by Category, Movement Activity by Type), critical restock alerts table, recent stock movement ledger.
5. **Accountant Dashboard (`app/views/dashboards/accountant.php`):**
   - Financial ledger: Cash & receivables KPIs (Net Collected Revenue, Outstanding Receivables, Total Invoiced, Operating Expenses), 4 Chart.js canvases (Invoice Settlement Status, Receivables Aging Brackets, 6-Month Net Collections, Payment Method Breakdown), overdue invoice alerts table, recent payment transactions table.
6. **Customer Portal Dashboard (`app/views/dashboards/customer.php`):**
   - VIP Client Services: Welcome banner, active repair status card with vehicle details, linked customer vehicles grid, 2 Chart.js canvases (Repair Status Distribution, 6-Month Service History), upcoming appointments table, recent invoice and billing ledger.

#### 2.3 Dashboard Subpages & Management Modules (15 Subdirectories)
All subpages utilize `ui_header()` and `ui_footer()` in `app/views/layout.php`:

- **Appointments:** `public/appointments/index.php`, `form.php`, `action.php`
- **Audit Logs:** `public/audit-logs/index.php`
- **Customers:** `public/customers/index.php`, `form.php`, `view.php`, `toggle.php`
- **Expenses:** `public/expenses/index.php`
- **Inventory:** `public/inventory/index.php`, `form.php`, `history.php`, `stock.php`, `issue.php`, `toggle.php`
- **Invoices:** `public/invoices/index.php`, `create.php`, `view.php`, `action.php`
- **Job Cards:** `public/job-cards/index.php`, `form.php`, `view.php`, `action.php`, `data.php`
- **Mechanics:** `public/mechanics/index.php`, `form.php`
- **Notifications:** `public/notifications/index.php`, `mark_read.php`
- **Payments:** `public/payments/index.php`, `record.php`, `receipt.php`, `refund.php`
- **Profile:** `public/profile.php`
- **Reports:** `public/reports/index.php`
- **Services:** `public/services/index.php`, `form.php`, `toggle.php`
- **Suppliers:** `public/suppliers/index.php`, `form.php`, `view.php`, `toggle.php`
- **Users & Settings:** `public/users/index.php`, `form.php`, `roles.php`, `settings.php`
- **Vehicles:** `public/vehicles/index.php`, `form.php`, `view.php`, `toggle.php`

---

### 3. SHARED LAYOUTS, CHROME & REUSABLE UI COMPONENTS

1. **Internal Application Chrome (`app/views/layout.php`):**
   - `ui_header()`: Renders `<head>` with `<?= css_links() ?>`, initializes `#toast-root` with server flash messages, creates `#loader` spinner, renders `.shell`, collapsible `.sidebar` with grouped navigation, `.topbar` with live filter search, theme toggle, system clock, low-stock counter, notification dropdown (`#notif-menu`), and account dropdown (`#user-menu`).
   - `ui_footer()`: Closes `.shell`, renders accessible `#modal` confirmation dialog, loads `app.js`, `validation.js`, and on `dashboard.php` loads `vendor/chart.umd.min.js` and `dashboard.js`.
2. **Public Website Chrome:**
   - Sticky `.luxury-navbar`: Contains logo, brand name, responsive navigation menu links, authentication CTA buttons, and announcement banner ticker.
   - Mobile Quick Actions (`.mobile-quick-actions`): Floating bottom pill providing one-tap call and booking triggers on handheld devices.
   - `.luxury-footer` / `.landing-footer`: Multi-column accordion footer with Telegram support link, quick navigation links, client access, and copyright note.
3. **Common Reusable Components:**
   - Metric / KPI Cards (`.kpi-card`, `.stat`, `.inv-stat`): Structured metric containers with trend indicators and sparklines.
   - Data Tables (`.table`, `.table.stack`): Responsive tabular displays that transform into stacked cards below 640px.
   - Filter Bars (`.filters`, `.dashboard-filter-form`): Inline query parameter forms with auto-submitting selects.
   - Progress Bars (`.dash-progress`, `.inv-progress`): Visual quota and threshold meters.
   - Action Badges (`.badge`, `.badge-*`): Semantic status tags for job, appointment, and payment states.

---

### 4. DESIGN TOKENS & CSS ARCHITECTURE

All styles adhere strictly to the ALPHA GARAGE design tokens defined in `public/assets/css/variables.css`:
- **Deep Forest Green (Primary Brand):**
  - `--forest-darkest`: `#071711`
  - `--forest-dark`: `#0D281E`
  - `--forest-deep`: `#153A2D`
  - `--forest-surface`: `#1E4E3D`
- **Champagne Gold (Accent Brand):**
  - `--gold-luxury` / `--gold-primary` / `--gold`: `#D4AF37`
  - `--gold-champagne`: `#E8CD83`
  - `--gold-dark` (Accessible WCAG AA contrast): `#7A5C00`
  - `--gold-light`: `#FBF4DC`
  - `--gold-glow`: `rgba(212, 175, 55, 0.35)`
- **Surfaces & Backgrounds:**
  - Light mode: `--background: #F4F6F4; --surface: #FFFFFF; --surface-alt: #F8FAFC;`
  - Dark mode (`[data-theme="dark"]`): Defined in `theme.css` with obsidian/forest surfaces (`#071711`, `#0D281E`, `#153A2D`).
- **Motion Tokens:**
  - `--ease`: `cubic-bezier(.2, .8, .2, 1)`
  - `--t-fast`: `.15s var(--ease)`
  - `--t`: `.25s var(--ease)`
  - `--t-slow`: `.5s var(--ease)`
- **Responsive Breakpoints (`responsive.css`):**
  - `1366px`: Content padding compression
  - `1100px`: 3-column grids fold to 2 columns
  - `1024px`: Topbar search compaction
  - `900px`: Sidebar switches to off-canvas mobile drawer with backdrop
  - `768px`: Service grids fold to 1 column; footer accordions activate
  - `640px`: Data tables collapse into mobile card stacks (`.table.stack td::before`)
  - `480px`: Full-width CTA buttons, compact stat tiles
  - `375px`: Ultra-compact micro-device adjustments

---

### 5. JAVASCRIPT ARCHITECTURE & LIFECYCLES

1. **Global Dashboard Script (`public/assets/js/app.js`):**
   - Self-executing IIFE (`(function () { 'use strict'; ... })()`).
   - Manages `#toast-root` notifications.
   - Manages mobile sidebar drawer (`#sidebar`, `#sidebar-backdrop`, `#menu-btn`).
   - Manages theme toggle (`.theme-toggle`, `localStorage` key `'gms.theme'`, and sync with `prefers-color-scheme`).
   - Desktop sidebar collapse state (`'gms.sidebar.collapsed'`).
   - Client-side table row filter (`#topbar-search-input`).
2. **Public Luxury Interaction Engine (`public/assets/js/luxury.js`):**
   - Self-executing IIFE.
   - Manages automotive background crossfade slider (`.luxury-slider`).
   - Manages dynamic typewriter text animation with `prefers-reduced-motion` bypass.
   - Real-time password strength meter and visibility toggles.
   - Sticky navbar scroll detection (`window.scrollY > 40`).
   - Mobile quick-actions drawer.
   - Accordion FAQ toggle with single-open enforcement.
3. **Chart.js Dashboard Engine (`public/assets/js/dashboard.js`):**
   - Manages 21 role-specific charts across all 6 dashboards.
   - Manages `chartRegistry` to prevent memory leaks and duplicate canvas instances.
   - Uses `MutationObserver` on `.dashboard-data` to safely re-initialize charts when AJAX filters refresh without full page reloads.
4. **Specialized Interaction Modules:**
   - `about.js`: Accessible WAI-ARIA specialty tab explorer with keyboard arrow navigation.
   - `services.js`: Real-time category filtering and text search.
   - `homepage.js`: Hero carousel with play/pause controls.
   - `validation.js`: Form input constraint checking.

---

### 6. ANIMATION AUDIT & TECHNICAL OPPORTUNITIES

#### Current State
- The application currently relies primarily on immediate DOM visibility upon load with standard CSS `:hover` state transitions (0.15s–0.25s).
- Background sliders crossfade on fixed intervals, and the typewriter module types characters sequentially.
- No unified scroll-driven animation architecture exists. When navigating down long pages (e.g. Homepage, About, Services, Admin Dashboard, Customer Portal), elements appear instantaneously as the viewport arrives at them without entrance motion.

#### Opportunities Identified
1. **Public Homepage (`index.php`, `home.php`):**
   - Staggered hero typography entrance (eyebrow, title, description, dual action buttons).
   - Staggered 3D card elevation reveals for the 6 core automotive service offerings.
   - Progressive reveal of the "Why Choose Us" four-pillar grid.
   - Alternating side slide-in for the "How It Works" 4-step workflow.
   - Smooth count-up or reveal of workshop statistics.
   - Fade-up reveal for customer testimonials and photo gallery.
   - Bottom CTA banner slide-up.
2. **About Page (`about.php`):**
   - Hero entrance with subtle zoom effect on automotive hero photography.
   - Alternating left/right reveals for the Workshop Story image and narrative columns.
   - Staggered flip/scale entrance for the 4 core value pillars.
   - Interactive specialty explorer tab panel entrance animation.
   - Accordion items smooth entrance.
3. **Services Catalog (`services.php`):**
   - Filter bar smooth reveal.
   - Service category card grid staggered cascading reveal (`stagger-1` through `stagger-6`).
   - "Before your visit" 3-step numbered cards animated sequentially.
   - Final booking prompt card entrance.
4. **Contact & Legal Pages (`contact.php`, `privacy.php`, `terms.php`):**
   - Contact split layout: Left side info card slides in from left; Right side form card slides in from right.
   - Legal document sections reveal smoothly on scroll with active table-of-contents highlight.
5. **Authentication Pages (`login.php`, `register.php`, `forgot-password.php`, `reset-password.php`):**
   - Centered luxury card smooth scale-and-fade entrance (`reveal-scale`).
   - Staggered form field entrance.
6. **All Six Role Dashboards & Subpages:**
   - Topbar and hero greeting fade-down entrance.
   - KPI metric cards staggered entrance (`reveal-up .stagger-1`, `.stagger-2`, etc.).
   - Target progress bars smooth fill expansion when scrolled into view.
   - Chart containers reveal smoothly, giving Chart.js charts an elegant entrance canvas.
   - Data tables and alert lists reveal row-by-row or as unified cards.
   - Subpages (Job Cards, Invoices, Inventory, Customers, etc.) receive consistent card entrance and table reveals.

---

### 7. TECHNICAL RISKS & MITIGATION STRATEGY

| Identified Risk | Severity | Root Cause | Architectural Mitigation |
| :--- | :--- | :--- | :--- |
| **Failsafe / JavaScript Disabled** | CRITICAL | If CSS hides elements via `opacity: 0` before JS executes, content vanishes if JS is blocked or fails. | Never hide elements in base CSS. Apply hiding styles ONLY under a `.js-ready` class added dynamically by `scroll-animations.js` upon initialization. If JS fails, all content remains 100% visible immediately. |
| **Accessibility & Vestibular Disorders** | CRITICAL | Aggressive motion causes disorientation or nausea for users with vestibular disorders. | Implement strict `@media (prefers-reduced-motion: reduce)` media queries. Force `opacity: 1 !important; transform: none !important; transition: none !important; animation: none !important;` across all animation classes. |
| **Layout Shift (CLS) on Scroll** | HIGH | Translating large elements vertically/horizontally during scroll can alter document flow. | Constrain transforms to modest offsets (e.g. `translateY(24px)`, `translateX(20px)`). Use GPU-accelerated `transform` and `opacity` exclusively; never animate `top`, `left`, `margin`, or `height`. |
| **Horizontal Overflow on Mobile** | HIGH | Left/right directional slide-ins (`reveal-left`, `reveal-right`) can trigger horizontal scrollbars on mobile viewports. | Apply `overflow-x: hidden` to root containers and restrict mobile slide-in translations to `<= 16px` on screens below 768px. |
| **Chart.js Collision & Repeated Initialization** | HIGH | Re-animating chart parent containers could cause canvas resizing or duplicate Chart.js re-renders. | Animate chart parent card containers with CSS transforms while leaving `<canvas>` rendering lifecycles strictly to `dashboard.js`. |
| **AJAX Partial Refresh Desynchronization** | HIGH | Admin dashboard filter replaces `#dashboard-data` via AJAX. Newly inserted nodes won't have observer hooks. | Utilize a centralized `MutationObserver` inside `scroll-animations.js` that automatically scans and observes newly injected `.reveal` elements. |
| **Scroll Event Performance Degradation** | HIGH | Using `window.addEventListener('scroll')` causes main-thread jank and frame drops. | Exclusively use the native `IntersectionObserver` API with passive thresholds (`threshold: 0.12`, `rootMargin: '0px 0px -40px 0px'`). |
| **Scroll Fluttering / Repeat Restart** | MEDIUM | Elements oscillating on the edge of the viewport repeatedly restart their animations. | Add an active intersection state class (`.is-revealed`) that locks the element into its visible state until it clearly leaves the viewport buffer. |

---

### 8. GLOBAL IMPLEMENTATION BLUEPRINT

#### A. Centralized CSS Stylesheet: `public/assets/css/scroll-animations.css`
- **Class Inventory:**
  - Base: `.reveal`, `.reveal-up`, `.reveal-down`, `.reveal-left`, `.reveal-right`, `.reveal-scale`, `.reveal-fade`, `.reveal-zoom`, `.reveal-blur`, `.reveal-rotate`
  - Stagger utility classes: `.stagger-1` (+100ms) through `.stagger-6` (+600ms)
  - Card & component micro-interactions: `.card-interactive`, `.btn-interactive`, `.table-row-reveal`
  - Floating back-to-top component: `.back-to-top`, `.back-to-top.is-visible`
  - Full `@media (prefers-reduced-motion: reduce)` override block

#### B. Centralized JavaScript Engine: `public/assets/js/scroll-animations.js`
- Feature detection for `IntersectionObserver`.
- Progressive enhancement: sets `document.documentElement.classList.add('js-ready')` upon successful boot.
- Bidirectional scroll support (subtle entrance on scroll-down, graceful exit/re-entrance on scroll-up when appropriate).
- `MutationObserver` instance for dynamic AJAX content (Admin dashboard date filters).
- Smooth anchor scrolling with reduced-motion awareness.
- Floating back-to-top button logic (`scrollY > 350px`).
- Public API `window.GMSAnimation = { observe, refresh }`.

#### C. System-Wide Integration
- Register `scroll-animations` in `css_links()` inside `app/helpers/functions.php`.
- Link `scroll-animations.css` in public page `<head>` blocks (`index.php`, `about.php`, `services.php`, `contact.php`, `login.php`, `register.php`, etc.).
- Load `scroll-animations.js` before `</body>` across `ui_footer()` and all standalone public page footers.
- Apply semantic reveal classes to every page section, hero, KPI grid, chart card, table, and form.

---

### 9. VERIFICATION & SUCCESS CRITERIA

1. **Zero Regression:** All existing PHP test suites (including Phase 1.11E Chart Payload tests and Phase 1.10E Customer Security tests) must pass with 100% success.
2. **Zero Content Disappearance:** When JavaScript is disabled, 100% of text, charts, tables, and buttons must remain fully visible and usable.
3. **Reduced Motion Compliance:** With `prefers-reduced-motion: reduce` enabled, all elements must display immediately with zero transition delay or transform movement.
4. **Mobile Responsiveness:** Zero horizontal scrollbars or layout shifts across 360px, 390px, 768px, 1024px, 1366px, and 1920px viewports.
5. **Headless Chrome Visual Proof:** Capture comprehensive screenshots across public pages and all six role dashboards confirming visible, polished render states.
