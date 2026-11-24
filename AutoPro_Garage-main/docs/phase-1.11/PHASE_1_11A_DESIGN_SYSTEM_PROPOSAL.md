# ALPHA GARAGE — PHASE 1.11A
# UNIFIED DESIGN SYSTEM & UI/UX SPECIFICATION PROPOSAL

**Document Reference:** `docs/phase-1.11/PHASE_1_11A_DESIGN_SYSTEM_PROPOSAL.md`  
**Phase:** Phase 1.11A — UI/UX Audit & Design Specification  
**Design Direction:** **Premium Automotive Management Interface**  
**Core Motif:** Deep Dark Forest Green & Warm Gold Accents with Precision Ergonomics  
**Working Directory:** `c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main`  
**Status:** Design Proposal (READ-ONLY — For Future Implementation)  

---

## 1. DESIGN PHILOSOPHY & BRAND IDENTITY

The ALPHA GARAGE interface must convey the prestige, precision, and engineering discipline of a high-performance automotive marque (analogous to Aston Martin, Bentley, or Porsche Motorsport). 

### Guiding Principles:
1. **Automotive Heritage & Executive Elegance:**
   Replace the generic corporate SaaS electric blue (`#2563EB`) with a bespoke **Deep Dark Forest Green** (`#0D281E`) foundation, illuminated by **Warm Champagne Gold** (`#D4AF37`) accents.
2. **Restraint Over Decoration:**
   Gold is an accent, not a background fill. It is reserved for primary focal actions, key financial milestones, high-priority status indicators, and active navigational cues. It must never compromise data legibility.
3. **Data Density with Visual Breathing Room:**
   Automotive service advisors, technicians, and accountants manage complex tabular data. The layout must balance high-density information architecture with disciplined whitespace, clear visual hierarchy, and instant scannability.
4. **Universal Accessibility (WCAG 2.1 AA Compliant):**
   Every color pair, form input, focus indicator, and status badge must satisfy rigorous contrast and accessibility standards. Color must never be the sole conveyor of operational status.

---

## 2. COLOR PALETTE ARCHITECTURE

```
+---------------------------------------------------------------------------------------------------+
|                                  ALPHA GARAGE MASTER PALETTE                                      |
+---------------------+-----------------+------------+----------------------------------------------+
| Role / Token        | Color Name      | HEX Code   | Purpose / Application                        |
+---------------------+-----------------+------------+----------------------------------------------+
| --brand-darkest     | Black Forest    | #071711    | Darkest base, mobile drawer backdrop         |
| --brand-dark        | Forest Dark     | #0D281E    | App shell sidebar, topbar headers, hero      |
| --brand-surface     | Deep Emerald    | #153A2D    | Card borders, active navigation backgrounds  |
| --brand-accent      | British Racing  | #1E4E3D    | Hover states, secondary action buttons       |
| --gold-primary      | Warm Gold       | #D4AF37    | Primary brand buttons, KPI highlight values  |
| --gold-champagne    | Champagne Gold  | #E8CD83    | Chart highlights, dark-mode text accents     |
| --gold-subtle       | Gold Tint       | #FBF4DC    | Light theme gold badge backgrounds           |
| --gold-border       | Gold Glow       | #C29B27    | Active focus rings and table border accents  |
+---------------------+-----------------+------------+----------------------------------------------+
| --surface-light     | Crisp White     | #FFFFFF    | Main card containers, modals, table cells    |
| --surface-alt       | Alabaster Warm  | #F8FAFC    | Page background, alternating table rows      |
| --border-subtle     | Platinum Gray   | #E2E8F0    | Card dividers, standard input borders        |
| --border-strong     | Slate Silver    | #CBD5E1    | Elevated card borders, table header divides  |
| --text-main         | Midnight Slate  | #0F172A    | High-contrast body text, primary table data  |
| --text-muted        | Storm Gray      | #64748B    | Table subtitles, helper text, timestamps     |
+---------------------+-----------------+------------+----------------------------------------------+
```

