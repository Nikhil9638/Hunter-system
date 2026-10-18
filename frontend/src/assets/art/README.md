# App artwork: provenance

These files are imported from JSX or CSS. Vite emits them as hashed `dist/static/<name>-<hash>.webp`, which the existing Apache rules already allow (`static/*.webp`). **Nothing is loaded from `reference/` at runtime.**

**Rights:** fan reference art supplied by the user for a **local college demo only**. Replace it with original or licensed artwork before any public deployment. The landing footer carries an attribution and "not affiliated" notice.

**Method** (2026-10-18, Phase 2; no new dependencies):
1. Crop, rotate and resize with Windows' built-in `System.Drawing` (PowerShell, `HighQualityBicubic`) into lossless PNG intermediates outside the project.
2. Encode WebP with the installed Chrome (`canvas.toBlob('image/webp', 0.86)`) through the existing `playwright-core` dev dependency.
3. Inspect every file visually.

Pixels are **not** colour-graded. Theme grading is done in CSS overlays (spec §2.3).

| Output | Size | Bytes (≈) | Source | Source SHA-256 | Operation | Used in |
|---|---|---|---|---|---|---|
| `hero-awakened-hunter.webp` | 560×735 | 44 KB | `reference/12_hero_arise_jinwoo_weapon.jpg` (copy of `reference/1057008975069959755.jpg`, 735×1319, stored rotated 90°) | `36899be9b37a3c47ef47757fdf47121e8935afd9a3a4257f4be8d0289d48ef5c` | Crop x0 y0 w735 h560 → rotate 90° CCW. Excludes all "ARISE / SOLO LEVELING" lettering, which starts at about x 585 of the rotated image. | Landing hero (Phase 3); decorative rank art for C–S (Phases 5–6) |
| `hero-awakened-hunter-sm.webp` | 400×525 | 27 KB | same | same | As above, scaled to 400×525 | `srcset` for narrow screens |
| `exp-e-sung-jinwoo.webp` | 480×640 | 23 KB | `reference/reference/04_rank_E_sung_jinwoo_portrait.jpg` (736×1307) | `a9159145fb22990124585cb6f5afdfc592804b521a2115c7ef91dc118f7c341d` | Crop x0 y40 w736 h981 (3:4) → 480×640 | Experience card E (Phase 4); decorative rank art for E–D |
| `exp-c-song-chi-yul.webp` | 480×640 | 29 KB | `reference/reference/03_rank_C_song_chi_yul.jpg` (736×1308) | `d87f929a9046683d646f066871ca14ea26ed21b11641569dc16dac55d1dc6c2d` | Crop x0 y0 w736 h981 → 480×640 | Experience card C |
| `exp-a-woo-jin-chul.webp` | 480×640 | 16 KB | `reference/reference/02_rank_A_woo_jin_chul.jpg` (735×1392) | `a98c91dea5c74e686fff9ea743598d0cf4109cba68adee66fd5a8eb5446a38a8` | Crop x0 y40 w735 h980 → 480×640 | Experience card A |
| `exp-s-cha-hae-in.webp` | 480×640 | 33 KB | `reference/reference/01_rank_S_cha_hae_in.jpg` (736×1700) | `2d83f8a993aee61583d2fa31b8bcb8f353a2a60eef668beca943e5252268f992` | Crop x0 y10 w736 h981 → 480×640 | Experience card S |

**Output SHA-256** (to detect accidental edits):

```
2013f7732c84fe4e7b8ab62e475291defe1535ad8e60ac5a9af7951391b43a9d  hero-awakened-hunter.webp
2e48ba81d5549d5f58889f00d37f685ee80b8794968a52c388cd4c5bffedaa0c  hero-awakened-hunter-sm.webp
89cdc7c2a4c4f3e1071c3f16ab8e550907cf07b663766a88cbeeb604dce7ef53  exp-e-sung-jinwoo.webp
93f6ff68eee322e85c73a20075031ec1eba8ddda932a9a46a66af75cbf1bcb1a  exp-c-song-chi-yul.webp
d9c8461b8b9352a37bbc63954eae25888d32ea3f19e85484f69e4aa57ebfd1c4  exp-a-woo-jin-chul.webp
17c452f7471a3eb3d4fa6b3295e1606d1f27d2ff3bf7f39825661e0beafe9685  exp-s-cha-hae-in.webp
```

**Known limits:**
- The hero is low resolution. Display it at most at its native 560×735 CSS px; it's slightly soft on 2× screens.
- The hero palette is red-leaning.
- Three portraits have light backgrounds. Card frames apply a dark vignette in CSS.

**Not shipped:** the reference video, references 05–09 and 11, the unused daylight Jinwoo (13), and the full ARISE poster.
