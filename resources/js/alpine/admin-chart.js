/* =============================================================================
   adminChart — the Chart.js wrapper for admin charts (PRD §11.0 "charts via Chart.js (Alpine
   wrapper)", A02). One small Alpine component:
     <div x-data="adminChart({ type, labels, datasets: [{ label, data }] })" wire:ignore>
         <canvas x-ref="canvas" role="img" aria-labelledby="…"></canvas>
     </div>
   type: 'line' | 'bar' | 'doughnut'. Colours come from the design tokens (never hard-coded here);
   only the Chart.js pieces these three chart types need are registered (smaller bundle). The page
   also renders the same numbers as a visually-hidden table, so the canvas is decorative for AT.
============================================================================= */

import {
    ArcElement, BarController, BarElement, CategoryScale, Chart, DoughnutController, Legend,
    LinearScale, LineController, LineElement, PointElement, Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement, BarController, BarElement, CategoryScale, DoughnutController, Legend,
    LinearScale, LineController, LineElement, PointElement, Tooltip,
);

const TYPES = ['line', 'bar', 'doughnut'];
const SERIES_TOKENS = ['--color-primary', '--color-secondary-dark', '--color-gr-dark', '--color-warning'];

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('adminChart', (config) => ({
        chart: null,

        init() {
            const type = TYPES.includes(config?.type) ? config.type : 'line';
            const colours = SERIES_TOKENS.map(token);
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            const datasets = (config?.datasets ?? []).map((set, index) => (type === 'doughnut'
                ? { label: set.label, data: set.data, backgroundColor: colours, borderWidth: 0 }
                : {
                    label: set.label,
                    data: set.data,
                    borderColor: colours[index % colours.length],
                    backgroundColor: colours[index % colours.length],
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.3,
                }));

            this.chart = new Chart(this.$refs.canvas, {
                type,
                data: { labels: config?.labels ?? [], datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: reduceMotion ? false : undefined,
                    plugins: { legend: { position: 'bottom' } },
                    scales: type === 'doughnut' ? {} : {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                    },
                },
            });
        },

        destroy() {
            this.chart?.destroy();
            this.chart = null;
        },
    }));
});
