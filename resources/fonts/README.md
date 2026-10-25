# Server-side fonts

`DejaVuSans-Bold.ttf` — used by GD to draw the profile-code watermark on full-size photos (M11).
DejaVu fonts are free to use and redistribute (Bitstream Vera / Arev licence, public-domain changes:
https://dejavu-fonts.github.io/License.html). Copied from dompdf's bundled fonts.

`NotoSans-Regular.ttf`, `NotoSans-Bold.ttf`, `NotoSansMalayalam-Regular.ttf`, `NotoSansMalayalam-Bold.ttf`
— the bureau biodata PDF (§11A B.7, P7.4: "Malayalam-capable font"), embedded by dompdf. Noto fonts
are under the SIL Open Font License 1.1 (https://openfontlicense.org), free to embed and
redistribute. From https://github.com/notofonts (hinted TTF). dompdf has no complex-script shaping,
so Malayalam conjuncts render as separate glyphs until a shaping renderer replaces it
(decisions 2026-10-25).
