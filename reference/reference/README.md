# Hunter System frontend reference assets

This folder is for local design reference only. It contains the user's supplied character images, Solo Leveling UI screenshots, stats/card examples, and one short video.

## Asset map

1. `01_rank_S_cha_hae_in.jpg` — character portrait used as the visual reference for the S-Rank experience card.
2. `02_rank_A_woo_jin_chul.jpg` — character portrait used as the visual reference for the A-Rank experience card.
3. `03_rank_C_song_chi_yul.jpg` — character portrait used as the visual reference for the C-Rank experience card.
4. `04_rank_E_sung_jinwoo_portrait.jpg` — young Sung Jinwoo portrait used as the visual reference for the E-Rank experience card.
5. `05_landing_page_solo_leveling_ui_inspiration.jpg` — main landing-page composition reference featuring a character-centered Solo Leveling UI and Jinwoo with a weapon. This is a screenshot/mockup, not a standalone hero image; do not embed the whole screenshot inside the finished page.
6. `06_neon_notification_hud.jpg` — neon-blue HUD/notification panel reference.
7. `07_igris_stats_dashboard_reference.jpg` — hunter stats/status dashboard visual reference.
8. `08_shadow_roster_badge_grid.jpg` — shadow/character grid reference for a badge/artifact store.
9. `09_character_card_collection_reference.jpg` — framed character-card grid reference.
10. `10_gaming_landing_page_style_reference.mp4` — short video reference. Sampled scenes show a cinematic purple/magenta gaming-site design with a hero and distinct sections for about/story, missions, game cards, recruitment/CTA sections. Adapt the atmosphere and section storytelling to Python learning; do not copy unrelated site content.
11. `11_video_style_contact_sheet.jpg` — contact sheet of sample frames from the video for quick visual inspection.

## Important implementation notes

- Keep this folder out of the frontend runtime bundle.
- Protect it from direct Apache HTTP access (this project is under `htdocs`).
- Only copy selected art to the frontend's public assets if actually needed.
- Confirm rights before public deployment; use licensed or original character artwork if the current images cannot be published.
