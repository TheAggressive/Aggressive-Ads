# Standard blocks

Blocks that render with editor scripts and plain front-end assets, built by
`pnpm build:blocks` into `dist/blocks/`.

**Empty on purpose.** The only public block this plugin ships — `aggr/ad-slot` —
lives in [`../blocks-interactivity/`](../blocks-interactivity/) because it needs
per-slot client state: it fills after paint, measures viewability, and can
rotate. This directory exists so the next block that *doesn't* need any of that
has an obvious home, and so the two builds stay separate.

The split mirrors the LAAO and Aggressive Apparel themes:

| Directory | Built by | Output | For |
|---|---|---|---|
| `src/blocks/` | `build:blocks` | `dist/blocks/` | Standard blocks |
| `src/blocks-interactivity/` | `build:interactivity` (`--experimental-modules`) | `dist/blocks-interactivity/` | Interactivity API blocks |
| `src/interactivity/` | `build:modules` | `dist/interactivity/` | Shared stores, not tied to a block |

`build:blocks` no-ops while this directory holds no `block.json`, so the build
stays green until the first standard block arrives. Delete that guard when one
does.
