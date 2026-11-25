#!/usr/bin/env node
/* =============================================================================
   P9.3c — Lighthouse on the PRD §14 pages (LCP < 2.5 s on 4G): home, search, a profile, the member
   dashboard. Mobile preset with Lighthouse's simulated 4G throttling, performance only, against a
   PRODUCTION build — run `npm run build` and stop the Vite dev server first (public/hot must not
   exist, or pages would load unbundled dev assets).

     node scripts/lighthouse.mjs                         # base http://localhost:8000
     LH_BASE=http://localhost:8000 LH_PROFILE=OPM12370 LH_COOKIE="oppam_session=…" node scripts/lighthouse.mjs

   LH_COOKIE: copy the session cookie of a signed-in member from the browser (DevTools → Application)
   for the signed-in pages; without it they are skipped. Lighthouse itself is fetched by `npx` (not a
   dependency — decisions 2026-11-22 P9.3 (3)). Writes docs/perf/lighthouse-<date>.md and the raw
   JSON reports to storage/app/lighthouse/ (not committed).
============================================================================= */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const base = (process.env.LH_BASE ?? 'http://localhost:8000').replace(/\/$/, '');
const cookie = process.env.LH_COOKIE ?? '';
const profile = process.env.LH_PROFILE ?? '';
const budgetLcpMs = 2500;

if (existsSync('public/hot')) {
    console.error('public/hot exists: the Vite dev server is (or was) running. Stop it and run `npm run build` first.');
    process.exit(1);
}

const pages = [
    { name: 'Home', path: '/', signedIn: false },
    { name: 'Search', path: '/search', signedIn: true },
    ...(profile ? [{ name: 'Profile', path: `/profile/${profile}`, signedIn: true }] : []),
    { name: 'Member dashboard', path: '/dashboard', signedIn: true },
];

const outDir = 'storage/app/lighthouse';
mkdirSync(outDir, { recursive: true });
const rows = [];

for (const page of pages) {
    if (page.signedIn && !cookie) {
        rows.push({ ...page, skipped: 'no LH_COOKIE' });
        continue;
    }
    const file = join(outDir, `${page.name.toLowerCase().replace(/\W+/g, '-')}.json`);
    const args = ['--yes', 'lighthouse@12', base + page.path,
        '--only-categories=performance', '--output=json', `--output-path=${file}`, '--quiet',
        '--chrome-flags=--headless=new --no-sandbox'];
    // The cookie goes in a temp JSON FILE (Lighthouse accepts a path): inline JSON is mangled by cmd.exe on Windows,
    // and the session cookie would sit on the process command line (review P9.3c). Deleted right after.
    const headersFile = join(outDir, '.headers.json');
    if (page.signedIn) {
        writeFileSync(headersFile, JSON.stringify({ Cookie: cookie }));
        args.push(`--extra-headers=${headersFile}`);
    }
    console.log(`Lighthouse: ${page.name} (${base + page.path}) …`);
    try {
        execFileSync(process.platform === 'win32' ? 'npx.cmd' : 'npx', args, { stdio: 'inherit', shell: process.platform === 'win32' });
    } finally {
        if (existsSync(headersFile)) rmSync(headersFile);
    }

    const report = JSON.parse(readFileSync(file, 'utf8'));
    const audit = (id) => report.audits[id]?.numericValue ?? null;
    rows.push({
        ...page,
        score: Math.round((report.categories.performance.score ?? 0) * 100),
        lcp: audit('largest-contentful-paint'),
        fcp: audit('first-contentful-paint'),
        tbt: audit('total-blocking-time'),
        cls: audit('cumulative-layout-shift'),
        bytes: audit('total-byte-weight'),
        lcpElement: report.audits['largest-contentful-paint-element']?.details?.items?.[0]?.items?.[0]?.node?.snippet ?? '',
    });
}

const ms = (v) => (v === null ? '—' : `${(v / 1000).toFixed(2)} s`);
const date = new Date().toISOString().slice(0, 10);
const lines = [
    `# Lighthouse — ${date}`,
    '',
    `Mobile preset, simulated 4G, performance only, production build, against ${base}. Budget: LCP < ${budgetLcpMs / 1000} s (PRD §14).`,
    '',
    '| Page | Score | LCP | FCP | TBT | CLS | Bytes | LCP ≤ 2.5 s | LCP element |',
    '|---|---|---|---|---|---|---|---|---|',
    ...rows.map((r) => (r.skipped
        ? `| ${r.name} | skipped (${r.skipped}) | | | | | | | |`
        : `| ${r.name} | ${r.score} | ${ms(r.lcp)} | ${ms(r.fcp)} | ${Math.round(r.tbt)} ms | ${r.cls.toFixed(3)} | ${Math.round(r.bytes / 1024)} KB | ${r.lcp <= budgetLcpMs ? 'yes' : '**no**'} | \`${r.lcpElement.replace(/\|/g, '\\|').slice(0, 80)}\` |`)),
    '',
];
mkdirSync('docs/perf', { recursive: true });
writeFileSync(`docs/perf/lighthouse-${date}.md`, lines.join('\n'));
console.log(lines.join('\n'));
process.exit(rows.some((r) => !r.skipped && r.lcp > budgetLcpMs) ? 1 : 0);
