# Font Awesome 4.7 → 6 class map (P9.3a)

Icons in this project are **full FA6 classes**: `fa-{solid|regular|brands} fa-{name}` — in Blade
(`<i class="fa-solid fa-heart" aria-hidden="true">`), in component props (`icon="fa-regular fa-bell"`),
and in PHP / config / JS values. There is no bare `fa` class and no FA 4.7 name;
`tests/Feature/Foundation/IconClassesTest.php` enforces it against the installed package's metadata.

How this map was made (2026-11-22, `@fortawesome/fontawesome-free` 6.7.2), not by hand:

- the FA4 name → FA6 style + name comes from Font Awesome's own v4 shim list (`js/v4-shims.js`), which keeps
  each icon's FA 4.7 look — an FA4 outline icon (`-o`, and e.g. `eye`, `credit-card`, `diamond`) is `fa-regular`;
- names FA6 kept only as aliases are replaced by their primary name (`search` → `magnifying-glass`);
- every result is checked to exist, free, in that style (`metadata/icon-families.json`);
- `fa-spin` / `fa-lg` are modifiers and stay.

Picking a new icon: find it on fontawesome.com (version 6, Free), use the style it is free in, and write
both classes. Brands are `fa-brands`.

| FA 4.7 | FA 6 | Uses (at conversion) | Note |
|---|---|---|---|
| `fa-address-book` | `fa-solid fa-address-book` | 3 |  |
| `fa-angle-down` | `fa-solid fa-angle-down` | 2 |  |
| `fa-angle-left` | `fa-solid fa-angle-left` | 10 |  |
| `fa-angle-right` | `fa-solid fa-angle-right` | 10 |  |
| `fa-archive` | `fa-solid fa-box-archive` | 1 | alias archive |
| `fa-arrow-down` | `fa-solid fa-arrow-down` | 1 |  |
| `fa-arrow-left` | `fa-solid fa-arrow-left` | 13 |  |
| `fa-arrow-right` | `fa-solid fa-arrow-right` | 8 |  |
| `fa-arrow-up` | `fa-solid fa-arrow-up` | 2 |  |
| `fa-arrows-v` | `fa-solid fa-up-down` | 1 | FA v4 shim |
| `fa-balance-scale` | `fa-solid fa-scale-balanced` | 3 | alias balance-scale |
| `fa-ban` | `fa-solid fa-ban` | 8 |  |
| `fa-bank` | `fa-solid fa-building-columns` | 5 | FA v4 shim |
| `fa-bar-chart` | `fa-solid fa-chart-column` | 2 | FA v4 shim |
| `fa-bars` | `fa-solid fa-bars` | 3 |  |
| `fa-bell` | `fa-solid fa-bell` | 2 |  |
| `fa-bell-o` | `fa-regular fa-bell` | 9 | FA v4 shim |
| `fa-bell-slash` | `fa-solid fa-bell-slash` | 2 |  |
| `fa-bold` | `fa-solid fa-bold` | 1 |  |
| `fa-bolt` | `fa-solid fa-bolt` | 11 |  |
| `fa-bookmark-o` | `fa-regular fa-bookmark` | 1 | FA v4 shim |
| `fa-briefcase` | `fa-solid fa-briefcase` | 1 |  |
| `fa-building-o` | `fa-regular fa-building` | 4 | FA v4 shim |
| `fa-bullhorn` | `fa-solid fa-bullhorn` | 4 |  |
| `fa-calculator` | `fa-solid fa-calculator` | 1 |  |
| `fa-calendar` | `fa-solid fa-calendar-days` | 1 | FA v4 shim |
| `fa-calendar-plus-o` | `fa-regular fa-calendar-plus` | 1 | FA v4 shim |
| `fa-camera` | `fa-solid fa-camera` | 3 |  |
| `fa-check` | `fa-solid fa-check` | 53 |  |
| `fa-check-circle` | `fa-solid fa-circle-check` | 14 | alias check-circle |
| `fa-check-circle-o` | `fa-regular fa-circle-check` | 2 | FA v4 shim |
| `fa-check-square-o` | `fa-regular fa-square-check` | 4 | FA v4 shim |
| `fa-circle-o` | `fa-regular fa-circle` | 2 | FA v4 shim |
| `fa-clipboard` | `fa-solid fa-paste` | 1 | FA v4 shim |
| `fa-clock-o` | `fa-regular fa-clock` | 14 | FA v4 shim |
| `fa-cloud-upload` | `fa-solid fa-cloud-arrow-up` | 1 | FA v4 shim |
| `fa-coffee` | `fa-solid fa-mug-saucer` | 1 | alias coffee |
| `fa-cog` | `fa-solid fa-gear` | 3 | alias cog |
| `fa-commenting-o` | `fa-regular fa-comment-dots` | 1 | FA v4 shim |
| `fa-comments` | `fa-solid fa-comments` | 2 |  |
| `fa-comments-o` | `fa-regular fa-comments` | 5 | FA v4 shim |
| `fa-compass` | `fa-regular fa-compass` | 1 | FA v4 shim |
| `fa-credit-card` | `fa-regular fa-credit-card` | 5 | FA v4 shim |
| `fa-desktop` | `fa-solid fa-desktop` | 2 |  |
| `fa-diamond` | `fa-regular fa-gem` | 18 | FA v4 shim |
| `fa-download` | `fa-solid fa-download` | 17 |  |
| `fa-ellipsis-h` | `fa-solid fa-ellipsis` | 1 | alias ellipsis-h |
| `fa-envelope` | `fa-solid fa-envelope` | 7 |  |
| `fa-envelope-o` | `fa-regular fa-envelope` | 8 | FA v4 shim |
| `fa-envelope-open-o` | `fa-regular fa-envelope-open` | 3 | FA v4 shim |
| `fa-eraser` | `fa-solid fa-eraser` | 1 |  |
| `fa-exchange` | `fa-solid fa-right-left` | 1 | FA v4 shim |
| `fa-exclamation-triangle` | `fa-solid fa-triangle-exclamation` | 8 | alias exclamation-triangle |
| `fa-eye` | `fa-regular fa-eye` | 15 | FA v4 shim |
| `fa-eye-slash` | `fa-regular fa-eye-slash` | 4 | FA v4 shim |
| `fa-facebook` | `fa-brands fa-facebook-f` | 2 | FA v4 shim |
| `fa-file-archive-o` | `fa-regular fa-file-zipper` | 2 | FA v4 shim |
| `fa-file-code-o` | `fa-regular fa-file-code` | 1 | FA v4 shim |
| `fa-file-excel-o` | `fa-regular fa-file-excel` | 3 | FA v4 shim |
| `fa-file-pdf-o` | `fa-regular fa-file-pdf` | 5 | FA v4 shim |
| `fa-file-text-o` | `fa-regular fa-file-lines` | 11 | FA v4 shim |
| `fa-filter` | `fa-solid fa-filter` | 1 |  |
| `fa-flag` | `fa-solid fa-flag` | 15 |  |
| `fa-flag-o` | `fa-regular fa-flag` | 1 | FA v4 shim |
| `fa-flask` | `fa-solid fa-flask` | 2 |  |
| `fa-gavel` | `fa-solid fa-gavel` | 2 |  |
| `fa-gift` | `fa-solid fa-gift` | 2 |  |
| `fa-globe` | `fa-solid fa-earth-americas` | 1 | FA v4 shim |
| `fa-handshake-o` | `fa-regular fa-handshake` | 1 | FA v4 shim |
| `fa-header` | `fa-solid fa-heading` | 1 | FA v4 shim |
| `fa-heart` | `fa-solid fa-heart` | 10 |  |
| `fa-heart-o` | `fa-regular fa-heart` | 11 | FA v4 shim |
| `fa-heartbeat` | `fa-solid fa-heart-pulse` | 2 | alias heartbeat |
| `fa-history` | `fa-solid fa-clock-rotate-left` | 9 | alias history |
| `fa-home` | `fa-solid fa-house` | 4 | FA v4 shim |
| `fa-hourglass-half` | `fa-solid fa-hourglass-half` | 1 |  |
| `fa-id-badge` | `fa-regular fa-id-badge` | 3 | FA v4 shim |
| `fa-id-card-o` | `fa-regular fa-id-card` | 9 | FA v4 shim |
| `fa-inbox` | `fa-solid fa-inbox` | 4 |  |
| `fa-info-circle` | `fa-solid fa-circle-info` | 1 | alias info-circle |
| `fa-inr` | `fa-solid fa-indian-rupee-sign` | 6 | FA v4 shim |
| `fa-instagram` | `fa-brands fa-instagram` | 2 | FA v4 shim |
| `fa-italic` | `fa-solid fa-italic` | 1 |  |
| `fa-key` | `fa-solid fa-key` | 2 |  |
| `fa-laptop` | `fa-solid fa-laptop` | 1 |  |
| `fa-level-up` | `fa-solid fa-turn-up` | 7 | FA v4 shim |
| `fa-life-ring` | `fa-solid fa-life-ring` | 6 |  |
| `fa-line-chart` | `fa-solid fa-chart-line` | 2 | FA v4 shim |
| `fa-link` | `fa-solid fa-link` | 2 |  |
| `fa-linkedin` | `fa-brands fa-linkedin-in` | 2 | FA v4 shim |
| `fa-list` | `fa-solid fa-list` | 2 |  |
| `fa-list-ol` | `fa-solid fa-list-ol` | 1 |  |
| `fa-list-ul` | `fa-solid fa-list-ul` | 1 |  |
| `fa-lock` | `fa-solid fa-lock` | 21 |  |
| `fa-map-marker` | `fa-solid fa-location-dot` | 8 | FA v4 shim |
| `fa-map-o` | `fa-regular fa-map` | 1 | FA v4 shim |
| `fa-minus` | `fa-solid fa-minus` | 4 |  |
| `fa-mobile` | `fa-solid fa-mobile-screen-button` | 3 | FA v4 shim |
| `fa-paper-plane` | `fa-solid fa-paper-plane` | 8 |  |
| `fa-paper-plane-o` | `fa-regular fa-paper-plane` | 3 | FA v4 shim |
| `fa-paragraph` | `fa-solid fa-paragraph` | 1 |  |
| `fa-pause` | `fa-solid fa-pause` | 8 |  |
| `fa-pause-circle` | `fa-solid fa-circle-pause` | 1 | alias pause-circle |
| `fa-pencil` | `fa-solid fa-pencil` | 7 |  |
| `fa-pencil-square-o` | `fa-regular fa-pen-to-square` | 3 | FA v4 shim |
| `fa-phone` | `fa-solid fa-phone` | 6 |  |
| `fa-picture-o` | `fa-regular fa-image` | 6 | FA v4 shim |
| `fa-play` | `fa-solid fa-play` | 3 |  |
| `fa-plug` | `fa-solid fa-plug` | 2 |  |
| `fa-plus` | `fa-solid fa-plus` | 12 |  |
| `fa-power-off` | `fa-solid fa-power-off` | 1 |  |
| `fa-qrcode` | `fa-solid fa-qrcode` | 2 |  |
| `fa-question-circle` | `fa-solid fa-circle-question` | 1 | alias question-circle |
| `fa-question-circle-o` | `fa-regular fa-circle-question` | 2 | FA v4 shim |
| `fa-quote-left` | `fa-solid fa-quote-left` | 2 |  |
| `fa-refresh` | `fa-solid fa-arrows-rotate` | 5 | FA v4 shim |
| `fa-repeat` | `fa-solid fa-arrow-rotate-right` | 2 | FA v4 shim |
| `fa-reply` | `fa-solid fa-reply` | 3 |  |
| `fa-road` | `fa-solid fa-road` | 2 |  |
| `fa-rotate-left` | `fa-solid fa-arrow-rotate-left` | 1 | FA v4 shim |
| `fa-rotate-right` | `fa-solid fa-arrow-rotate-right` | 1 | FA v4 shim |
| `fa-save` | `fa-regular fa-floppy-disk` | 2 | FA v4 shim |
| `fa-search` | `fa-solid fa-magnifying-glass` | 9 | alias search |
| `fa-share` | `fa-solid fa-share` | 1 |  |
| `fa-share-alt` | `fa-solid fa-share-nodes` | 4 | alias share-alt |
| `fa-shield` | `fa-solid fa-shield` | 7 |  |
| `fa-sign-in` | `fa-solid fa-right-to-bracket` | 2 | FA v4 shim |
| `fa-sign-out` | `fa-solid fa-right-from-bracket` | 2 | FA v4 shim |
| `fa-sliders` | `fa-solid fa-sliders` | 9 |  |
| `fa-smile-o` | `fa-regular fa-face-smile` | 1 | FA v4 shim |
| `fa-star` | `fa-solid fa-star` | 5 |  |
| `fa-star-o` | `fa-regular fa-star` | 4 | FA v4 shim |
| `fa-stethoscope` | `fa-solid fa-stethoscope` | 1 |  |
| `fa-sticky-note-o` | `fa-regular fa-note-sticky` | 3 | FA v4 shim |
| `fa-table` | `fa-solid fa-table` | 1 |  |
| `fa-tachometer` | `fa-solid fa-gauge-high` | 2 | FA v4 shim |
| `fa-tag` | `fa-solid fa-tag` | 1 |  |
| `fa-tags` | `fa-solid fa-tags` | 3 |  |
| `fa-tasks` | `fa-solid fa-bars-progress` | 2 | FA v4 shim |
| `fa-th-large` | `fa-solid fa-table-cells-large` | 1 | alias th-large |
| `fa-th-list` | `fa-solid fa-table-list` | 1 | alias th-list |
| `fa-thumbs-o-up` | `fa-regular fa-thumbs-up` | 2 | FA v4 shim |
| `fa-ticket` | `fa-solid fa-ticket` | 2 |  |
| `fa-times` | `fa-solid fa-xmark` | 28 | alias times |
| `fa-times-circle` | `fa-solid fa-circle-xmark` | 4 | alias times-circle |
| `fa-toggle-on` | `fa-solid fa-toggle-on` | 1 |  |
| `fa-trash` | `fa-solid fa-trash-can` | 5 | FA v4 shim |
| `fa-trash-o` | `fa-regular fa-trash-can` | 2 | FA v4 shim |
| `fa-twitter` | `fa-brands fa-twitter` | 2 | FA v4 shim |
| `fa-undo` | `fa-solid fa-arrow-rotate-left` | 7 | alias undo |
| `fa-unlock` | `fa-solid fa-unlock` | 1 |  |
| `fa-unlock-alt` | `fa-solid fa-unlock` | 4 | FA v4 shim |
| `fa-upload` | `fa-solid fa-upload` | 7 |  |
| `fa-user` | `fa-solid fa-user` | 1 |  |
| `fa-user-o` | `fa-regular fa-user` | 4 | FA v4 shim |
| `fa-user-plus` | `fa-solid fa-user-plus` | 2 |  |
| `fa-user-secret` | `fa-solid fa-user-secret` | 4 |  |
| `fa-user-times` | `fa-solid fa-user-xmark` | 1 | alias user-times |
| `fa-users` | `fa-solid fa-users` | 16 |  |
| `fa-wrench` | `fa-solid fa-wrench` | 1 |  |
| `fa-youtube-play` | `fa-brands fa-youtube` | 2 | FA v4 shim |
