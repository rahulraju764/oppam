---
name: oppam-ui-standards
description: Oppam Matrimony UI standard — template-faithful Bootstrap 5.3 + design-token styling, the reusable Blade component kit (buttons, inputs, cards, modals, badges, alerts, empty states), mandatory UI states, responsive breakpoints (320/375/tablet/desktop), accessibility and mobile rules. Load before creating or changing any Blade view, Livewire view, CSS or Alpine code. Oppam project only.
---

# Oppam UI standard

The original PHP template (`docs/template/`, notes in `docs/template-notes.md`) is the visual
source of truth. The goal is a UI that looks like one product everywhere and is easy to change in
one place.

## Styling system — Bootstrap 5.3 + design tokens (not Tailwind)

The project deliberately keeps the template's **Bootstrap 5.3 + `style.css`/`responsive.css` +
CSS custom-property tokens**. Do **not** add Tailwind (PRD §4, CLAUDE.md). The principles below
are the same ones a Tailwind codebase would follow, applied to this stack:

| Principle | How it's done here |
|---|---|
| Mobile-first responsive | Write base styles for phones, add `@media (min-width: …)` upward; use Bootstrap's mobile-first grid (`col-12 col-lg-6`). New CSS never adds `max-width` media queries except to fix a template rule. |
| Small, fixed design system | Only the tokens in `resources/css/tokens.css`: colours (`--color-primary #e02349`, `--color-primary-dark`, `--color-secondary`, `--color-secondary-dark`, `--color-gr-dark`, `--bg-rose/teal/sand/mist`), spacing `--space-1…11` (4-px base), type scale `--text-xs…3xl`, `--text-hero`, radii `--radius-xs…lg`, `--radius-card`, `--radius-pill`, shadows `--shadow-*`, motion `--transition-*`, z-layers. Need a new value? Add a token, don't hard-code. |
| Reusable components, not repeated class strings | Blade components in `resources/views/components/ui/*` (below). A class combination used 3+ times becomes a component or a named CSS class. |
| No random colours / arbitrary values | No hex/rgb literals, no one-off `px` spacing in views or new CSS — tokens only. `style=""` attributes are not allowed (except dynamic widths like progress bars via a CSS variable). |
| Explicit maps for dynamic classes | Map enums to classes in PHP (`InterestStatus::Accepted->badgeClass()` or a `match`) — never build class names by string concatenation from user/DB data. |
| Visible focus | Keep `--ring-focus`; never `outline: none` without a replacement. |
| Contrast | Text on white uses `--color-secondary-dark` / `--color-gr-dark` (plain teal/grey fail AA). White text only on `--color-primary`, `--color-primary-dark`, `--color-secondary-dark`. |

## Component kit — `resources/views/components/ui/`

Build and reuse these; never re-create them inline:

| Component | Variants / props |
|---|---|
| `<x-ui.button>` | `variant` primary / secondary / outline / ghost / danger / link, `size` sm / md / lg, `loading` (spinner + disabled via `wire:loading`), `icon`, `type` (submit by default inside forms), renders `<a>` only when `href` is given |
| `<x-ui.input>`, `<x-ui.select>`, `<x-ui.textarea>`, `<x-ui.checkbox>`, `<x-ui.radio-group>` | `label` (required), `name`, `id`, `hint`, error from `$errors` automatically, `required` marker, `wire:model` passthrough |
| `<x-ui.card>` | header slot, body, footer, `variant` default / accent (`.all-matches` red panel) — uses `--radius-card`, `--shadow-card`, `--pad-card` |
| `<x-ui.modal>` | Alpine-driven, focus trap, Esc/close button, `aria-modal`, returns focus on close; confirm variant with typed-reason input for destructive admin actions |
| `<x-ui.badge>` / `<x-ui.chip>` | Map-driven colours (verified, premium, plan, status) |
| `<x-ui.alert>` | success / info / warning / danger, dismissible, `role="alert"` for errors |
| `<x-ui.empty-state>` | icon, title, message, primary action — every list uses it |
| `<x-ui.skeleton>` | card / row / avatar placeholders for `#[Lazy]` and loading |
| `<x-ui.toast-stack>` | driven by `dispatch('toast', …)` and live notifications, `aria-live="polite"` |
| `<x-ui.pagination>` / load-more | Bootstrap-styled, keyboard accessible |
| `<x-ui.avatar>` | photo with privacy-aware blurred fallback + online dot |
| Domain components | `<x-profile.row>`, `<x-profile.tile>`, `<x-profile.member-card>`, `<x-story.card>`, `<x-pricing.card>` — converted 1:1 from the template partials |

