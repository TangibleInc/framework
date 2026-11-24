# Onboarder layout rules

How content is laid out on every onboarder step. Written by Cristian (design)
on 2026-10-14 for building plugin-specific screens; each pattern in rule 7
names its reference frame in the Plugin Wizard Figma file.

- Rules (Figma): https://www.figma.com/design/4SUsvcc5JuZl0MNCxQyiXm/Plugin-Wizard?node-id=3953-3418
- Reference screens, Design Upgrade free + Pro: https://www.figma.com/design/4SUsvcc5JuZl0MNCxQyiXm/Plugin-Wizard?node-id=3938-2934

## 1. Shell
- Top bar: 80px tall, white, 1px #CCCCCC line at the bottom, 32px side padding. Plugin logo 32px tall on the left, Exit setup on the right.
- Stepper: 68px tall, white, under the top bar, steps centred.
- Footer: 80px tall, white, 1px #CCCCCC line at the top, at the bottom of the window. Back on the left, actions on the right.
- Page background between them: #F0F0F0.

## 2. Content width
- Steps use a 720px content column, centred.
- A step goes to 1200px only when it puts things side by side: option cards in a row, two panels next to each other, or media beside a form.
- Content keeps at least 32px from the window edge.
- Below 1100px window width, side-by-side blocks stack into one column and the step uses the 720px column.

## 3. Cards
- Content sits in cards on the grey page, never directly on the grey.
- Card: #FFFFFF, 12px corners, 32px padding, no border.
- Card shadow, four layers: 0 4px 4px rgba(0,0,0,0.01), 0 3px 3px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.02), 0 1px 1px rgba(0,0,0,0.03).
- One card per group of related controls. Option cards and notices are never placed inside another card.

## 4. Bands
- A band is a tinted card for an ask or an offer, such as the email sign-up on Done or the usage-data ask on Build.
- Band: #E2E9FF, 12px corners, 24px padding, no border, no shadow.
- A band sits after the step's main content, never first.

## 5. Spacing
- 32px between blocks.
- 32px between cards in a row. Cards in a row share the row's height.
- 64px above the heading and below the last block.
- Inside a card: the label sits above its control, 24px between groups.

## 6. Heading
- The heading and subtitle sit above the cards, 12px apart.
- Heading: 32px, regular weight. Subtitle: 13px.
- They are left aligned, except on Done, where they are centred.

## 7. Patterns

Each pattern names its reference frame in the Plugin Wizard Figma file.

### Welcome
- 1200. A media card (720) beside the form card (448).
- Reference frame: [Core] Free 01 Welcome.

### Short form or credentials
- 720. One card, fields stacked.
- Reference frame: 04 Connect.

### Pick one option
- 1200. Option cards in a row, one marked recommended.
- Reference frame: 03 Backend.

### Two or three short questions
- 1200. One card with the questions in columns.
- Reference frame: [Core] 02 Your site.

### Choose what to include
- One card of toggles. 1200 if there are two groups side by side, otherwise 720.
- Reference frame: 05 Index.

### Something running, with a result
- 1200. Two panels side by side.
- Reference frame: 06 Build.

### Values to show before agreeing
- 720. One card of label and value rows.
- Reference frame: [Core] Free 02 Usage data.

### A warning
- A notice at full column width, right under the heading.
- Reference frame: [Core] Free 03b Done compatibility notice.

### Next steps
- 720 with one card of links, or 1200 with one card per group (see [Core] 09 Done).
- Reference frame: [Core] Free 03 Done.
