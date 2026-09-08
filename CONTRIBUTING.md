# Contributing to WatchState

WatchState is a project for self-hosted play-state sync across Plex, Jellyfin, and Emby. Contributions are welcome, but final decisions about scope, architecture, and direction always rest with the maintainer.

## Core Principles

* The maintainer has final say on project direction.
* WatchState is built for personal use first. Community contributions are welcome when they align with the project's goals.
* All pull requests must target the `dev` branch, never `master`.
* Small, focused fixes are welcome. For larger changes, start a discussion before spending significant time on implementation.
* Contributions should improve correctness, compatibility, maintainability, performance, or documentation.
* Preference-only UI or stylistic churn is usually not a good fit.

---

## Contribution Process

### 1. Start the Conversation

Before writing non-trivial code, open the right channel first:

* Open a **GitHub Issue** for bugs, regressions, focused fixes, or small features.
* Start a **GitHub Discussion** for larger proposals, architectural questions, or open-ended design discussions.
* Use **Discord** for quick questions or informal discussion, but do not treat it as the system of record for substantial changes.

Include the following in your proposal:

* What you want to change
* Why it is needed
* How you plan to implement it at a high level
* Any backend-specific, sync-specific, or migration-related context that matters

Bug fixes, backend compatibility fixes, correctness improvements, and measurable performance work are more likely to be accepted than preference-driven redesigns.

### 2. Align on Scope

You do not need explicit approval for every typo fix or narrow bug fix, but you should get alignment first for changes such as:

* Large features
* Architectural refactors
* Database or migration changes
* New abstractions or framework patterns
* Broad UI rewrites

The maintainer may narrow the scope, suggest a different approach, or decline the change entirely. That is normal for a solo-maintained project.

### 3. Develop Your Changes

Branch from `dev`:

```bash
git checkout dev
git pull origin dev
git checkout -b feature/descriptive-name
```

Keep the change focused and consistent with the existing codebase:

* Match existing style and architecture instead of introducing new patterns by default.
* Add or update tests for every functional change.
* Bug fixes must include a regression test where practical.
* New features must include meaningful test coverage.
* Refactors must preserve existing behavior.
* Update docs or config examples when behavior changes.

### 4. Follow Project Standards

Backend expectations:

* Target PHP 8.4.
* Use `declare(strict_types=1)`.
* Follow PSR-12.
* Public methods should include PHPDoc.
* Prefer existing container and service patterns over manual instantiation or new abstraction layers.

Frontend expectations:

* WatchState uses a Nuxt 4 SPA with SSR disabled.
* Use single quotes and no semicolons.
* Keep types explicit. Do not introduce `any` without a strong reason.
* Do not rely on auto-imports. Import Vue, Nuxt, utilities, and components explicitly.
* Use `Array<Type>` syntax for arrays.
* Use `withDefaults` when component props have defaults.
* Reusable frontend types belong in `frontend/app/types/index.d.ts`.
* API responses should continue using `parse_api_response<T>()` and existing error-handling patterns.

### 5. Validate Before Opening a PR

Run the commands that match the parts of the project you changed.

Install the repository's pre-commit hook:

```bash
./.githooks/install
```

Use `./.githooks/install --force` to replace an existing pre-commit hook.

Add executable local checks to one of these directories:

* `.git/hooks/pre-commit.d/` for all changes
* `.git/hooks/pre-commit.d/backend/` for backend changes
* `.git/hooks/pre-commit.d/frontend/` for frontend changes

Backend:

```bash
composer format
composer lint
composer test
```

Frontend:

```bash
bun --cwd=./frontend/ lint
bun --cwd=./frontend/ lint:tsc
```

If your change affects the generated static frontend output used for release builds, also run:

```bash
bun --cwd=./frontend/ generate
```

For UI changes, do a manual browser check as well.

### 6. Open the Pull Request

Pull requests must target `dev`.

Your PR should:

* Reference the relevant issue or discussion when the change is non-trivial.
* Explain what changed and why.
* Call out breaking changes, migration notes, or operational impact.
* Stay focused and avoid unrelated cleanup.
* Pass the relevant checks.

PR checklist:

- [ ] Targets `dev`
- [ ] Linked the relevant issue or discussion for non-trivial work
- [ ] Tests added or updated and passing
- [ ] Relevant linting and validation commands were run
- [ ] Documentation updated if needed
- [ ] UI changes were manually verified if applicable

---

## Changes Likely to Be Rejected

The following are likely to be closed or declined:

* PRs targeting `master` instead of `dev`
* Large refactors, migration changes, or broad UI rewrites without prior discussion
* Changes that do not align with WatchState's goals
* Preference-only visual or stylistic churn without functional benefit
* Fully AI-generated submissions with minimal human review
* Changes the contributor cannot explain or justify

---

## AI-Assisted Development

AI tools are permitted as development aids, but you remain fully responsible for the submitted code.

### Acceptable Use

AI-assisted code is acceptable when:

* You fully understand every line being submitted
* The result matches the existing project conventions
* You reviewed, tested, and validated the output yourself
* Tests meaningfully cover the behavior being changed
* You can explain and defend the design decisions

AI is a tool, not a substitute for understanding.

### Not Acceptable

The following will be rejected:

* Prompt-dump code with little or no human review
* Code that introduces new patterns or abstractions without justification or prior discussion
* AI-generated tests that do not validate real behavior
* Changes the contributor cannot explain or maintain

### Disclosure

You are not required to disclose AI usage. Regardless of how the code was produced, you are accountable for correctness, maintainability, and alignment with the project.

---

## Questions?

* Check existing Issues and Discussions first.
* Join the project's Discord for quick questions and community discussion: https://discord.gg/haUXHJyj6Y
* Please be patient. This is a solo-maintained project, and replies may take time. The maintainer is in the `UTC+3` timezone.

---

## License

By contributing, you agree that your code will be licensed under the project's MIT License.

Thank you for helping keep WatchState stable and maintainable.