## Mandatory UI states

Every screen and interactive element has all of these designed, not left to chance:

1. **Loading** — skeletons for sections, spinners + disabled buttons for actions
   (`wire:loading.attr="disabled"`, `wire:target`), no layout jump.
2. **Empty** — `<x-ui.empty-state>` with a helpful next step.
3. **Error** — inline field errors under inputs (`aria-describedby`), a toast/alert for action
   failures, friendly text (never raw exception messages).
4. **Success** — toast or inline confirmation; optimistic UI where the PRD asks for it (likes,
   chat) with rollback on failure.
5. **Disabled / locked** — explain why (e.g. "Upgrade to chat", "Your bureau's KYC is pending").
6. **Offline / reconnecting** — real-time screens show a subtle "Reconnecting…" bar.

## Layout rules

- Member & broker dashboards use the template's **3 / 6 / 3** layout (sidebar | content | rail);
  admin uses its own sidebar layout. No 3/9 pages.
- Containers: `.container` (full-bleed, tokenised gutter), `.container.is-chrome` (1400 px — header,
  footer, home sections), `.container.is-readable` (1200 px — forms, legal, wizard).
- Inside `.container` rows use gutters ≤ `g-4` (larger gutters overflow at 360 px).
- Sticky elements use `top: var(--offset-sticky)`.
- One `<h1>` per page; sections `<h2>`+; `<main id="main" tabindex="-1">`; skip link first.

## Responsive checks (every changed screen)

Check at **320 px, 375 px, 768 px (tablet), 1024 px, 1440 px**:
- No horizontal scroll (`document.scrollingElement.scrollWidth <= clientWidth`).
- Tap targets ≥ 44 × 44 px; primary actions reachable with one thumb on phones.
- Mobile drawer + bottom tab bar behave as in the template; chat shows one pane at a time < 992 px.
- Tables in admin scroll inside their own `overflow-x: auto` wrapper.
- Images have `width`/`height`, `loading="lazy"` (except above-the-fold `fetchpriority="high"`),
  WebP conversions from medialibrary.

## Accessibility (WCAG 2.1 AA)

- Every control: `name`, `id`, visible `<label for>` (or `aria-label` for icon-only buttons).
- Actions are `<button>`; navigation is `<a href>`; no `href="#"`; no `<a>` inside `<a>`
  (use `.stretched-link`).
- Keyboard: everything reachable and operable with Tab/Enter/Space/Esc; visible focus; modals and
  the mobile drawer trap focus and restore it.
- Live regions: new chat messages and toasts announced politely; errors assertively.
- Colour is never the only signal (read ticks also have `aria-label`, status badges have text).
- `prefers-reduced-motion` respected for carousels, preloader, toasts.
- axe-core checks in Dusk on key pages must pass.

## Alpine.js

- Alpine for pure client behaviour only (toggles, optimistic bubbles, typing whispers, drag-sort,
  cropper, keyboard shortcuts). Business decisions stay on the server.
- Shared client state in `Alpine.store()` (online presence, toast queue); no globals on `window`
  except `Echo`.
- Put reusable Alpine components in `resources/js/alpine/*.js`, not long inline `x-data` objects
  (> ~10 lines → extract).

## Performance

- Optimise images (dimensions + WebP), lazy-load below the fold, no images > 200 KB in layouts.
- No new front-end dependency without a reason in `docs/decisions.md`; check bundle size impact.
- Swiper instances initialised per element and re-initialised after Livewire morphs
  (`Livewire.hook('morph.updated')`), never per selector string.

## Template fidelity

- Keep the template's markup and class names when converting a page; improve only what the PRD
  or `docs/template-notes.md` lists as a fix.
- Font Awesome 6 migration is its own task (P9.3) — don't mix icon renames into feature work.
