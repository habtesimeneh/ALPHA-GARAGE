# ALPHA GARAGE — PHASE 1.13
## TRANSLATION ARCHITECTURE & INTERNATIONALIZATION (i18n) SPECIFICATION

### 1. Executive Summary

Phase 1.13 introduces a comprehensive, production-grade bilingual internationalization (i18n) and localization (l10n) architecture to the ALPHA GARAGE platform. The application provides complete, seamless support for two languages:
1. **English (EN)** — Canonical default language.
2. **Amharic (አማርኛ — AM)** — Official working language of the Federal Democratic Republic of Ethiopia.

The architecture was engineered under strict zero-regression constraints:
- **Zero database schema modifications**: No columns or tables were altered. Database records retain their canonical values.
- **Strict LTR layout preservation**: Amharic is an indigenous Ethiopic script written from left to right (LTR). Layouts, sidebars, forms, tables, and data grids are never mirrored or inverted.
- **Fail-closed & progressive enhancement**: Every component functions reliably without JavaScript, falling back gracefully to English defaults when an unmapped term or invalid parameter is encountered.
- **Chart.js contract safety**: Client-side label translation maps canvas displays without altering Phase 1.11E `{ labels: [...], data: [...] }` backend payloads.

---

### 2. Architecture Overview

```mermaid
flowchart TD
    Req[Incoming HTTP Request] --> CheckParam{Has ?lang= in URL?}
    CheckParam -- Yes (Valid: en|am) --> SetSession[Set $_SESSION['lang'] & Cookie GMS_LANG]
    CheckParam -- No --> CheckSession{Has $_SESSION['lang']?}
    CheckSession -- Yes --> UseSession[Set Active Language]
    CheckSession -- No --> CheckCookie{Has GMS_LANG Cookie?}
    CheckCookie -- Yes --> UseCookie[Set Active Language]
    CheckCookie -- No --> DefaultLang[Default to 'en']

    SetSession --> ActiveLang[Active Language: 'en' or 'am']
    UseSession --> ActiveLang
    UseCookie --> ActiveLang
    DefaultLang --> ActiveLang

    ActiveLang --> HtmlTag["Render &lt;html lang='...'&gt;"]
    ActiveLang --> LoadDict["Load Language Dictionary (en.php / am.php)"]
    LoadDict --> ViewRender["Render PHP Views (t() / __())"]
    ViewRender --> ClientJS["Initialize Client i18n (window.GMS_I18N)"]
    ClientJS --> LiveDom["Translate Data Attributes & Charts"]
```

---

### 3. Language Resolution & Precedence Hierarchy

The language resolution pipeline is implemented in `app/helpers/i18n.php` via `init_language()` and `current_lang()`:

1. **Explicit URL Parameter (`?lang=`)**:
   - Accepts strictly allowlisted values: `['en', 'am']`.
   - Any malicious string (e.g. `<script>`, `../../etc/passwd`, non-whitelisted strings) is rejected and defaults to `'en'`.
   - When present, updates `$_SESSION['lang']` and sets a persistent cookie `GMS_LANG` (1-year TTL, `SameSite=Lax`, `path=/`).
2. **Active User Session (`$_SESSION['lang']`)**:
   - If the user previously selected a language during their session, it persists across all subsequent page views and role transitions.
3. **Persistent Cookie (`$_COOKIE['GMS_LANG']`)**:
   - Enables language preference persistence across browser restarts and returning visits.
4. **Canonical Fallback (`'en'`)**:
   - Guarantees backward compatibility with automated test suites and existing integrations.

---

### 4. Query Parameter Preservation Algorithm

Switching languages must never disrupt the user's active workflow, filters, search terms, or pagination state.

The helper function `lang_switch_url(string $targetLang)`:
- Extracts existing `$_GET` parameters (e.g. `page=3`, `status=in_progress`, `q=brake`).
- Overwrites or appends the `lang` key with `$targetLang`.
- Reconstructs the canonical URI using `http_build_query()`.
- Result: A user filtering invoices on page 2 (`invoices/?status=unpaid&page=2`) switching to Amharic seamlessly lands on `invoices/?status=unpaid&page=2&lang=am` with all filters intact.

---

### 5. Dictionary & String Resolution Engine

The translation subsystem employs a dual-mode resolution pipeline:

1. **Structured Modular Dictionaries (`app/lang/en.php` and `app/lang/am.php`)**:
   - Organized into logical domains:
     - `common.*` (App name, actions, buttons, generic terms)
     - `status.*` (Repair and financial lifecycle states)
     - `priority.*` (Urgency levels)
     - `nav.*` (Top-level menus, sidebar groups, navigation items)
     - `topbar.*` (Alerts, dropdown headers, quick actions)
     - `auth.*` (Sign in, registration, recovery, field labels)
     - `dashboard.*` (Role headers, KPI titles, analytics ranges)
     - `landing.*` (Public hero, features, FAQs, footers)
     - `modules.*` (Subpage headers and descriptive subtitles)
     - `msg.*` (Toasts, error notifications, validation hints)

2. **Core Helper Functions**:
   - `t(string $key, ?string $default = null, array $params = []): string`:
     - Checks structured dictionary keys first.
     - Checks literal English phrase mappings for legacy views.
     - Performs parameter interpolation (`:name`, `{name}`).
     - Returns default or key if in English or untranslated.
   - `e_t(string $key, ?string $default = null, array $params = []): string`:
     - XSS-safe escaped translation output (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`).
   - `__(string $key, array $params = []): string`:
     - Standard modern localization helper for structured keys.
   - `__e(string $key, array $params = []): string`:
     - XSS-safe shortcut for `__()`.

---

### 6. Client-Side Localization & Chart.js Integration

To ensure full interactivity, dynamic content translation is handled through `public/assets/js/i18n.js`:
- Exposes `window.GMS_I18N` and `window.GMS_LANG` APIs.
- Translates dynamic HTML attributes: `[data-i18n]` and `[data-i18n-placeholder]`.
- Synchronizes language switching via click handlers.
- **Chart.js Label Translation (`dashboard.js`)**:
  - The backend emits raw database keys (`Pending`, `In Progress`, `Revenue`, `Expenses`).
  - The frontend maps chart dataset labels and slice legends via `window.GMS_I18N.t(lbl)` upon canvas rendering.
  - Guarantees zero regression on Phase 1.11E automated chart payload contract tests.
- **Form Validation (`validation.js`)**:
  - Real-time client-side HTML5 validation errors dynamically adapt to the active language (`document.documentElement.lang`).
  - Password strength meter displays localized feedback (`በጣም ጠንካራ`, `ደካማ`).

---

### 7. Ethiopic Typography & Design Tokens

Amharic script requires dedicated typographic support to ensure optimal legibility and prevent clipping of diacritics:
- **Font Stack**:
  ```css
  --font-ethiopic: 'Nyala', 'Segoe UI', 'Noto Sans Ethiopic', 'Abyssinica SIL', sans-serif;
  ```
- **Language-Scoped Overrides**:
  ```css
  html[lang="am"] {
    font-family: var(--font-ethiopic);
    line-height: 1.6;
    letter-spacing: 0.01em;
  }
  ```
- **Windows Native Compatibility**:
  - `Nyala` and `Segoe UI` are pre-installed system fonts on Windows 10/11, delivering instantaneous, crystal-clear Ethiopic glyph rendering without external network latency or font-loading flicker.
