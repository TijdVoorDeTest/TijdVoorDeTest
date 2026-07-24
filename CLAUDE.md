# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Tijd voor de test** is a PHP/Symfony 8.1 application for managing quizzes in the style of **Wie is de Mol?** (WIDM) —
a Dutch TV show where contestants try to identify a saboteur ("de Mol") among them. At the end of each episode,
participants take a quiz about the Mol's identity and actions; the candidate with the least correct answers is
eliminated. This app replicates that quiz format with:

- Test creation with variable question counts
- Season management with active test controls
- Candidate answer tracking with automatic timing
- Elimination tracking with joker adjustments
- Backoffice management for quiz administration and statistics

Tech Stack:

- **Framework**: Symfony 8.1
- **PHP**: 8.5+
- **Database**: PostgreSQL 16
- **ORM**: Doctrine
- **Server**: FrankenPHP with Caddy
- **Container**: Docker Compose
- **Frontend**: Twig templates with SASS and TypeScript (via asset mapper)
- **Testing**: PHPUnit 13 with DAMA Doctrine test bundle

## Build & Development Commands

All commands assume Docker is running. The project uses a [Justfile](https://just.systems) as the primary interface.

### Essential Commands

```bash
just up           # Start Docker services (PHP, PostgreSQL)
just stop         # Stop services
just down         # Stop and remove containers/orphans
just shell        # Interactive shell inside the PHP container
just shell-run    # Shell in a fresh one-off container
```

### Working in git worktrees

`just up` auto-runs `just init` first, which generates a gitignored `.env.local` per checkout with a unique
`COMPOSE_PROJECT_NAME`, `IMAGES_PREFIX`, and free `HTTP_PORT`/`HTTPS_PORT`/`POSTGRES_PORT`/`MAILPIT_PORT`/
`SPOTLIGHT_PORT`. This means every worktree gets its own containers, network, volumes, and image tag — running
`just up` in two worktrees at the same time does **not** make them share a database, image, or port, even if the
worktree directories have the same basename.

- Run `just ports` to see the ports assigned to the *current* checkout — the app for that worktree is at
  `https://localhost:<HTTPS_PORT>`, not a fixed port. Never assume port 8080/8443/5432/etc. when working inside a
  worktree; always check `.env.local` or `just ports` first.
- `.env.local` is generated once and reused; it's safe to run `just init`/`just up` repeatedly. Delete `.env.local`
  and re-run `just init` to force new ports (e.g. if the assigned ones are now taken by something else).
- Each worktree's Postgres data, uploaded files, and Caddy state live in per-worktree Docker volumes — nothing is
  shared with the main checkout or other worktrees. Migrations/fixtures must be (re-)run per worktree.
- `just down`/`just clean` in one worktree only ever affects that worktree's own containers/volumes — safe to run
  without impacting other worktrees.

### Database

```bash
just migrate                  # Run Doctrine migrations (starts services first)
just fixtures                 # Load dev fixtures (truncates first)
just reload-tests             # Drop/recreate test DB, migrate, load test fixtures
```

### Testing

```bash
just test                                  # Run full PHPUnit suite
just test tests/Path/To/TestFile.php       # Run a specific test file
just test --coverage-html var/coverage     # Generate HTML coverage report
```

### Code Quality & Linting

```bash
just fix-cs                        # Auto-fix PHP-CS-Fixer + Twig-CS-Fixer
just phpstan                       # PHPStan static analysis (level 9)
just phpstan --no-progress         # Without progress output
just rector                        # Apply Rector modernizations
just rector --dry-run              # Preview Rector changes
```

### Other

```bash
just translations   # Extract/update nl translation strings
just clean          # Nuke containers (volumes) + all generated files (prompts for confirmation)
just trust-cert     # Trust the local Caddy TLS certificate (macOS)
just exec <cmd>     # Run any command inside the PHP container
```

All code quality checks run in CI/CD (.github/workflows/ci.yml) and should pass before merging.

## Project Structure

```
src/
  Controller/             # HTTP request handlers (attribute-routed)
    Backoffice/           # Admin panel controllers
  Entity/                 # Doctrine ORM entities
  Repository/             # Database queries
  Service/                # Business logic
  Command/                # CLI commands
  Form/                   # Symfony form types
  Dto/                    # Data transfer objects
  Enum/                   # Enumerations (FlashType, etc.)
  Exception/              # Custom exceptions
  Factory/                # Object factories
  Helpers/                # Utility functions
  Security/               # Auth and voter classes
    Voter/                # Authorization voters
  DataFixtures/           # Test data loaders

config/
  packages/               # Symfony bundle configurations
  routes/                 # Route definitions
  services.yaml           # Service container configuration
  routes.yaml             # Main route entry point

templates/
  backoffice/             # Admin UI templates
  quiz/                   # Public quiz UI templates
  base.html.twig          # Main layout

tests/
  Command/                # Command tests
  Controller/             # Controller/integration tests
  Repository/             # Repository tests
  Security/               # Auth tests
  Helpers/                # Utility tests
  bootstrap.php           # PHPUnit bootstrap with test container setup
```

## Core Domain Entities

- **Season**: Groups quizzes and candidates for a specific period, with a linked `SeasonSettings`.
- **Quiz**: A test within a season containing multiple `Question`s, each with multiple `Answer`s.
- **Candidate**: A participant in the season.
- **QuizCandidate**: Represents a candidate's attempt at a specific quiz (tracks start/end time).
- **GivenAnswer**: The specific answer a candidate selected during a quiz.
- **Elimination**: Records red/green screens and forced results with joker adjustments.
- **User**: Administrative accounts for managing the system.

## Domain Context: "De Test" (Wie is de Mol)

**Wie is de Mol?** (WIDM) is a Dutch reality competition: a group of contestants ("kandidaten") travels together while
one of them, "de Mol", secretly sabotages assignments. Each episode ends with the fixed line: *"Tijd voor de test.
Twintig vragen over de identiteit en het doen en laten van de Mol. Degene die het minst weet, ligt uit het spel. Behalve
de Mol. Die hoeft nooit naar huis."* ("Time for the test. Twenty questions about the identity and the actions of the
Mol. Whoever knows the least is out of the game. Except the Mol — they never have to go home.") The contestant with the
worst score is eliminated ("afvallen"); the Mol is immune regardless of score, since they already know the answers. This
app is a generic engine for running that quiz format for private/fan seasons, not just modeling the TV show
incidentally — the entity model below exists specifically to reproduce WIDM's test mechanics.

### What a test's 20 questions actually are

Per the intro line, questions fall into two factual categories — never opinion ("who would you vote off") — plus a
third recurring format used on the show:

1. **Identity of the Mol**: guessing which contestant is the Mol.
2. **The Mol's actions**: what the Mol did or where the Mol was during a specific assignment/moment.
3. **Candidate self-answered questions**: earlier, every contestant privately answered a question about themselves
   (an interview-style question); the test then asks other contestants to guess what a *specific* candidate answered
   about themselves. This tests how well contestants know each other, not just Mol-tracking.

### Why answers can be bound to candidates

All three categories above can have contestants themselves as the answer options rather than free text: "who is the
Mol" and "who did X" both need contestant names as options, and "what did candidate Y answer" needs Y's own submitted
answer among the options. In the domain model this is `Answer::$candidates` (a `ManyToMany` to `Candidate`, on both
sides): an answer option can *be* another contestant, not just text.

Because the relationship is many-to-many on the answer side too, a single answer option can cover **more than one
candidate at once** — e.g. "Anna en Bram" as one option for "who missed the assignment together", or an option
representing everyone who gave a particular self-answer in category 3 above. So a candidate-bound answer isn't always
one candidate, it can be a group; treat `Answer::$candidates` as "the set of contestants this option represents", not
as a single foreign key.

Combined with `GivenAnswer::$candidate` (who answered), every given answer on a candidate-bound question is a directed
relationship from the answering candidate to *every* candidate covered by the chosen option — a one-to-many edge when
the option is a group, not just candidate A pointed at candidate B. This is the mechanic behind any "who's suspected of
what" or sociogram-style statistic — it only applies to candidate-bound questions, plain trivia questions have no such
relationship. `Quiz::getQuestionErrors()` already relies on this distinction to validate that every active candidate is
covered exactly once per candidate-bound question (a candidate appearing across multiple group-options on the same
question counts as covered more than once).

### Elimination mechanics

- **Red/green screens**: at the end of a test, contestants are shown red or green screens one at a time to build tension
  before the elimination is revealed. `Elimination::$data` stores the colour shown per candidate (
  `SCREEN_RED/SCREEN_GREEN` via `getScreenColour()`), independent of the actual quiz score.
- **Jokers / corrections**: contestants can hold a "joker" (an advantage, e.g. an extra correct answer) that adjusts
  their effective score without changing what they actually answered. This is `QuizCandidate::$corrections` — a float
  added to the raw score, kept separate from `GivenAnswer` so the audit trail of what was actually answered stays
  untouched.
- **Dropouts**: `Quiz::$dropouts` controls how many contestants can be eliminated in a single test (normally 1, but some
  episodes eliminate more).
- **Finalization/locking**: `Quiz::$isFinalized` and `$isLocked` gate when a quiz's questions/answers can still be
  edited — a quiz becomes immutable once a candidate has started it or an admin explicitly finalizes it. Treat this as
  the natural point where computed results (scores, statistics) can be cached indefinitely, since nothing that feeds
  them can change afterward.

### Terminology map (Dutch UI ↔ domain code)

| UI/domain term (Dutch)       | Code                          |
|------------------------------|-------------------------------|
| Test                         | `Quiz`                        |
| Vraag                        | `Question`                    |
| Antwoord                     | `Answer`                      |
| Kandidaat                    | `Candidate`                   |
| Ingevuld antwoord            | `GivenAnswer`                 |
| Afvallen / rood-groen scherm | `Elimination`                 |
| Joker / correctie            | `QuizCandidate::$corrections` |

## Architecture Notes

### Routing

- Routes are **attribute-based** (PHP 8 attributes in controller methods)
- Configured in `config/routes/attributes.yaml` for automatic discovery
- Main entry point: `config/routes.yaml`

### Service Container & Dependency Injection

- Services in `src/` are automatically registered via PSR-4 namespace `Tvdt\`
- Exclusions: Entity, DependencyInjection, Kernel classes
- Autowiring and autoconfiguration enabled by default
- Service definitions in `config/services.yaml`

### Database & Migrations

- PostgreSQL-based with Doctrine ORM
- Migrations in `migrations/` at project root, namespace `DoctrineMigrations` (intentionally not autoloaded); generate
  with `bin/console make:migration`
- Test fixtures in `src/DataFixtures/` (loaded with `--group=test`)
- Test database configured separately via `.env.test`

### Testing Infrastructure

- **PHPUnit 13** with DAMA Doctrine Test Bundle for transaction rollback
- Bootstrap: `tests/bootstrap.php` loads env vars and autoloader; `tests/symfony-container.php` boots the test
  kernel/container (used by Rector)
- Symfony test utilities (BrowserKit, CSS selectors) available
- Coverage excluded from: `src/DataFixtures/`
- Test environment: `APP_ENV=test` (set in phpunit.dist.xml)

### Testing Conventions (TDD)

- **Write the failing test first.** When fixing any PHP-reachable bug, write a PHPUnit test that reproduces the failure
  before touching the production code. Fix the code until the test passes.
- For bugs in `assets/*.ts` logic, write a `deno test` first instead — same TDD rule, different runner. Only skip a
  test if the bug is in markup/DOM wiring that isn't worth a test per the rule below.
- Don't write tests for trivial presentational markup (e.g. asserting a tooltip/popover attribute or a CSS class exists
  in a template). Tests cover behavior: routing, forms, persistence, authorization.
- Follow the pattern in `tests/Controller/Backoffice/` for controller/integration tests: log in, GET for CSRF token,
  POST form data, assert redirect, clear entity manager, assert DB state.
- **Prefer `TestCase` over `WebTestCase`/`KernelTestCase`.** Reach for the full kernel/DB boot only when the test
  genuinely needs routing, persistence, or the container — pure logic (services, listeners, helpers) should be tested
  with plain PHPUnit `TestCase` and mocked dependencies; it's faster and more isolated.
- **Boy Scout Rule**: when you're already touching a file for an unrelated change, fix small nearby issues in the same
  commit (e.g. a test that unnecessarily extends `WebTestCase`, a stale comment) rather than leaving them for later —
  but don't let this balloon into an unrelated refactor.
- **Scope creep as a service**: when a review (yours or `/code-review`'s) turns up a real bug or gap outside the
  original task's scope — even in already-merged code untouched by the current diff — fix it in the same MR rather
  than just reporting it and moving on. Write a regression test first per the TDD rule above. Only leave something
  unaddressed if fixing it would require a design decision only a human can make (e.g. reverting an intentional
  security/access-control choice) — in that case, say so explicitly instead of silently skipping it.

### Code Style & Standards

- **PHP-CS-Fixer**: Symfony ruleset + risky rules enabled
    - Strict types declaration required
    - Trailing commas in multiline structures
    - No else-only blocks
- **Rector**: Aggressive modernization with all attribute sets + prepared sets (dead code, code quality, Doctrine,
  Symfony, PHPUnit)
- **PHPStan**: Level 8 with extensions for Doctrine and Symfony
- **Twig-CS-Fixer**: Template style enforcement
- **Safe functions**: Use `thecodingmachine/safe` wrappers for standard PHP functions that return `false` on failure —
  they throw exceptions instead
- **TypeScript (`assets/`)**: Compiled via `sensiolabs/typescript-bundle` (standalone SWC binary, no Node/npm).
  Formatting, linting, type-checking, and tests use **Deno** (`deno fmt` / `deno lint` / `deno check` / `deno test`) —
  a single standalone binary, kept dev-only (installed in the `frankenphp_dev` Docker stage, not prod), consistent
  with the project's no-Node-anywhere approach. See `deno.json` for config; run via `just fix-ts` / `just check-ts` /
  `just test-ts`. Tests live alongside their source as `*_test.ts` files

### Environment Configuration

- `.env` - Local development defaults (uncommitted in .env.local)
- `.env.dev` - Development overrides
- `.env.test` - Test environment configuration
- Production uses `composer dump-env prod` for compiled configuration
- Key variables:
    - `APP_ENV` - Environment (dev/test/prod)
    - `DATABASE_URL` - PostgreSQL connection string
    - `MAILER_SENDER` - From address for emails

### Frontend Build

- Asset mapper (no Node.js/Webpack) for JS/CSS bundling; JS modules declared in `importmap.php`
- **Stimulus** controllers in `assets/controllers/`, **Turbo** for SPA-like navigation
- Sass sources in `assets/styles/`, compiled via `bin/console sass:build`
- Production: Assets precompiled during Docker build
- Development: Watch mode enabled in FrankenPHP container
- **Modals containing a form**: always wire up the `bo--modal` Stimulus controller's dirty-tracking, even when the
  modal is opened via plain `data-bs-toggle="modal"` rather than `bo--modal#open`'s turbo-frame loading. Add
  `data-controller="bo--modal" data-bo--modal-target="modal"` and `data-action="hidden.bs.modal->bo--modal#resetDirty"`
  on the modal element, and `data-action="input->bo--modal#markDirty change->bo--modal#markDirty"` on each form
  field. This blocks backdrop-click/Escape dismissal once the user has typed something, so in-progress edits aren't
  silently discarded. See `templates/backoffice/partials/rename_control.html.twig` for a reusable example.

## CI/CD Pipeline

GitHub Actions workflow (`.github/workflows/ci.yml`):

1. **Linting**: Dockerfile (hadolint), Twig templates
2. **Code Quality**:
    - PHP-CS-Fixer style check
    - Twig-CS-Fixer style check
    - PHPStan static analysis
    - Rector dry-run
    - Translation extraction check (fails if `translation:extract` produces uncommitted changes)
3. **Integration Tests**:
    - Docker image build and start services
    - Database creation and migration
    - Fixture loading
    - Full PHPUnit test suite with JUnit XML output
    - Doctrine schema validation
4. **Build & Deploy** (on tags or main, disabled currently):
    - Docker image push to GitHub Container Registry
    - Sentry release creation
    - Portainer webhook trigger for production deployment

Runs on all pushes to main and pull requests. Concurrency cancels old runs on new commits.

## Important Files & Conventions

- **Kernel**: `src/Kernel.php` - Symfony kernel class
- **AbstractController**: Base class for all controllers — defines route parameter regexes (`SEASON_CODE_REGEX`,
  `CANDIDATE_HASH_REGEX`) and flash helpers
- **Flash Messages**: Use `FlashType` enum instead of string literals
- **QuizSpreadsheetService**: Handles importing quizzes from XLSX files
- **Rector container**: `tests/symfony-container.php` — boots a test kernel so Rector can resolve Symfony service types
- **.gitignore**: Excludes var/, vendor/, .env.local, .phpunit.cache
- **Dockerfile**: Multi-stage build with dev/prod separation, FrankenPHP-based
- **Docker Compose**: PHP service with Caddy, PostgreSQL database, persistent volumes

## Security & Authorization

- Doctrine extensions enabled (timestamps, slugs, etc.)
- Voter-based authorization in `src/Security/Voter/`
- User entity with security encoding configured
- CSRF protection enabled
- Email verification available via SymfonyCasts bundle

## Composer Scripts

Auto-executed scripts on install/update:

- `cache:clear` - Symfony cache clear
- `assets:install` - Copy public assets
- `importmap:install` - JS import map setup

## Writing Style (Help Content & UI Text)

When writing Dutch help content in `templates/backoffice/help/nl/`:

- **No em-dashes** (—): use a comma or restructure the sentence instead.
- **No semicolons** (;): use a comma. Semicolons are technically correct but read as AI-generated text.
- **Natural Dutch**: write the way a person would explain it to a colleague, not in formal documentation style.
- **Colons after bold labels** (e.g. `<strong>Label:</strong> description`) are fine and intentional.

## Notes for Future Work

- The backoffice elimination logic is in `Controller/Backoffice/PrepareEliminationController.php`
- Quiz timing logic starts on candidate start click and stops on final answer selection
- Background music feature noted but not yet implemented (requirements only)
- Statistics module (per-quiz statistics page, candidate accusation matrix, caching) is planned per GitHub issue #199 —
  see "Domain Context" above for why candidate-bound answers matter to it
