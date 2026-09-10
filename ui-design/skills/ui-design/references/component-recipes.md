# Component recipes

The general rules applied to components people build most often.

These describe *relationships* — which element is primary, which spacing is tighter
than which — not specific values. Take every actual number from the project's own
scale, as SKILL.md's first section requires.

## Card

- Padding from the project's spacing scale — a mid step for compact cards, one or two
  steps up for roomy ones. The same value on all four sides unless there's a reason.
- Space between the card's own sections must be *smaller* than the space between
  cards. Get this backwards and the cards read as one blob.
- Title is usually **not** the focus — the content is. A small, possibly grey title
  above prominent content beats a big bold heading above small grey text.
- One border *or* a background difference *or* a shadow. Not two, never three.
- Low elevation. A card is barely off the page; save large shadows for modals.

## Button

- Padding is not proportional to font size. Whatever the base button uses, the large
  variant wants proportionally *more* padding and the small one proportionally less —
  otherwise the sizes look like zoom levels of each other rather than distinct sizes.
- Primary, secondary, tertiary need visibly different weight, not three different
  colors. Solid fill → subtle fill or outline → plain text.
- Destructive actions get a semantic red, but never red alone — the label must say
  "Delete".
- Press state: shrink or remove the shadow so it feels pushed in.

## Form

- The ambiguous-spacing trap lives here. Label→input gap must be clearly tighter
  than input→next-label gap. When in doubt, halve the inner gap.
- Help text belongs to its field: tight against it, styled as tertiary.
- Errors need an icon or text, not just a red border.
- Inputs are inset — a subtle inner shadow at the *top* edge, if any.
- Custom checkboxes and radios in a brand color are the cheapest polish available.

## Table

- Column headers are labels: small, secondary, often uppercase with letter-spacing.
  The *data* is the focus.
- Merge columns that don't need sorting — a name and email in one cell with the name
  emphasized reads better than two flat columns.
- Row separation: alternating background or a very light border, not both. Often
  spacing alone is enough.
- Numeric columns right-align; text columns left-align.

## Dropdown / menu

- Medium elevation — above the page, below a modal.
- It is just a floating box. Sections, icons, supporting descriptions, and multiple
  columns are all allowed.
- Active item: don't only brighten it — soften the inactive items (emphasize by
  de-emphasizing).

## Modal

- Largest shadow in the system, plus a backdrop.
- Its title *is* usually the focus, unlike a card's.
- Primary and cancel actions must differ in weight, and cancel should be quiet.

## Empty state

Never a blank panel. Minimum viable version:

- An illustration or a large icon
- One line saying what goes here
- One primary button that creates the first item

This is the first screen every new user sees. Budget for it at design time, not
after launch.

## Stat / metric tile

- The number is primary: large, heavy, dark. The label is tertiary.
- Trend indicators need an arrow or a "+"/"−", not just green/red text.
- On a row of tiles, keep number sizes identical even when digit counts differ.

## Nav

- Active item stands out by de-emphasizing the rest, plus an accent border on the
  active one.
- Nav is chrome, not content — it should be quieter than the page it frames.
