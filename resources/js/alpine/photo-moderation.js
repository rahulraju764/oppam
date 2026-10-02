/* =============================================================================
   photoModeration — the A04 photo grid's keyboard (admin panel).
   ← → ↑ ↓ move between photos · A mark approve · D mark reject · Space show / blur the
   focused photo · Enter apply every mark. Marks are only a client-side draft: the server
   (PhotoQueueGrid::decide → DecidePhoto) authorizes, checks the claim and audits each one.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('photoModeration', () => ({
        focus: 0,
        marks: {},
        revealed: {},

        tiles() {
            return Array.from(this.$refs.grid?.querySelectorAll('[data-id]') ?? []);
        },

        columns() {
            const tiles = this.tiles();
            if (tiles.length < 2) {
                return 1;
            }
            const top = tiles[0].offsetTop;
            const perRow = tiles.findIndex((tile) => tile.offsetTop !== top);
            return perRow === -1 ? tiles.length : perRow;
        },

        move(delta) {
            const tiles = this.tiles();
            if (tiles.length === 0) {
                return;
            }
            this.focus = Math.max(0, Math.min(tiles.length - 1, this.focus + delta));
            tiles.forEach((tile, index) => tile.setAttribute('tabindex', index === this.focus ? '0' : '-1'));
            tiles[this.focus].focus();
        },

        current() {
            return this.tiles()[this.focus]?.dataset.id;
        },

        key(event) {
            // Typing in the note / reason fields must not trigger shortcuts.
            if (event.target.closest('input, textarea, select, button')) {
                return;
            }

            const id = this.current();
            const handlers = {
                ArrowRight: () => this.move(1),
                ArrowLeft: () => this.move(-1),
                ArrowDown: () => this.move(this.columns()),
                ArrowUp: () => this.move(-this.columns()),
                a: () => id && (this.marks[id] = 'approve'),
                d: () => id && (this.marks[id] = 'reject'),
                ' ': () => id && (this.revealed[id] = ! this.revealed[id]),
                Enter: () => this.apply(),
            };
            const handler = handlers[event.key] ?? handlers[event.key.toLowerCase()];

            if (handler) {
                event.preventDefault();
                handler();
            }
        },

        summary() {
            const values = Object.values(this.marks);
            const approve = values.filter((v) => v === 'approve').length;
            const reject = values.filter((v) => v === 'reject').length;
            return `${approve} ✓ · ${reject} ✕`;
        },

        apply() {
            if (Object.keys(this.marks).length === 0) {
                return;
            }
            const decisions = { ...this.marks };
            this.marks = {};
            this.focus = 0;
            this.$wire.decide(decisions);
        },
    }));
});