### 2.1 Semantic Status Palette (Dual Visual Cues)
Every status color is paired with a specific geometric glyph to ensure accessibility for color-blind users:

| Status Intent | Background | Text / Border | Symbol Cue | Applicable System States |
| :--- | :---: | :---: | :---: | :--- |
| **Success / Operational** | `#DCFCE7` | `#15803D` | `✓` | Completed, Paid, Available, Active, In Stock |
| **Warning / Action Needed**| `#FEF3C7` | `#B45309` | `▲` | Low Stock, Urgent Priority, Pending Intake |
| **Critical / Bottleneck**  | `#FEE2E2` | `#B91C1C` | `✕` | Out of Stock, Unpaid, Cancelled, Overdue |
| **Informational / Active** | `#E0F2FE` | `#0369A1` | `●` | In Progress, Diagnosing, Confirmed Appointment |
| **Neutral / Archived**    | `#F1F5F9` | `#475569` | `–` | Draft, Closed, Waiting for Parts, Off Duty |

---

## 3. TYPOGRAPHY & TYPE SCALE

ALPHA GARAGE utilizes an optimized system-native font stack for instant zero-latency rendering across all devices, eliminating external CDN dependencies and GDPR/security risks.

```css
--font-sans: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
--font-display: "Segoe UI Semibold", system-ui, -apple-system, sans-serif;
--font-mono: ui-monospace, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
```

### Typographic Hierarchy

| Token | Size | Line Height | Weight | Letter Spacing | Usage Target |
| :--- | :---: | :---: | :---: | :---: | :--- |
| `--text-4xl` | `2.25rem` (36px) | `1.15` | `800` | `-0.025em` | Hero welcome greetings, high-level metrics |
| `--text-3xl` | `1.75rem` (28px) | `1.20` | `750` | `-0.020em` | Primary KPI card metric values |
| `--text-2xl` | `1.35rem` (22px) | `1.25` | `700` | `-0.015em` | Section headings, page titles |
| `--text-xl`  | `1.15rem` (18px) | `1.30` | `650` | `-0.010em` | Card titles, modal headers |
| `--text-base`| `0.9375rem` (15px)| `1.45` | `400` / `500`| `0` | Standard body text, table cell content |
| `--text-sm`  | `0.8125rem` (13px)| `1.40` | `500` / `600`| `+0.010em` | Form labels, table headers, button labels |
| `--text-xs`  | `0.70rem` (11px)  | `1.35` | `700` | `+0.060em` | Section eyebrows, status badges, timestamps |

---

## 4. SPACING, GRID & ELEVATION SYSTEM

### 4.1 Strict 8-Point Spacing Scale
```css
--space-1: 0.25rem;  /* 4px  - Micro padding, badge icon gaps */
--space-2: 0.50rem;  /* 8px  - Button gaps, form field margins */
--space-3: 0.75rem;  /* 12px - Table padding, dropdown item gaps */
--space-4: 1.00rem;  /* 16px - Standard component padding */
--space-5: 1.25rem;  /* 20px - Card interior padding */
--space-6: 1.50rem;  /* 24px - Section gaps, hero padding */
--space-8: 2.00rem;  /* 32px - Major layout column gutters */
--space-10: 2.50rem; /* 40px - Page container top/bottom margins */
```

### 4.2 Elevation, Borders & Shadows
```css
--radius-sm: 4px;    /* Badges, micro buttons */
--radius-md: 8px;    /* Form inputs, standard buttons, table containers */
--radius-lg: 12px;   /* Cards, modal dialogs, hero headers */
--radius-pill: 999px;/* Pill tags, user avatars */

--shadow-sm: 0 1px 3px rgba(7, 23, 17, 0.06), 0 1px 2px rgba(7, 23, 17, 0.04);
--shadow-md: 0 4px 6px -1px rgba(7, 23, 17, 0.08), 0 2px 4px -2px rgba(7, 23, 17, 0.04);
--shadow-lg: 0 10px 15px -3px rgba(7, 23, 17, 0.10), 0 4px 6px -4px rgba(7, 23, 17, 0.05);
--shadow-gold: 0 4px 14px rgba(212, 175, 55, 0.25);
```

