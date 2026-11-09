# Website Hardening Implementation Report

## Scope

This report records implementation following the public-site UI requests and static audit. It is not a penetration-test report or evidence of a verified production deployment.

## Implemented

- Rebranded the public experience as Alpha Garage, added a matching gold-and-forest monogram logo, and updated the configurable brand defaults and fresh-install seed. Existing databases can apply `database/migrations/alpha_garage_brand.sql` to update their stored garage name.
- Kept animated typewriter copy in the hero with responsive sizing and updated brand language.
- Added a styled, auto-sliding customer-trust carousel with accessible controls. It highlights service commitments rather than inventing customer testimonials or ratings.
- Moved the customer-trust carousel to directly below the hero feature strip so it appears near the top of the homepage, before the about and services sections.
- Added a continuous, responsive feature marquee after the hero with ten service and booking highlights, hover pause, screen-reader-safe duplicated content, and a static layout for reduced-motion preferences.
- Replaced the rotating top announcement with a seamless, gap-free welcome-and-services marquee across public pages; matching repeated text maintains the loop, it pauses on hover/focus, and it presents a static accessible message for reduced-motion preferences.
- Refreshed the primary landing page with a clearer booking-first hero, direct call/booking actions on small screens, a simpler navigation structure, and a keyboard-accessible skip link.
- Added a rotating announcement bar above the fixed navigation on the landing, contact, about, privacy, and terms pages, plus animated typewriter copy in the landing hero.
- Turned the landing-page visit guidance into an accessible auto-advancing carousel with manual controls, pause/resume, keyboard support, and reduced-motion handling.
- Made the Quick Links and Client Access footer groups collapsible on mobile while keeping them expanded on larger screens.
- Replaced placeholder customer testimonials and unverified marketing statistics with practical service-selection guidance and next steps.
- Improved service-card contrast, spacing, and responsive layouts; made phone/email details actionable.
- Updated the hero slideshow to use vehicle imagery and added keyboard-friendly mobile navigation behavior.
- Added an automatic five-image hero slideshow on the optional marketing page, including controls and reduced-motion behavior.
- Improved automotive service cards to show catalog category, standard price, estimated time, and service-specific booking links.
- Added two-hour CSRF token age validation and regenerated CSRF session state at login.
- Hardened contact inquiry validation and removed the false-success path when persistence fails.
- Removed public registration's fallback to staff roles and made required phone input explicit.
- Removed fabricated public catalog data on query failure and added separate empty/unavailable states.
- Required database settings through runtime environment variables, removed runtime schema creation/import, and added a CLI-only forward migration runner with checksum tracking.
- Added a separate runtime/migration account grant template and documented the destructive-only-for-empty-database baseline import.
- Removed inline styling from several public landing/contact/auth pages and replaced hero slide background injection with image elements. This is a partial CSP remediation; other pages still contain inline styling.
- Made encryption-key configuration explicit: an environment key must be 64 hexadecimal characters; file/key-generation failures are reported instead of silently falling back.
- Updated installation and audit documentation to distinguish implemented source changes from unverified deployment behavior.

## Validation

- PHP syntax validation: passed for all changed PHP files, including the new unit test.
- Non-destructive unit tests: `tests/security_hardening_unit.php` passed with `mbstring` and `openssl` enabled. The default CLI configuration did not load those required extensions; both were loaded explicitly for this run.
- Re-run on this MAMP PHP installation with:
  ```powershell
  & "C:\MAMP\bin\php\php8.3.1\php.exe" -d "extension=C:\MAMP\bin\php\php8.3.1\ext\php_mbstring.dll" -d "extension=C:\MAMP\bin\php\php8.3.1\ext\php_openssl.dll" "tests\security_hardening_unit.php"
  ```
- Database integration suite: not run. `tests/run_tests.php` writes data to its configured database.
- MySQL/MariaDB baseline import, migration parser/checksum behavior, and least-privilege grants: not run; no disposable database was configured.
- Browser/CSP verification, contact persistence, and full registration workflow: not run.
- Landing page browser check at 320, 375, 390, 430, 768, 860, 980, 1024, and 1440 pixels: no horizontal overflow. Verified mobile call/booking bar, menu sign-in link, Escape-to-close behavior, skip-link focus, and image loading.
- Latest public-page browser check at 320, 390, 768, 1024, and 1440 pixels: no horizontal overflow on the landing, contact, about, privacy, and terms pages; fixed header remains at the viewport top; footer groups follow the expected mobile/desktop open state.
- Verified announcement rotation, carousel auto-advance, dot navigation, pause behavior, mobile footer toggling, and static content under reduced-motion preference in the browser.
- Browser console still reports existing inline-style CSP violations on some public pages; full CSP remediation remains outstanding.
- Latest Alpha Garage browser check confirmed the updated brand title and logo render, hero typewriter markup is present, and both landing-page carousels auto-advance and respond to manual selection and pause controls. Width checks at 320, 390, 768, 1024, and 1440 pixels found no horizontal overflow.
- Customer-trust section browser check passed at 320, 375, 390, 430, 768, 1024, and 1440 pixels. Auto-advance, dot navigation, and pause were verified.
- Verified the trust carousel now appears immediately after the hero feature strip and still auto-advances.
- Feature marquee browser check passed at 320, 375, 390, 430, 768, 1024, and 1440 pixels with no horizontal overflow; reduced-motion mode disables the animation and hides the duplicate list.
- Welcome marquee browser check passed at 320, 375, 390, 430, 768, 1024, and 1440 pixels with the contact link visible and reduced-motion behavior verified.
- Confirmed both repeated marquee segments have matching widths at mobile and desktop breakpoints for a seamless loop.
- PHP syntax validation passed for the updated public pages and application branding files.
- Rebranding migration SQL was added but not executed against a database.

## Remaining work and deployment cautions

1. Configure separate database users and verify the actual application query permissions against a disposable MySQL/MariaDB database.
2. Test the migration runner and migration SQL on supported database versions; migrations are forward-only, and the baseline SQL contains destructive `DROP TABLE` statements.
3. Finish CSP remediation on all pages and verify the site in a CSP-enforcing browser.
4. Add rate limiting for anonymous contact and password-reset requests.
5. Remove `public/setup.php` from deployed public directories, even though it locks after setup.
6. Conduct database backup/restore drills and establish external audit-log checkpointing and encryption-key recovery/rotation procedures.
