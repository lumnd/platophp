<!--
One subject per pull request. A rename and a behaviour change in one diff cannot be reviewed.
Security problems do not go here — see SECURITY.md.
-->

## What this changes

<!-- One or two sentences. What is different after this is merged. -->

## Why

<!--
The reasoning, not the diff. What was wrong, what you considered, why this shape and not another.
This is the half that ends up in CHANGELOG.md and that the next person needs.
-->

## Does anything behave differently than before?

<!--
This package is pre-1.0, but an existing call changing behaviour still has to be said out loud —
here and in CHANGELOG.md. Write "no" if nothing does.
-->

## Checklist

- [ ] `composer test` passes — or the failures are listed below with the reason (a missing Redis or
      MySQL is an environment problem; say so, do not edit the test)
- [ ] `composer check:architecture` passes
- [ ] `composer analyse` passes with no PHPStan errors
- [ ] `composer style` reports zero errors
- [ ] Tests cover the behaviour added, or the bug fixed
- [ ] `CHANGELOG.md` has an entry under the current Unreleased version, with the reason and not only
      the change
- [ ] Code comments and docblocks are in English
- [ ] `README.md` and `README.zh-CN.md` are both updated, if either needed it
- [ ] Nothing under `tests/` writes into the repository tree (`git status` is clean after a run)

## Test failures, if any

<!-- Paste them. A red test reported honestly is worth more than a green one that was edited. -->