---

## 5. COMPONENT DESIGN SPECIFICATIONS

### 5.1 Application Hero Banner (`.hero`)
- **Background:** Rich gradient blending `#071711` to `#0D281E` with a subtle 20% gold radial glow in the top-right corner.
- **Border:** Subtle 1px translucent border (`rgba(212, 175, 55, 0.2)`).
- **Typography:** Display title in pure white (`#FFFFFF`) with role subtitle in champagne gold (`#E8CD83`).
- **Actions:** Quick-create action buttons positioned on the right with gold primary styling.

### 5.2 KPI Metric Cards (`.kpi-card`)
- **Structure:** Crisp white background (`#FFFFFF`), 12px border radius, 1px platinum border (`#E2E8F0`).
- **Brand Accent:** 4px vertical left-accent border communicating status:
  - Gold (`#D4AF37`) for Revenue and Profit Margin.
  - Emerald (`#15803D`) for Completed Jobs and Positive Cashflow.
  - Amber (`#D97706`) for Active Repairs and Pending Appointments.
  - Carmine (`#DC2626`) for Out of Stock and Overdue Receivables.
- **Metric Value:** Tabular figures in bold Midnight Slate (`#0F172A`) at `1.65rem`.
- **Micro Sparkline:** Embedded inline SVG trend line rendering in emerald or carmine based on period comparison.

### 5.3 Buttons (`.btn`)
- **Primary Action (`.btn-primary`):**
  - Gradient: Linear gradient 135deg from `#D4AF37` to `#E8CD83`.
  - Text Color: Deep Forest Green (`#071711`), font-weight 700.
  - Hover: Subtle brightness lift, translateY(-1px), gold drop-shadow (`--shadow-gold`).
  - Active: Flat translateY(0).
- **Secondary Action (`.btn-secondary`):**
  - Background: Deep Emerald (`#153A2D`), Text: `#FFFFFF`.
  - Hover: Background `#1E4E3D`.
- **Ghost Action (`.btn-ghost`):**
  - Background: Transparent, Border: 1px solid `#CBD5E1`, Text: `#153A2D`.
  - Hover: Background `rgba(21, 58, 45, 0.06)`, Border: `#153A2D`.
- **Destructive Action (`.btn-danger`):**
  - Background: `#DC2626`, Text: `#FFFFFF`.

### 5.4 Data Tables (`.table`)
- **Container:** Wrapped in `.table-wrap` with subtle horizontal scrolling on small screens.
- **Header:** Sticky top header with subtle gray background (`#F8FAFC`), 1px bottom border in Deep Emerald (`#153A2D`), uppercase 11px font with 0.06em tracking.
- **Alternating Rows:** Even rows receive transparent background; odd rows receive 2.5% opacity tint.
- **Hover State:** Entire row highlights with `rgba(21, 58, 45, 0.04)` and a 3px inset indicator in Warm Gold (`#D4AF37`).
- **Mobile Responsive Card Stacking (`.table.stack`):**
  - Below 640px, rows transform into discrete card blocks.
  - All `<td>` elements must supply `data-label="Column Name"`. The CSS pseudo-element `td::before { content: attr(data-label); }` displays the label on the left while the value aligns on the right.

### 5.5 Form Controls & Inputs (`.input`, `.field`)
- **Standard Input:** Height 42px, border radius 8px, border 1px solid `#CBD5E1`, background `#FFFFFF`.
- **Focus State:** 0 0 0 3px ring in translucent emerald (`rgba(21, 58, 45, 0.15)`), border color `#153A2D`.
- **Error State:** Border `#DC2626`, background `#FEF2F2`, helper error message rendered in `#B91C1C`.

---

## 6. DATA VISUALIZATION & CHART.JS GUIDELINES

For the seven analytics charts on the Administrator and Accountant dashboards:
1. **Background & Tooltip Styling:**
   Tooltips must render in Deep Forest Glass (`rgba(7, 23, 17, 0.94)`) with gold title text (`#E8CD83`) and crisp white body text (`#FFFFFF`).
