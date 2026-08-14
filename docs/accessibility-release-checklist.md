# Accessibility release record

Automated tests are necessary but do not constitute the manual screen-reader
pass required by [accessibility.md](accessibility.md). Copy the table below into
the release issue for every release that changes a user flow. A blank or failed
row blocks production release.

| Check | Environment | Result | Evidence / notes |
|---|---|---|---|
| Complete portal traversal without a mouse | Current Chrome | Pending | |
| Sign-in, validation errors, wizard status, upload status, and submission announcements | NVDA + current Firefox | Pending | |
| Same critical announcements and dialog labels | VoiceOver + current Safari | Pending | |
| Dialog open, trap, Escape, close, and focus restoration | NVDA and VoiceOver | Pending | |
| Reflow at 400% / 320 CSS px with no two-dimensional scrolling | Current Chrome and Safari | Pending | |
| Text spacing override and 200% zoom | Current Chrome | Pending | |
| Forced-colours or high-contrast mode | Windows High Contrast | Pending | |
| Reduced motion | Current Safari or Chrome | Pending | |

Record tester, date, commit, operating-system version, browser version, and
assistive-technology version with the completed table. Automated CI covers
WCAG-tagged axe scans, contrast tokens, reflow, and dialog keyboard mechanics;
the release record covers announcement quality and real assistive technology.
