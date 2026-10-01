/* =============================================================================
   photoCropper — choose → crop/rotate → upload one profile photo (M11).
   The crop happens in the browser (Cropper.js, loaded only when needed) and the result is
   re-encoded as JPEG, so HEIC photos from iPhones work wherever the browser can display them;
   when it can't, the member is asked for a JPG/PNG. The server re-validates and re-encodes
   everything: nothing decided here is trusted.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('photoCropper', () => ({
        cropper: null,
        objectUrl: null,
        error: '',
        uploading: false,
        progress: 0,

        async choose(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            this.error = '';

            if (! file) {
                return;
            }

            const { default: Cropper } = await import('../vendor/cropper');

            this.objectUrl = URL.createObjectURL(file);
            const image = this.$root.querySelector('[data-crop-image]');

            image.onerror = () => {
                this.reset();
                this.error = this.$root.dataset.unreadable;
            };
            image.onload = () => {
                this.$dispatch('open-modal', { name: 'photo-crop' });
                this.$nextTick(() => {
                    this.cropper?.destroy();
                    this.cropper = new Cropper(image, { aspectRatio: 4 / 5, viewMode: 1, autoCropArea: 1, background: false });
                });
            };
            image.src = this.objectUrl;
        },

        rotate(degrees) {
            this.cropper?.rotate(degrees);
        },

        confirm() {
            if (! this.cropper) {
                return;
            }

            const canvas = this.cropper.getCroppedCanvas({ maxWidth: 2400, maxHeight: 2400, fillColor: '#fff' });

            canvas.toBlob((blob) => {
                if (! blob) {
                    this.error = this.$root.dataset.unreadable;
                    return;
                }

                const file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                this.$dispatch('close-modal', { name: 'photo-crop' });
                this.uploading = true;
                this.progress = 0;

                this.$wire.upload('photo', file,
                    () => { this.$wire.savePhoto().finally(() => { this.uploading = false; }); },
                    () => { this.uploading = false; this.error = this.$root.dataset.failed; },
                    (event) => { this.progress = event.detail.progress; },
                );
                this.reset();
            }, 'image/jpeg', 0.9);
        },

        cancel() {
            this.$dispatch('close-modal', { name: 'photo-crop' });
            this.reset();
        },

        reset() {
            this.cropper?.destroy();
            this.cropper = null;
            if (this.objectUrl) {
                URL.revokeObjectURL(this.objectUrl);
                this.objectUrl = null;
            }
        },

        destroy() {
            this.reset();
        },
    }));
});
