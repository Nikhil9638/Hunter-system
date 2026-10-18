# Updated badge decisions — names/prices approved, not on sale

Current authoritative manifest and review/contact sheets use Shadow Recruit10,
Shadow Soldier15, Shadow Knight20, Elite Shadow Knight30, Shadow Commander45 and
Shadow Monarch60 (total180). Original geometry is retained, with updated SVG labels
and new m8b-*-v2.png exports. User approval is separate from an authenticated price-approval
event. Sample retirements are authorized but not executed: no authentic session is available
and MariaDB is stopped. No dev catalogue writes or badge sale occurred.

Current checks: six PNGs and18 preview images at1280/390px verified. Earlier lifecycle
reports and store/profile screenshots are historical original-proposal evidence; do not
count their54 checks as current revised-content integration evidence. The lifecycle runner
now expects45 coins spent on the first three badges, but live rerun is blocked.
See docs/administrator-content-access.md for the backup-first interactive batch and
fixture rehearsal commands. Notes below describe the earlier preparation workflow;
this decision update overrides prior price alternatives and pending-retention choices.

Open `review.html` locally for the responsive proposal page, or view
`proposal-1280.png` / `proposal-390.png` for contact sheets. Store/profile screenshots
show the proposals in the actual application on an isolated fixture. These review
files and editable SVG sources are private and blocked over HTTP. Only six intended
512×512 transparent PNGs are served from `assets/badges/m8b-*-v1.png`.

## Options for review

| Approved title | Approved coins |
|---|---:|
| Shadow Recruit | 10 |
| Shadow Soldier | 15 |
| Shadow Knight | 20 |
| Elite Shadow Knight | 30 |
| Shadow Commander | 45 |
| Shadow Monarch | 60 |
| **Total — approved** | **180** |

Current rewards are 10 coins once per topic completion, across 18 topics: 180 maximum.
The former240-coin proposal is superseded. The approved180-coin set
allows all six only if a student completes all topics and saves all earnings. Buying
other badges consumes that same budget. There are no repeat-completion earnings;
rewards and product rules were not changed. Names/prices are user-approved; no dev
price-approval event was created. V1 exports remain only for historical fixture references.

Each description explicitly says the item is a cosmetic purchase, not an earned
achievement or skill certification, and does not unlock lessons or increase rank.
Names describe decorative themes. No E–S rank letters, copied logos, anime characters
or third-party artwork were used. Six original geometric motifs share a metallic frame
with cyan/violet accents. `build_proposal.py` is the editable authoring source and
`sources/*.svg` are editable vector outputs, never app-served artwork.

## Reproduce artwork and checks

From the project root:

```powershell
python content/badges/build_proposal.py
Set-Location frontend
node tests/badge-artwork.mjs
```

The installed Chrome renders original vectors to PNG. The exporter verifies PNG signatures,
512×512 dimensions, fully transparent corners, substantial transparent/opaque regions and
antialiased edge pixels. Responsive review page checks cover all 18 images (160/64/32px
per design) at desktop 1280px and mobile 390px with no overflow. Private reports record
actual image dimensions/bytes/alpha counts and installed Chrome version.

For real integration, start the existing complete fixture backend on 8098 using
`docs/milestone-7-verification.md`. It must attest `hunter_system_m3_fixture` with
restricted `hunter_m7_fixture`. There is no target URL override or dev fallback.

```powershell
# From project root, before a new rehearsal; do not overwrite an existing baseline:
$env:HUNTER_PRIVATE_DIR = Join-Path $env:TEMP 'HunterSystem-M7-Verification\complete'
$env:HUNTER_FIXTURE_PRIVATE_DIR = $env:HUNTER_PRIVATE_DIR
# C:\xampp\php\php.exe content/badges/fixture_state.php capture
Set-Location frontend
node tests/badge-proposal-journeys.mjs
Set-Location ..
C:\xampp\php\php.exe content/badges/fixture_state.php verify
```

This rehearsal reuses the existing M7 **fixture-only** administrator (recorded in
`lifecycle-validation.json`). It creates no administrator and performs no dev writes.
Its known test password applies only to that isolated fixture actor; never use it for a
real account. Each run creates uniquely slugged `fx-m8b-...` catalogue rows and a labelled
test student. Catalogue writes go through existing authenticated CSRF-protected APIs
with exact versions and transactional actor auditing. Six proposal prices are approved
and put on sale on the fixture only. Price invalidation is exercised; three badges are
purchased through the real UI with 60 coins earned from six actual quizzes, then showcased.
All six are left **off sale** afterwards; owned badges, attempts and audit rows are retained.
No root credentials are provided to browser tests, no fake ledger credits or history bypass.

Checks: 54 real API/Chrome assertions, including 64px artwork on desktop/mobile store,
profile showcase rendering/order, actual purchases, unchanged rank/progress, audit events,
six static PNG responses and four private-path rejections. No mocked API evidence is counted.
Existing fixture badge/audit rows are fingerprinted for preservation across the final rehearsal.
Final preservation checks: 1,007 existing fixture badge/audit rows and all 11,735 original
course-history rows unchanged; zero M8B fixture badges remain on sale. IR-1 remains intact.
Dev badge/audit and course fingerprints before/after are in `preservation-report.json`.

## Review decisions

- Approve or revise the six names and cosmetic descriptions.
- Approve or revise artwork; inspect 32px and 64px previews and app screenshots.
- Choose proposed 240 total, alternative 180 total, or specify another price set.
- Decide whether to retain the four sample drafts, or replace them with these proposals.
  The existing samples remain draft/provisional; none was retired. Replacement should
  mean separately approved retirement through admin APIs, never deletion of history.
- Identify your authentic administrator separately, review course material, then authorize
  authenticated content import and publication as distinct steps.

No dev catalogue entries, approvals, sales, sample retirements, course imports or administrator
creation happened in this task. Publication and catalogue approval remain user decisions.
See `../../docs/administrator-content-access.md` for interactive administrator setup/import
instructions. Full application, other-browser/accessibility checks and final security review
remain after content review/import/publication.
