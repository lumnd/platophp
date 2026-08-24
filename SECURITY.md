# Security Policy

## Supported versions

PlatoPHP is pre-1.0. Only the latest release is supported: a fix goes into the next tag, and there
are no backports to earlier ones.

| Version | Supported |
| --- | --- |
| latest release | yes |
| anything older | no |

## Reporting a vulnerability

**Do not open a public issue, a pull request or a discussion for a security problem.**

Report it privately, either way:

- **GitHub** — the *Security* tab of this repository, *Report a vulnerability*. This opens a
  private advisory that only the maintainers can see.
- **Email** — github@lumnd.com, with `[platophp security]` in the subject.

What helps, in rough order of usefulness:

- the version, and whether it reproduces on `main`
- the smallest piece of code that shows it — a controller action, a config snippet, a request
- what an attacker gets out of it: whose data, which requests, from which position
- your assessment of severity, and how you would fix it, if you have one

You will get an acknowledgement within a few days. If it is a real issue you will be told what the
fix looks like and when it is planned. Credit in the advisory and in `CHANGELOG.md` unless you ask
not to be named.

Please give a reasonable window before disclosing publicly. This is a volunteer project and the
answer to "how long" depends on the finding; it will be agreed with you rather than dictated.

## Scope

In scope — a defect in this package that lets an attacker do something the framework is meant to
stop:

- bypassing CSRF verification, the action whitelist, the encrypted envelope, or the route guards
- injection reachable through the query builder, the template layer or the request parsing
- the crypto in `src/security/` used wrongly: a fixed IV, a key derived from something guessable,
  a comparison that is not constant time
- the file cache or the session handler exposing another request's data
- a path traversal through `resp::download()` / `resp::file()` or the template resolution

Out of scope:

- an application built on the framework configuring it insecurely, unless the insecure setting is
  the default this package ships
- anything needing an attacker who can already run PHP in the process
- a missing hardening measure with no attack behind it. That is a feature request, and an issue is
  the right place for it
- vulnerabilities in Smarty or in the PHP extensions this package suggests — report those upstream

## What this package promises about its defaults

The framework applies these rules to security-sensitive defaults:

- **A default must never be the one that switches a check off.** The CSRF IP allowlist ships empty
  because host configuration is merged into framework defaults.
- **The CLI and WebSocket entry points skip the authorisation callback and CSRF by default**,
  controlled by `cli_auth` and `cli_csrf`. That is documented behaviour, not an oversight. A change
  that makes any default more permissive has to say so explicitly in the pull request.
