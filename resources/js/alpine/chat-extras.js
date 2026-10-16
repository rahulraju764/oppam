/* =============================================================================
   CHAT EXTRAS (PRD M07, P4.2) — mixed into chatThread (chat.js); client behaviour only.

   typingMixin(channel, myCode) — "Anjali is typing…" over `presence-chat.{id}` client events
     (whispers): sent at most once every TYPING_THROTTLE_MS while the member types, shown for
     TYPING_SHOW_MS after the other member's last keystroke. Never touches the database. The
     presence channel is left when the thread closes (the private chat channel Livewire follows
     is not — Echo.leave() of the presence name only).
   emojiMixin() — a small built-in emoji palette (no package): inserts at the caret.
============================================================================= */

export const TYPING_THROTTLE_MS = 2000;
export const TYPING_SHOW_MS = 4000;

const EMOJIS = [
    '😀', '😃', '😄', '😁', '😊', '🙂', '😉', '😍', '🥰', '😘', '😇', '🤗', '🤔', '😅', '😂', '🤣',
    '😌', '😎', '🥲', '😢', '😭', '😮', '😯', '😳', '🙈', '🙏', '👍', '👌', '👏', '🙌', '🤝', '💪',
    '❤️', '🧡', '💛', '💚', '💙', '💜', '💕', '💖', '💐', '🌹', '🌸', '🌼', '🌺', '☀️', '🌙', '⭐',
    '🎉', '🎊', '🎂', '☕', '🍵', '🍛', '🍰', '📞', '📷', '🏠', '🛕', '⛪', '🕌', '✈️', '🎵', '📚',
];

export function typingMixin(channel, myCode) {
    return {
        typing: false,
        typingTimer: null,
        lastWhisper: 0,
        presence: null,

        initTyping() {
            if (!window.Echo || channel === '') return;
            this.presenceName = `presence-${channel}`;
            this.presence = window.Echo.join(this.presenceName);
            this.presence.listenForWhisper('typing', (event) => {
                if (event?.code === myCode) return;   // another tab of this member
                this.typing = true;
                clearTimeout(this.typingTimer);
                this.typingTimer = setTimeout(() => { this.typing = false; }, TYPING_SHOW_MS);
            });
        },

        typed() {
            const now = Date.now();
            if (!this.presence || now - this.lastWhisper < TYPING_THROTTLE_MS) return;
            this.lastWhisper = now;
            this.presence.whisper('typing', { code: myCode });
        },

        stopTyping() {
            clearTimeout(this.typingTimer);
            if (this.presence) window.Echo.leave(this.presenceName);
        },
    };
}

export function emojiMixin() {
    return {
        emojiOpen: false,
        emojis: EMOJIS,

        addEmoji(emoji) {
            const input = this.$refs.input;
            const start = input?.selectionStart ?? this.body.length;
            const end = input?.selectionEnd ?? this.body.length;
            this.body = this.body.slice(0, start) + emoji + this.body.slice(end);
            this.emojiOpen = false;
            this.$nextTick(() => {
                input?.focus();
                input?.setSelectionRange(start + emoji.length, start + emoji.length);
            });
        },
    };
}
