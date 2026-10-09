# Security policy

NovaNuke is currently a beta project. The public `0.4.0-beta.1` release is the documented upgrade baseline; private development snapshots are not supported upgrade sources. Check the project README and release documentation before assuming that a version is supported.

## Reporting a vulnerability

Please do not post unpatched vulnerability details in a public GitHub issue. Until the project publishes a verified private reporting channel, use the project owner or repository administrators through a private channel available to you and state that the message contains a security report. Do not include passwords, access tokens, production `.env` files, database dumps or personal data.

A useful report includes:

- the affected version or commit;
- a concise description and impact;
- reproducible steps or a minimal proof of concept;
- the affected route, module or configuration;
- any prerequisites and the expected versus observed behavior; and
- a suggested mitigation, if known.

The project has not declared a guaranteed response time, bug bounty, or verified private GitHub reporting feature. Those are owner follow-ups rather than promises of this policy.

## Deployment and module safety

Protect `.env`, credentials, backups, runtime logs and production data. Do not commit them or include them in a release archive. Follow the deployment and hardening guidance in `docs/PRODUCTION.md`, `docs/PRODUCTION_HARDENING.md` and `docs/SECURITY_CHECKLIST.md`.

Third-party PHP modules execute trusted extension code inside the application process. Review module source and provenance before installation, and install only modules from sources you trust. Ordinary media and user uploads must not be treated as executable PHP.

For ordinary defects, feature requests and documentation problems, use the normal public issue process after checking existing documentation and issues. Security reports should use the private approach above instead of exposing an unpatched vulnerability publicly.
