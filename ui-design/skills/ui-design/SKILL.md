---
name: ui-design
description: Practical visual design rules for building app UI — type scale, spacing system, visual hierarchy, color shades, depth and shadows, empty states. Use when writing or reviewing UI markup and styles (HTML/CSS, Tailwind, Blade, JSX, Vue), when building a component or page, when picking font sizes, spacing, or colors, or when asked why a UI looks off, unpolished, or generic.
---

# UI design

Practical visual design for **application UI in a codebase**. (For a published
Claude Artifact, prefer the `artifact-design` skill; for charts, `dataviz`.)

This skill is about consistency with the project's own design system. For areas it
doesn't cover, such as motion, interaction states, UX copy or responsive behavior,
consider the [impeccable](https://impeccable.style) skills if they're installed.
Where impeccable pushes for a distinctive look, the project's system still wins.

## Start here

Most "this looks bad and I don't know why" comes from three things, in order:

1. **No hierarchy** — everything competes for attention.
2. **No system** — arbitrary font sizes and spacing values, chosen one pixel at a time.
3. **Not enough white space** — elements crowded because the space was filled.

Fix those before reaching for gradients, shadows, or illustrations.

## First: read the project's system, don't bring your own

**Before writing a single value, find what the project already defines and use it.**
A value that isn't in the project's system is a bug, even if it's a "good" value in
the abstract.

Look, in this order, and stop at the first that exists:

1. **A theme/token declaration** — `@theme { }` in the main CSS, `tailwind.config.*`,
   `:root { --color-*, --space-*, --font-size-* }`, a `tokens.{json,ts,scss}`, or a
   design-system package the app imports.
2. **A component library's tokens** — shadcn/ui, Radix, Material, Bootstrap, or an
   in-house kit. Its scale is the project's scale.
3. **The existing code** — grep a few representative components for the spacing,
   font-size, and color values actually in use, and infer the working set.

Then:

- **Use the project's names, not raw values.** `p-4`, `text-sm`, `bg-surface-2`,
  `var(--space-3)` — whatever the project speaks. Raw values (`p-[13px]`,
  `#3b82f6`, `margin: 17px`) are the thing to avoid, whatever the framework allows.
- **Its shade naming wins.** 50–950, 100–900, `light`/`DEFAULT`/`dark`, or semantic
  names like `surface`/`muted`/`accent` — match it. Don't renumber a palette to fit
  a book.
- **Need a value the system lacks?** Say so and propose adding it to the theme, or
  pick the nearest existing value. Don't quietly introduce a one-off.
- **Only when there is genuinely no system** — greenfield, or a project with no
  discernible convention — establish one using the rules below, and say that you did.

The rules in the rest of this skill are about *how a good system behaves*. They tell
you whether a project's system is sound and how to build one that's missing. They are
not a set of values to paste over what a project already has.

## What a good system looks like

Use these to judge an existing system, or to build one where none exists.

**A fixed set, chosen up front.** The failure mode is nitpicking 120px vs 125px —
slow to decide, inconsistent in the result. Constraint is the feature.

**Non-linear steps.** At the small end a few pixels matter a lot (12→16px is a 33%
jump); at the large end they're imperceptible. **No two adjacent values closer than
~25%.** A linear "everything is a multiple of 4px" scale fails this and doesn't
actually make the choice easier.

**No compounding units for type.** `px` or `rem`, not `em` — `em` compounds, so
`.875em` inside `1.25em` computes to 17.5px, a value in nobody's scale.

**No proportional scaling.** Large elements shrink faster than small ones on small
screens: 45/18px on desktop is a 2.5 ratio, but at 14px body copy the headline wants
20–24px — a 1.5 ratio, so there was never a fixed relationship to encode. Same inside
a component: a large button wants *disproportionately* more padding, or it just looks
zoomed.

**Line-height inverse to font size, proportional to line length.** Small text and
wide columns need more; large headlines can sit at 1.0.

### Starting values, only if the project has none

Not defaults to impose — a reasonable first draft when you're establishing a system
from scratch. Most mature frameworks ship something equivalent; prefer theirs.

```
spacing   4  8  12  16  24  32  48  64  96  128  192  256  384  512  640  768
type      12  14  16  18  20  24  30  36  48  60  72
```

## Hierarchy

**Not everything is equal.** Decide primary / secondary / tertiary for every element
before styling it.

- **Size isn't the only tool** — and leaning on it gives you huge primary text and
  unreadable secondary text. Use **weight** and **color** instead.
- **Two font weights** is usually enough: 400/500 for body, 600/700 for emphasis.
  **Never go below 400** in UI. To de-emphasize, use a lighter *color* or smaller
  size — not a lighter weight.
- **Three text colors**: dark for primary, grey for secondary, lighter grey for
  tertiary.
- **Emphasize by de-emphasizing.** When the important element won't stand out, stop
  adding weight to it and soften everything competing with it.
- **Separate visual from document hierarchy.** An `h1` is a semantic choice, not a
  size. Section titles usually act as labels and should be *small* — sometimes
  visually hidden entirely, with the content as the focus. Pick the tag for meaning,
  style it for hierarchy.
- **Labels are a last resort.** `janedoe@example.com` needs no "Email:" label — the
  format says it. Fold the label into the value where you can: "In stock: 12" →
  "12 left in stock"; "Bedrooms: 3" → "3 bedrooms". When you do need labels (dense,
  scannable data), style them as secondary. Emphasize the *label* over the value only
  when users scan for the label — spec tables, where someone looks for "Depth", not
  "7.6mm".
- **Make state unmistakable, and show it the same way everywhere.** An active card,
  a selected row, or the current nav item should be obvious at a glance, and every
  "selected" in the app should use the same treatment. The treatment itself comes
  from the project's design; this skill only asks that there is one and that it's
  used consistently.

## Layout and spacing

- **Start with too much white space, then remove.** The reverse never happens.
- **You don't have to fill the screen.** If the content needs 600px, use 600px.
- **Avoid ambiguous spacing.** Space *inside* a group must be smaller than space
  *between* groups — a label sitting equidistant between two fields belongs to
  neither. This one rule fixes a surprising number of "off" forms.

## Text

- **45–75 characters per line** (roughly `20–35em`). Cap paragraph width even when
  the content column is wider for other elements.
- **Line-height is proportional to line length, inverse to font size.** Narrow
  columns ~1.5, wide columns up to 2; large headlines can go to 1.
- **Baseline-align mixed font sizes on one line**, not center.
- **Not every link needs a color.** In link-dense UI, a heavier weight or darker
  color is enough; truly ancillary links can reveal themselves on hover only.

## Color

- **Author color in the project's color space.** When there's none yet, use OKLCH:
  equal lightness values look equally light, so shade steps come out even. Avoid raw
  hex for anything you'll need to derive shades from.
- **You need far more colors than five.** A real palette is 8–10 greys, 1–2 primaries
  with 5–10 shades each, plus accent colors for semantic states (red destructive,
  yellow warning, green positive) with their own shades. A complex UI can need ten
  colors at 5–10 shades.
- **Define shades up front. Never use `lighten()`/`darken()` at the call site** —
  that's how you get 35 nearly identical blues. Method: pick the **base** (one that
  works as a button background), the **darkest** (for text), and the **lightest**
  (for tinted backgrounds), then bisect the gaps until you have enough. Bisecting is
  what keeps the steps perceptually even — don't interpolate mathematically and
  don't add shades ad hoc later.
