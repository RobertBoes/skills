# skills

Claude Code skills for writing readable code, refactoring, and designing interfaces.

Each is a set of trigger→action rules meant to fire while you work — during code
review, while writing a component, while cleaning up a long method — rather than a
document you read once.

## Skills

| Skill | Covers |
|---|---|
| [`readable-code`](readable-code/skills/readable-code/SKILL.md) | Naming, guard clauses instead of nesting, removing dead code and explanatory comments, avoiding `null` returns, symmetry. Fires when writing or reviewing any code. |
| [`refactoring`](refactoring/skills/refactoring/SKILL.md) | When duplication is ready to abstract, extracting long methods by level, objects over primitives, turning accumulating loops into pipelines. Fires on long methods, duplication, and loops that build up a result. |
| [`ui-design`](ui-design/skills/ui-design/SKILL.md) | Reading a project's design tokens, visual hierarchy, spacing and type systems, color and shades, depth and shadows, empty states. Fires on UI markup and styles. |

They're split by topic, not by source: `readable-code` is about how code reads where
you write it, `refactoring` is about changing its structure. A messy method usually
wants both.

## Install

```
/plugin marketplace add <your-github-user>/skills
/plugin install readable-code@robertboes-skills
/plugin install refactoring@robertboes-skills
/plugin install ui-design@robertboes-skills
```

For local development, point at the directory instead:

```
/plugin marketplace add ~/Developer/skills
```

### Without the plugin system

Symlink a skill directly:

```
ln -s ~/Developer/skills/readable-code/skills/readable-code ~/.claude/skills/readable-code
```

Or into a single project's `.claude/skills/`. Each skill lives at
`<plugin>/skills/<name>`, so the same pattern works for all three.

## Publishing your own copy

```bash
cd ~/Developer/skills
git init && git add . && git commit -m "Initial commit"
gh repo create skills --public --source=. --remote=origin --push
```

To ship an update: edit the skill, bump `version` in both
`.claude-plugin/marketplace.json` and `<plugin>/.claude-plugin/plugin.json`, commit
and push, then `/plugin marketplace update robertboes-skills` on the consuming
machine.

## Layout

A marketplace repo holds plugins; a plugin holds skills:

```
skills/                                <- the marketplace (this repo)
  .claude-plugin/marketplace.json
  readable-code/                       <- a plugin
    .claude-plugin/plugin.json
    skills/readable-code/              <- the skill
      SKILL.md
      references/
  refactoring/
  ui-design/
```

Each skill is its own plugin so they install independently. To add another, create a
sibling directory with a `.claude-plugin/plugin.json` and a `skills/<name>/SKILL.md`,
then add an entry to `marketplace.json`.

## Design notes

These are written to survive framework churn and to avoid the usual failure mode of
"guidelines" skills, which is being nodded at and ignored.

**Triggers, not principles.** Rules are written as a condition and an action — "an
`else` after a branch that returns → drop the `else` and de-indent" — so they can
actually fire during review. Advice phrased as a virtue does nothing.

**No hardcoded project values.** `ui-design` reads the project's own theme tokens —
spacing, type scale, palette, shadows — and only falls back to a starting scale when
a project genuinely has none. Framework defaults move; relationships like "non-linear
steps, ~25% minimum gaps, line-height inverse to font size" don't.

**Operations, not method names.** `refactoring` names the operation (keep matching,
flatten one level, first match) and maps it across PHP/JS/Python/Ruby, with an
explicit instruction to verify signatures against the installed version rather than
trusting the write-up.

**Explicit limits.** Every skill says where to stop: don't rewrite untouched code,
don't abstract code that appears once, don't build a pipeline past ~5 links, don't
override a project's design system. Unbounded advice produces overreach.

**Dated notes are quarantined.** Anything version-specific lives in a reference file
with a verification date, marked subordinate to the project's own config — see
`ui-design/skills/ui-design/references/framework-notes.md`.

If a rule ever conflicts with a project's conventions, the project wins; the skills
say so explicitly.

## Credits

These skills teach ideas that aren't mine. The rules, examples, and organization here
are written from scratch, but the thinking behind them comes from:

- **[BaseCode Field Guide](https://basecodefieldguide.com)** — Jason McCreary. The
  backbone of `readable-code` and the extraction process in `refactoring`.
- **[Refactoring to Collections](https://adamwathan.me/refactoring-to-collections/)**
  — Adam Wathan. The loops-to-pipelines material in `refactoring`.
- **[Refactoring UI](https://refactoringui.com)** — Adam Wathan & Steve Schoger.
  Nearly all of `ui-design`.

Also drawn on throughout: Kent Beck's *Implementation Patterns*, Martin Fowler's
*Refactoring*, Hunt & Thomas's *The Pragmatic Programmer*, and Rob Pike's notes on
complexity.

Buy the books. They contain the reasoning, the worked examples, and — in Refactoring
UI's case especially — before/after imagery that no text summary can replace. These
skills are a working reference for an AI assistant, not a substitute for reading them.

## License

[MIT](LICENSE) for the contents of this repository.
