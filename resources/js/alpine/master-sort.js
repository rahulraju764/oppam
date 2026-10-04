/* =============================================================================
   masterSort — drag-and-drop ordering for the A11 master list editor (admin panel).
   Rows dispatch master-drag / master-drop with their id; this moves the dragged row before
   the drop target in the current DOM order and sends the whole order to the server
   (ListEditor::reorder → ReorderMasterRows, which checks it is exactly the list's rows).
   The arrow buttons on each row are the keyboard alternative.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('masterSort', () => ({
        dragging: null,

        drag(id) {
            this.dragging = id;
        },

        drop(targetId) {
            if (this.dragging === null || this.dragging === targetId) {
                this.dragging = null;
                return;
            }

            const ids = Array.from(document.querySelectorAll('tr[data-id]')).map((row) => Number(row.dataset.id));
            const from = ids.indexOf(this.dragging);
            ids.splice(from, 1);
            ids.splice(ids.indexOf(targetId), 0, this.dragging);
            this.dragging = null;

            this.$wire.reorder(ids);
        },
    }));
});
