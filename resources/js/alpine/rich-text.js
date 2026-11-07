/* =============================================================================
   richText — the shared A08 editor (x-admin.rich-text). A contenteditable with a small toolbar and
   no outside library. It sends HTML to a Livewire property; the server sanitises it (HtmlSanitizer),
   so nothing here is a safety measure. The value that opens a row comes back from the server clean.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('richText', (model) => ({
        linking: false,
        linkUrl: '',
        range: null,

        init() {
            this.render();
            // Opening another row in the dialog replaces the property on the server.
            this.$wire.$watch(model, () => this.render());
        },

        render() {
            const next = this.$wire.$get(model) ?? '';
            if (this.$refs.editor.innerHTML !== next) {
                this.$refs.editor.innerHTML = next;
                this.range = null;   // it pointed into the content just replaced
                this.linking = false;
                this.linkUrl = '';
            }
        },

        sync() {
            // Held in the browser until the form is submitted: no request per key press.
            this.$wire.$set(model, this.$refs.editor.innerHTML, false);
        },

        // Tabbing to a toolbar button takes focus from the editor: keep where the cursor was.
        remember() {
            const selection = window.getSelection();
            if (selection.rangeCount > 0 && this.$refs.editor.contains(selection.anchorNode)) {
                this.range = selection.getRangeAt(0).cloneRange();
            }
        },

        restore() {
            this.$refs.editor.focus();
            if (this.range) {
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(this.range);
            }
        },

        format(command, value = null) {
            this.remember();   // a mouse click doesn't blur: the selection now is the one meant
            this.restore();
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand(command, false, value);
            this.sync();
        },

        openLink() {
            this.remember();
            this.linkUrl = '';
            this.linking = true;
        },

        applyLink() {
            this.restore();
            if (this.linkUrl && this.range) {
                document.execCommand('createLink', false, this.linkUrl);
            }
            this.linking = false;
            this.sync();
        },
    }));
});
