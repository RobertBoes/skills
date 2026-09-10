# Framework notes

> **Dated reference — verified September 2026 against Tailwind v4.x docs.**
> These are observations about what one framework happened to ship, not values to
> apply. The project's own theme always wins (see "First: read the project's system"
> in SKILL.md). **Re-verify before relying on any of it** — every item here has
> already changed at least once.

Kept because knowing what a framework's defaults *are* helps you tell whether a
project has customized them, and because two of these confirm rules in the skill.

## Tailwind, as of v4.x

**Type scale — matches the widely-used hand-picked scale exactly.**
`text-xs` … `text-7xl` = 12, 14, 16, 18, 20, 24, 30, 36, 48, 60, 72px, continuing to
`text-8xl` 96 and `text-9xl` 128. This one has been stable across versions.

**Line-height is pre-paired, and pairs correctly.** Roughly 1.5 at 16px, decreasing
to a flat 1.0 from `text-5xl` (48px) up — the inverse relationship the skill
recommends, already built in. Override with `text-sm/6` syntax only when line length
demands it.

**Spacing is no longer a fixed scale.** v4 generates every step from a single
`--spacing` variable: `calc(var(--spacing) * n)`, default `0.25rem`. So `p-13` and
`p-27` are legal, on-scale utilities — the framework stopped enforcing a constrained
set. This is the single most important drift for this skill: **the discipline of
sticking to a scale is now entirely the author's job.** Set the base in
`@theme { --spacing: … }`; keep to sensible multiples by convention.

**Color: 11 shades, 50–950, in OKLCH.** Not the 9-step 100–900 that older design
writing assumes. The extra ends are genuinely useful — 50
for barely-tinted backgrounds, 950 for near-black text. Because OKLCH is
perceptually uniform, equal lightness steps look equal, which handles much of the
"increase saturation as lightness moves away from 50%" correction automatically —
that rule was written for HSL and applies in full only there. Override via `@theme`
using the same names; `--color-*: initial` clears the defaults entirely.

**Shadows already implement the two-part rule.** `shadow-sm` through `shadow-xl` are
each defined as two layers (cast shadow + tight contact shadow), and `shadow-2xl`
collapses to a single layer — exactly the "contact shadow disappears at high
elevation" behavior the skill describes. The named steps (`2xs xs sm md lg xl 2xl`)
are a ready-made elevation system; map components onto them rather than writing
custom `box-shadow`.

## What to check when a project uses something else

- **Bootstrap / Bulma / Foundation** — SCSS variables, usually `$spacer` multiples
  and a `$font-size-base` with ratio-derived headings. Ratio-derived scales often
  violate the ~25% gap rule at the small end; note it, work within it.
- **Material / MUI** — an 8px spacing unit and a documented elevation scale (0–24).
  The elevation system is unusually explicit; use its levels directly.
- **shadcn/ui + Radix** — semantic CSS custom properties (`--background`,
  `--muted`, `--accent`) rather than numbered shades. Match the semantic names; do
  not translate them back into a numeric palette.
- **No framework** — look for `:root` custom properties before assuming there's no
  system. A handful of `--space-*` / `--color-*` declarations is a system.