2. **Chart Line Tension & Curves:**
   Line charts must use a refined cubic spline curve (`tension: 0.35`) with vector points highlighted by 3px white radii borders.
3. **Chart Palette Ramps:**
   - Collected Revenue: Solid Emerald `#15803D` with translucent fill `rgba(21, 128, 61, 0.15)`.
   - Expenses: Carmine `#DC2626` with translucent fill `rgba(220, 38, 38, 0.12)`.
   - Net Profit: Warm Gold `#D4AF37`.
   - Job Stages: Multi-tier status ramp (`#D97706`, `#0284C7`, `#B45309`, `#1E4E3D`, `#15803D`, `#071711`).

---

## 7. RESPONSIVE LAYOUT BREAKPOINTS

| Breakpoint Name | Media Query | Layout Behavior |
| :--- | :--- | :--- |
| **Large Desktop** | `min-width: 1367px` | 4-column KPI grids, 2-column chart grids, full 272px persistent sidebar. |
| **Standard Desktop** | `1025px – 1366px` | 4-column KPI grids, condensed topbar padding, persistent sidebar. |
| **Tablet Landscape** | `901px – 1024px` | 2-column KPI grids, collapsible 78px icon-only sidebar option. |
| **Tablet Portrait** | `641px – 900px` | Sidebar transitions to off-canvas drawer with backdrop; 2-column grids collapse to 1 column. |
| **Mobile Standard** | `421px – 640px` | Tables activate `.table.stack` card mode; KPI cards collapse to 2 columns; topbar search hidden. |
| **Mobile Compact** | `max-width: 420px` | 1-column KPI cards; full-width stacked filter forms; horizontal scrolling disabled. |

---

## 8. ACCESSIBILITY & INCLUSIVE STANDARDS (WCAG 2.1 AA)

1. **Color Contrast Thresholds:**
   - Normal text (<18pt): Minimum contrast ratio of **4.5:1** against the container background.
   - Large text (≥18pt or ≥14pt bold): Minimum contrast ratio of **3.0:1**.
   - Interactive components & UI boundaries: Minimum contrast ratio of **3.0:1** against adjacent surfaces.
   - *Prohibition:* Gold `#D4AF37` must **never** be used for small body text on light backgrounds. On light surfaces, use Deep Forest `#0D281E` or Dark Bronze `#855F00`.
2. **Keyboard Navigation & Focus Management:**
   - All interactive controls (`button`, `a`, `input`, `select`, `summary`) must possess an explicit `:focus-visible` ring (`0 0 0 3px rgba(212, 175, 55, 0.45)`).
   - Off-canvas drawers and modal dialogs must trap keyboard focus while open and release upon `Escape` key press.
3. **Screen Reader Semantic Structure:**
   - All data charts must provide descriptive `aria-label` text and `role="img"`.
   - Form inputs must be linked to explicit `<label for="...">` elements with `aria-required="true"` where mandatory.

---

## 9. RECOMMENDED CSS ARCHITECTURE (REUSABLE SHARED SYSTEM)

Rather than maintaining six disjointed dashboard views with inline styles and separate asset files, the system will consolidate into a single clean cascade:

```
public/assets/css/
├── variables.css      # Single source of truth: Deep Green & Gold design tokens
├── reset.css          # Standard modern CSS reset
├── typography.css     # Typography scale and tabular numerals
├── layout.css         # Shell, sidebar, topbar, responsive drawer
├── components.css     # Cards, buttons, alerts, badges, modals, toasts
├── forms.css          # Input styles, select boxes, validation states
├── tables.css         # Enterprise data tables & responsive stack definitions
├── dashboard.css      # KPI metric grids, chart wrappers, progress meters
├── responsive.css     # Breakpoint overrides
└── theme.css          # Dark-mode variable mappings
```

By connecting the Customer Portal to this unified asset pipeline and adopting the Deep Forest Green and Warm Gold design tokens, the entire ALPHA GARAGE suite will achieve a world-class, cohesive, and luxurious user experience.

---
*Design proposal prepared under read-only audit authorization.*
