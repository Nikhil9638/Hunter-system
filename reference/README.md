# Hunter System frontend reference assets

This folder is for local design reference only. It contains the user's supplied character images, Solo Leveling UI screenshots, stats and card examples, and one short video.

**Private.** `reference/.htaccess` (`Require all denied`) and the root rewrite rules both block HTTP access. Nothing here is imported at runtime. Art used by the app is copied and converted into `frontend/src/assets/art/`, with provenance recorded in that folder's `README.md`.

## Asset map

1. `01_rank_S_cha_hae_in.jpg`: character portrait; visual reference for the S-Rank experience card.
2. `02_rank_A_woo_jin_chul.jpg`: character portrait; visual reference for the A-Rank experience card.
3. `03_rank_C_song_chi_yul.jpg`: character portrait; visual reference for the C-Rank experience card.
4. `04_rank_E_sung_jinwoo_portrait.jpg`: young Sung Jinwoo portrait; visual reference for the E-Rank experience card.
5. `05_landing_page_solo_leveling_ui_inspiration.jpg`: main landing-page composition reference. It's a screenshot or mockup, not a standalone hero image, so don't embed the whole screenshot in the finished page.
6. `06_neon_notification_hud.jpg`: neon-blue HUD and notification panel reference (stored rotated 90°).
7. `07_igris_stats_dashboard_reference.jpg`: hunter stats and status dashboard reference.
8. `08_shadow_roster_badge_grid.jpg`: shadow and character grid reference for the badge store (carries a third-party watermark).
9. `09_character_card_collection_reference.jpg`: framed character-card grid reference.
10. `10_gaming_landing_page_style_reference.mp4`: short video reference. It shows a cinematic purple and magenta gaming site. **Its final frames are an unrelated course advert.** Keep it out of every build.
11. `11_video_style_contact_sheet.jpg`: contact sheet of sample frames from the video.
12. `12_hero_arise_jinwoo_weapon.jpg`: *added 2026-10-18 (Phase 2).* An "ARISE" poster stored rotated 90°. Jinwoo holds a katana, with Igris behind him. Source of the landing hero derivative; the crop excludes all poster lettering.
13. `13_young_jinwoo_daylight_unused.jpg`: *added 2026-10-18 (Phase 2).* Young Jinwoo on a daylight street. Not used (tonally wrong for the theme).

## Path correction (2026-10-18, copy-only)

The pack was originally extracted one level too deep. Its numbered files and the original README are in `reference/reference/`. To fix that without destroying anything, they were **copied** up into this folder and verified byte-identical with `cmp`. Files 12 and 13 are copies of the two unique, originally named files below.

Nothing was moved or deleted. Still present until the user decides otherwise:
- `reference/reference/`: the original numbered set and the original README, untouched.
- The originally named files at this level. Ten are byte-identical (SHA-256) duplicates:

| Originally named file | Identical to |
|---|---|
| `Cha hae-in.jpg` | `01_rank_S_cha_hae_in.jpg` |
| `CHIEF WOO JIN-CHUL.jpg` | `02_rank_A_woo_jin_chul.jpg` |
| `Song Chi-Yul _ Solo Leveling.jpg` | `03_rank_C_song_chi_yul.jpg` |
| `download (1).jpg` | `04_rank_E_sung_jinwoo_portrait.jpg` |
| `𝐒𝐮𝐧𝐠 𝐉𝐢𝐧-𝐰𝐨𝐨 _ 𝐒𝐨𝐥𝐨 𝐋𝐞𝐯𝐞𝐥𝐢𝐧𝐠 _ Wallpaper.jpg` | `05_landing_page_solo_leveling_ui_inspiration.jpg` |
| `Solo Leveling _ Ore dake Level Up na Ken.jpg` | `06_neon_notification_hud.jpg` |
| `Igris – Shadow Knight Stats _ Solo Leveling Character Evolution.jpg` | `07_igris_stats_dashboard_reference.jpg` |
| `download.jpg` | `08_shadow_roster_badge_grid.jpg` |
| `Solo Leveling All Main Characters Tier List _ … (1).jpg` | `09_character_card_collection_reference.jpg` |
| `From Klickpin.com- … .mp4` | `10_gaming_landing_page_style_reference.mp4` |
| `1057008975069959755.jpg` (unique) | copied as `12_hero_arise_jinwoo_weapon.jpg` |
| `Solo Leveling Seung Jin Woo.jpg` (unique) | copied as `13_young_jinwoo_daylight_unused.jpg` |

Removing the duplicates or the nested folder is a separate step that needs the user's approval. Even then they remain recoverable from Git and the external backups.

## Important implementation notes

- Keep this folder out of the frontend runtime bundle.
- It is protected from direct Apache HTTP access (this project is under `htdocs`).
- Only selected, converted art is copied to `frontend/src/assets/art/`.
- Confirm rights before any public deployment; use licensed or original character artwork if these images can't be published.
