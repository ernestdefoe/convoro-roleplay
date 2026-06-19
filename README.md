# Convoro Role-Play

A free, first-party Convoro extension that turns a forum into a role-play community.

Members create **characters** and **post as them** — in-character boards show the
character's name and avatar instead of the account, powered by the core
`PostIdentity` hook. Multiple characters per account, character profiles, and
(coming) applications & claims, in-character/out-of-character boards, character
sheets & dice, trackers, and a live shoutbox.

Self-contained: all of its tables (`rp_*`) are created and dropped by the
extension's own migration. Requires Convoro ≥ 1.40.0 (for `App\Support\PostIdentity`).

## Status

Spine: characters + post-as-character. More features in progress.

MIT licensed.
