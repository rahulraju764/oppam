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
            }
        },

        sync() {
            // Held in the browser until the form is submitted: no request per key press.
            this.$wire.$set(model, this.$refs.editor.innerHTML, false);
        },

        format(command) {
            this.$refs.editor.focus();
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand(command);
            this.sync();
        },

        openLink() {
            const selection = window.getSelection();
            this.range = selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
            this.linkUrl = '';
            this.linking = true;
        },

        applyLink() {
            this.$refs.editor.focus();
            if (this.range) {
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(this.range);
            }
            if (this.linkUrl) {
                document.execCommand('createLink', false, this.linkUrl);
            }
            this.linking = false;
            this.sync();
        },
    }));
});
