/* =============================================================================
   pdfDocument(url, watermark) — the A05 viewer for a PDF ID document (admin panel).
   pdf.js (loaded only when a PDF is opened) fetches the bytes from the 10-minute signed link
   and draws each page onto a <canvas> with the viewer's watermark painted over it: there is no
   browser PDF viewer, so no download / print button. Scripting in the PDF is never run
   (isEvalSupported: false; uploads with JavaScript are refused anyway). The server audits the
   fetch; nothing is cached (`no-store`).
============================================================================= */

const MAX_PAGES = 4;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pdfDocument', (url, watermark) => ({
        loading: true,
        failed: false,

        async init() {
            try {
                const [pdfjs, worker] = await Promise.all([
                    import('pdfjs-dist'),
                    import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
                ]);
                pdfjs.GlobalWorkerOptions.workerSrc = worker.default;

                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const data = new Uint8Array(await response.arrayBuffer());
                const pdf = await pdfjs.getDocument({ data, isEvalSupported: false, enableXfa: false }).promise;

                for (let n = 1; n <= Math.min(pdf.numPages, MAX_PAGES); n += 1) {
                    const page = await pdf.getPage(n);
                    const viewport = page.getViewport({ scale: 1.5 });
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);
                    canvas.className = 'vf-viewer__page';
                    const context = canvas.getContext('2d');
                    await page.render({ canvasContext: context, viewport, canvas }).promise;
                    this.stamp(context, canvas);
                    this.$refs.pages.appendChild(canvas);
                }

                await pdf.destroy();
            } catch {
                this.failed = true;
            } finally {
                this.loading = false;
            }
        },

        // Diagonal, repeated watermark in the brand colour — burned into the pixels, like the images.
        stamp(context, canvas) {
            const colour = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();
            const size = Math.max(14, Math.round(Math.min(canvas.width, canvas.height) / 32));

            context.save();
            context.globalAlpha = 0.35;
            context.fillStyle = colour;
            context.font = `bold ${size}px sans-serif`;
            context.translate(canvas.width / 2, canvas.height / 2);
            context.rotate(-Math.PI / 6);

            const step = context.measureText(watermark).width + size * 4;
            for (let y = -canvas.height; y < canvas.height; y += size * 6) {
                for (let x = -canvas.width; x < canvas.width; x += step) {
                    context.fillText(watermark, x, y);
                }
            }
            context.restore();
        },
    }));
});
