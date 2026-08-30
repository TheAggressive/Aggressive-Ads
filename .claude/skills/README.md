# Skills

Generic WordPress skills, copied from `laao/.claude/skills/` rather than
symlinked. `wp-block-themes` is deliberately not among them: block themes need
`theme.json`, HTML templates and `parts/`, and this is a plugin — its
`templates/` holds PHP portal templates. The rest earn their place
(`aggr/ad-slot` for block development, `test:e2e:studio` for the Studio CLI).

Copied rather than symlinked because: this plugin is its own git repository, and a symlink escaping it
would be committed and arrive broken in every other clone. They carry no
laao-specific content, so a copy is the honest representation.

They live here because skills do **not** resolve from parent directories the
way `CLAUDE.md` does. While they sat only in the laao root they were invisible
to every session started inside this plugin.

Re-sync from the laao copy if it changes upstream.

Trailing blank lines were stripped at EOF on import: this repository's
pre-commit hook rejects them. Account for that when re-syncing — the diff
against the laao copy is EOF whitespace only.