- **In an HSL palette, increase saturation as lightness moves away from 50%**, or
  light and dark shades look washed out. OKLCH handles most of this for you.
- **In an HSL palette, rotate hue to change brightness** when saturation is maxed:
  toward 60°/180°/300° to lighten, toward 0°/120°/240° to darken. Stay within 20–30°
  or it reads as a different color.
- **Greys shouldn't be 0% saturation.** Tint blue for a cool UI, yellow/orange for a
  warm one. Keep the tint consistent across the whole scale.
- **Never grey-out text on a colored background**, and don't just drop opacity —
  it looks faded and lets patterns show through. Hand-pick a color at the same hue
  with adjusted saturation/lightness.
- **Contrast: 4.5:1** for text under ~18px, **3:1** above. When white-on-color forces
  a background so dark it wrecks hierarchy, **flip the contrast**: dark colored text
  on a light colored background.
- **Never encode meaning in color alone.** Add an icon, a label, or rely on
  light/dark contrast — colorblind users can read contrast far more easily than hue.

**Reading the project's palette.** Count the shades and note the color space before
you use them — both vary by framework and version, and they change what advice
applies:

- **How many steps, and what are they called?** Nine (100–900) and eleven (50–950)
  are both common; so are `light`/`DEFAULT`/`dark` and semantic names. Use the
  project's. If it has a 50 and a 950, those exist for barely-tinted backgrounds and
  near-black text — reach for them rather than inventing a lighter or darker one.
