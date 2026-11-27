# Coachwood Tools: working rules

Read `docs/decisions.md`, `docs/phase-0-plan.md` and the latest entry in `docs/context.md` before starting. `docs/build-spec.md` section 16 holds the full rules. `decisions.md` wins where it differs from the spec.

- The old app at `~/PhpstormProjects/CompanyTools` is reference only. Never modify it, and never read its `.env`.
- No production credentials on a development machine. Anything needing live credentials is an Artisan command that Lesley runs on the server, and it prints no secrets.
- No live writes from development or shadow running. Every Shopify, Klaviyo or Amazon write goes through `App\Support\ExternalWrites`.
- No test makes a live call. `Http::preventStrayRequests()` is on in `Tests\TestCase`.
- Never run a destructive database command (`migrate:fresh`, `db:wipe`, mass delete) except against a disposable local database. Never write to the old database.
- Production data is confidential. Never put real customer, order or financial values in fixtures, docs, commits or output.
- Port tests first and make them pass. Fix bugs as found: write a test that fails on the old behaviour first, then log the fix in `docs/found-issues.md`.
- Work goes straight to `main`: no per-step branches or pull requests (decision 2026-12-02). Commit and push only when Lesley asks. Plesk deploys `release`, which CI moves to a commit only after it passes on `main`.
- End each session with a dated `docs/context.md` entry covering: what changed, test status, verified/from docs/assumed, open questions, next step.
