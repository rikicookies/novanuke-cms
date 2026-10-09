# Friends

Friends provides member-to-member friendship requests, accepted relationships and contact blocking.

## Capabilities

- Member-facing friends area and relationship actions.
- Request, accept and manage mutual friendships.
- Block contacts according to the module’s relationship rules.
- Friend-request and acceptance events for other modules to observe.

Friends has no module-specific permissions in `module.json`; access is member-facing and still subject to normal authentication and application authorization rules. It is not a public anonymous directory.

## Installation

Friends is enabled by default on a fresh NovaNuke installation. Its migration is applied through the normal module installation/update lifecycle. See [`docs/MODULES.md`](../../docs/MODULES.md) for module lifecycle and package guidance.

The module depends on the application’s authenticated user and event systems. Test relationship behavior with appropriate member accounts when enabling it on an existing site.