- **What color space?** If the palette is authored in a perceptually uniform space
  (OKLCH/LCH — increasingly the default in current frameworks), equal lightness steps
  already *look* equal, and much of the "increase saturation away from 50% lightness"
  correction is handled for you. In plain HSL or hex it is not, and that rule applies
  in full.
- **Extend, don't replace.** A missing shade should be added to the theme under the
  project's naming, not hardcoded at the call site.

## Depth

- **Light comes from above.** Raised elements: lighter top edge, shadow below. Inset
  elements: shadow at the top. This one rule is the whole effect.
- **Shadows mean elevation, and elevation means attention.** Small/tight for buttons,
  medium for dropdowns, large for modals. Use a **small fixed set** (about five, or
  the framework's named steps if it has them) and stop. Choose by asking
  where the element sits on the z-axis, not by how the shadow looks.
- **Two-part shadows**: a large soft one with vertical offset (cast shadow) plus a
  tight dark one with little offset (ambient occlusion). The tight one should be
  distinct at low elevation and nearly gone at high elevation — things far from a
  surface lose their contact shadow.
- **Shadows work as interaction feedback**: grow on drag, shrink or vanish on press.

**Check before building an elevation system — you probably already have one.** Most
CSS frameworks ship a named shadow scale, and the good ones already implement the
two-part rule (mid-range shadows defined as two layers, the largest collapsing to
one, which is exactly the "contact shadow disappears at high elevation" behavior).
Where that's true, the named steps *are* your elevation system; map components onto
them (button → small, dropdown → medium, modal → largest) instead of writing custom
`box-shadow` values.

## Finishing touches

Only after hierarchy, spacing, and type are right:

- **Supercharge the defaults** — icons instead of bullets, oversized colored quote
  marks, custom checkboxes/radios in a brand color, styled link underlines.
- **Decorate backgrounds** — a different background color per section, a subtle
  gradient (hues within ~30°), a low-contrast repeating pattern, or a simple
  geometric shape. Keep contrast low so content stays readable.
- **Use fewer borders.** Before adding one, try a box shadow, two different
  background colors, or simply more space. If you have both a border and differing
  backgrounds, delete the border.
- **Don't overlook empty states.** For anything driven by user content, the empty
  state is the *first* thing a user sees. Give it an illustration and a clear call
  to action; never ship a blank panel.
- **Think outside the box.** A dropdown is just a floating box — it can have
  sections, columns, icons, supporting text. Table columns that don't need sorting
  can be merged to create hierarchy.

## Reviewing existing UI

Walk it in this order and stop at the first thing that's wrong — later items rarely
matter while an earlier one is broken:

1. Is there a clear primary element? (hierarchy)
2. Is every font size, spacing value, and color drawn from the project's own
   scale — and named, not raw? (system)
3. Is grouping unambiguous — inner space < outer space?
4. Is text 45–75 characters, with line-height matched to its width?
5. Do the greys have a consistent temperature; does text meet 4.5:1?
6. Do shadows reflect a consistent elevation system?
7. Are there borders that a background change or extra space would do better?
8. Is there an empty state?

`references/component-recipes.md` has the checks applied to specific components.
`references/framework-notes.md` records what current framework defaults happen to be
— dated, and subordinate to whatever the project defines.

## A note on durability

This skill deliberately contains almost no fixed values. Design-system defaults move
between framework versions — shade counts, spacing scales, and shadow steps have all
changed recently — and any project may reasonably define its own. The rules here are
about *relationships*: non-linear steps, ~25% minimum gaps, inverse line-height,
contrast ratios, light from above. Those hold regardless of the palette or the
framework. When a rule below seems to conflict with the project's system, the
project's system wins — flag the conflict, don't silently override it.
