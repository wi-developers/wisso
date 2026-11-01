# wisso

A Nextcloud 34 app used by WI on <https://path.wi.sa>. It lets a WI user who clicks the Path card land
signed in on Path (three-call contract: login, update, consume). It is not an OpenID Connect or SAML
provider. Server keys are never stored in the app's source: only a hash of the key is kept in the
server configuration.

- Licence: GNU AGPL v3 or later (`COPYING`). Notices: `NOTICE`.
- Source of the version running on path.wi.sa: this repository, tag `v0.1.2`. The deployable package is
  built with `sh tools/build-package.sh <commit> <outdir>` (deterministic).
- Tests: `tools/run-tests.sh` (needs a Nextcloud test environment).

No warranty. Questions about the source or the licence: open an issue in this repository.
