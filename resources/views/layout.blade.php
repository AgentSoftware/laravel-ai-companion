<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Companion</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* ── Design tokens ─────────────────────────────────────────── */
        :root {
            --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
            --font-gilmer: 'Inter', ui-sans-serif, system-ui, sans-serif;
            --color-spectre-purple: #1f0b38;
            --color-white: #ffffff;
            --color-primary-50:  #eff2f8; --color-primary-100: #e2e8f9; --color-primary-200: #c4d1fb;
            --color-primary-300: #a8b4f2; --color-primary-400: #8090ef; --color-primary-500: #6a6fec;
            --color-primary-600: #5359ea; --color-primary-700: #4246ca; --color-primary-800: #3135aa;
            --color-primary-900: #24278b;
            --color-default-50: #f9fafb;  --color-default-100: #f3f4f6; --color-default-200: #e5e7eb;
            --color-default-300: #d1d5db; --color-default-400: #9ca3af; --color-default-500: #6b7280;
            --color-default-600: #4b5563; --color-default-700: #374151; --color-default-800: #1f2937;
            --color-default-900: #111827;
            --color-red-50: #fef2f2;   --color-red-600: #dc2626;   --color-red-700: #b91c1c;
            --color-green-50: #f0fdf4; --color-green-400: #4ade80; --color-green-600: #16a34a; --color-green-700: #15803d;
            --color-blue-50: #eff6ff;  --color-blue-600: #2563eb;  --color-blue-700: #1d4ed8;
            --color-yellow-50: #fefce8; --color-yellow-600: #ca8a04; --color-yellow-800: #854d0e;
            --color-orange-50: #fff7ed; --color-orange-600: #ea580c; --color-orange-700: #c2410c;
            --color-purple-50: #faf5ff; --color-purple-600: #9333ea; --color-purple-700: #7e22ce;
            --color-fg: var(--color-default-900);
            --color-fg-secondary: var(--color-default-500);
            --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --gradient-ai: linear-gradient(90deg, #942aa9, #2b4dd0);
            --duration-fast: 120ms; --duration-base: 180ms;
            --ease-standard: cubic-bezier(0.2, 0, 0, 1);
        }

        /* ── Score colour bands ────────────────────────────────────── */
        .sc-green  { --sc: var(--color-green-600);  --sc-bg: var(--color-green-50);  --sc-text: var(--color-green-700); }
        .sc-amber  { --sc: var(--color-yellow-600); --sc-bg: var(--color-yellow-50); --sc-text: var(--color-yellow-800); }
        .sc-orange { --sc: var(--color-orange-600); --sc-bg: var(--color-orange-50); --sc-text: var(--color-orange-700); }
        .sc-red    { --sc: var(--color-red-600);    --sc-bg: var(--color-red-50);    --sc-text: var(--color-red-700); }
        .sc-none   { --sc: var(--color-default-300);--sc-bg: var(--color-default-100);--sc-text: var(--color-default-500); }

        /* ── Reset & base ──────────────────────────────────────────── */
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body { background: var(--color-default-50); color: var(--color-fg); font-family: var(--font-sans); font-size: 14px; }
        a { color: inherit; text-decoration: none; }

        /* ── Layout ─────────────────────────────────────────────────── */
        .app { display: grid; grid-template-columns: 248px 1fr; min-height: 100vh; }

        /* ── Sidebar ─────────────────────────────────────────────────── */
        .sidebar {
            background: var(--color-spectre-purple);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 22px 16px 16px;
            overflow: hidden;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 18px;
        }

        .sidebar__mark {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--gradient-ai);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar__mark i { color: #fff; font-size: 16px; }

        .sidebar__name {
            font-family: var(--font-gilmer);
            font-weight: 700;
            font-size: 17px;
            line-height: 1.2;
            color: #fff;
        }

        .sidebar__tag {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.45);
            line-height: 1;
        }

        .sidebar__nav { flex: 1; display: flex; flex-direction: column; gap: 2px; }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255,255,255,0.75);
            transition: background var(--duration-fast) var(--ease-standard), color var(--duration-fast) var(--ease-standard);
            cursor: pointer;
        }

        .nav-item i { font-size: 16px; opacity: 0.8; }
        .nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
        .nav-item.active { background: rgba(255,255,255,0.12); color: #fff; }
        .nav-item.active i { opacity: 1; }

        .nav-badge {
            margin-left: auto;
            background: rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8);
            font-size: 11px;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 20px;
        }

        .sidebar__footer {
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-family: monospace;
            font-size: 11px;
            color: rgba(255,255,255,0.38);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .sidebar__footer i { font-size: 13px; }

        /* ── Main area ───────────────────────────────────────────────── */
        .main { background: var(--color-default-50); overflow-y: auto; }

        .content {
            max-width: 1340px;
            margin: 0 auto;
            padding: 28px 36px;
        }

        /* ── Page header ─────────────────────────────────────────────── */
        .page-head { margin-bottom: 24px; }
        .page-head__title { font-size: 20px; font-weight: 700; color: var(--color-default-900); margin: 0 0 2px; }
        .page-head__sub { font-size: 13px; color: var(--color-fg-secondary); margin: 0; }
        .page-head__back {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: var(--color-fg-secondary);
            margin-bottom: 10px;
            transition: color var(--duration-fast);
        }
        .page-head__back:hover { color: var(--color-default-700); }

        /* ── Cards ───────────────────────────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--color-default-100);
        }

        .card__title {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-default-700);
            margin: 0;
        }

        .card__sub {
            font-size: 12px;
            color: var(--color-fg-secondary);
            margin: 2px 0 0;
        }

        .card__body { padding: 20px; }

        /* ── Stat band ───────────────────────────────────────────────── */
        .statband {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat {
            background: #fff;
            border-radius: 10px;
            box-shadow: var(--shadow);
            padding: 16px 18px 14px;
        }

        .stat__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat__label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--color-fg-secondary);
        }

        .stat__icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: var(--color-primary-50);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-primary-600);
            font-size: 15px;
        }

        .stat__value {
            font-size: 28px;
            font-weight: 700;
            color: var(--color-default-900);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat__footer {
            font-size: 11px;
            color: var(--color-fg-secondary);
            margin-top: 6px;
        }

        .stat__progress {
            height: 3px;
            background: var(--color-default-100);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 8px;
        }

        .stat__progress-fill {
            height: 100%;
            background: var(--color-primary-500);
            border-radius: 2px;
            transition: width 0.6s var(--ease-standard);
        }

        /* ── Toolbar ─────────────────────────────────────────────────── */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .search-input {
            flex: 1;
            max-width: 280px;
            padding: 7px 12px 7px 32px;
            border: 1px solid var(--color-default-200);
            border-radius: 7px;
            font-size: 13px;
            color: var(--color-default-700);
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 256 256'%3E%3Cpath d='M229.66,218.34l-50.07-50.06a88.21,88.21,0,1,0-11.31,11.31l50.06,50.07a8,8,0,0,0,11.32-11.32ZM40,112a72,72,0,1,1,72,72A72.08,72.08,0,0,1,40,112Z' fill='%239ca3af'/%3E%3C/svg%3E") no-repeat 10px center;
            outline: none;
            transition: border-color var(--duration-fast);
        }

        .search-input:focus { border-color: var(--color-primary-400); }

        .seg-control {
            display: flex;
            background: var(--color-default-100);
            border-radius: 7px;
            padding: 2px;
            gap: 2px;
        }

        .seg-btn {
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            color: var(--color-default-500);
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all var(--duration-fast);
        }

        .seg-btn.active { background: #fff; color: var(--color-default-800); box-shadow: var(--shadow-xs); }

        /* ── Tables ──────────────────────────────────────────────────── */
        .tbl { width: 100%; border-collapse: collapse; }

        .tbl th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--color-default-400);
            text-align: left;
            padding: 10px 16px;
            border-bottom: 1px solid var(--color-default-100);
            background: var(--color-default-50);
        }

        .tbl td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--color-default-100);
            vertical-align: middle;
        }

        .tbl tr:last-child td { border-bottom: none; }
        .tbl tbody tr { transition: background var(--duration-fast); cursor: pointer; }
        .tbl tbody tr:hover { background: var(--color-default-50); }

        /* ── Agent cell ──────────────────────────────────────────────── */
        .agent-cell { display: flex; align-items: center; gap: 10px; }

        .agent-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: var(--color-primary-50);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-primary-600);
            font-size: 17px;
            flex-shrink: 0;
        }

        .agent-name { font-size: 13px; font-weight: 600; color: var(--color-default-800); }
        .agent-path { font-size: 11px; color: var(--color-fg-secondary); font-family: monospace; margin-top: 1px; }

        /* ── Log id chip ─────────────────────────────────────────────── */
        .logid {
            display: inline-block;
            font-family: monospace;
            font-size: 11px;
            padding: 2px 7px;
            background: var(--color-default-100);
            border-radius: 5px;
            color: var(--color-default-500);
            letter-spacing: 0.03em;
        }

        /* ── Prompt preview ──────────────────────────────────────────── */
        .prompt-preview {
            font-size: 13px;
            color: var(--color-default-600);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.45;
            max-width: 360px;
        }

        /* ── Chips ───────────────────────────────────────────────────── */
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .chip-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .chip.evaluated { background: var(--color-green-50); color: var(--color-green-700); }
        .chip.pending   { background: var(--color-default-100); color: var(--color-default-500); }
        .chip.evaluating{ background: var(--color-blue-50); color: var(--color-blue-700); }

        /* ── Score badge ─────────────────────────────────────────────── */
        .score-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--sc-bg);
            color: var(--sc-text);
        }

        /* ── Score bar ───────────────────────────────────────────────── */
        .score-bar { display: flex; align-items: center; gap: 8px; }

        .score-bar__track {
            flex: 1;
            height: 5px;
            background: var(--color-default-100);
            border-radius: 3px;
            overflow: hidden;
            min-width: 60px;
        }

        .score-bar__fill {
            height: 100%;
            border-radius: 3px;
            background: var(--sc, var(--color-primary-500));
            transition: width 0.5s var(--ease-standard);
        }

        .score-bar__num {
            font-size: 12px;
            font-weight: 600;
            color: var(--sc-text, var(--color-default-600));
            width: 28px;
            text-align: right;
            flex-shrink: 0;
        }

        /* ── Donut ring ──────────────────────────────────────────────── */
        .ring {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ring svg { position: absolute; top: 0; left: 0; }

        .ring__track { stroke: var(--color-default-100); }
        .ring__fill  {
            stroke: var(--sc, var(--color-primary-600));
            stroke-linecap: round;
            transition: stroke-dashoffset 0.8s var(--ease-standard);
        }

        .ring__label {
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 1;
            position: relative;
            z-index: 1;
        }

        .ring__num  { font-weight: 800; color: var(--color-default-900); line-height: 1; }
        .ring__out  { font-size: 11px; color: var(--color-fg-secondary); margin-top: 2px; }

        /* ── Buttons ─────────────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: background var(--duration-fast), opacity var(--duration-fast);
            white-space: nowrap;
        }

        .btn.primary {
            background: var(--color-primary-600);
            color: #fff;
        }
        .btn.primary:hover { background: var(--color-primary-700); }
        .btn.primary:disabled { opacity: 0.55; cursor: not-allowed; }

        .btn.sm { padding: 5px 10px; font-size: 12px; }

        /* ── Spinner ─────────────────────────────────────────────────── */
        @keyframes spin { to { transform: rotate(360deg); } }

        .spin {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            flex-shrink: 0;
        }

        /* ── Eval hero ───────────────────────────────────────────────── */
        .eval-hero {
            background: #fff;
            border-radius: 10px;
            box-shadow: var(--shadow);
            padding: 24px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 16px;
        }

        .eval-hero__left { flex: 1; min-width: 0; }

        .eval-hero__verdict {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--sc-bg);
            color: var(--sc-text);
            margin-bottom: 8px;
        }

        .eval-hero__verdict-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--sc);
        }

        .eval-hero__title {
            font-size: 20px;
            font-weight: 700;
            color: var(--color-default-900);
            margin: 0 0 6px;
        }

        .eval-hero__meta {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--color-fg-secondary);
        }

        .judge-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 500;
            background: var(--color-primary-50);
            color: var(--color-primary-700);
            font-family: monospace;
        }

        /* ── Criteria ─────────────────────────────────────────────────── */
        .criterion {
            display: grid;
            grid-template-columns: 260px 150px 1fr;
            align-items: center;
            gap: 16px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--color-default-100);
        }

        .criterion:last-child { border-bottom: none; }
        .criterion__name { font-weight: 600; font-size: 13px; color: var(--color-default-800); }
        .criterion__fb { font-size: 13px; color: var(--color-fg-secondary); }

        /* ── Summary text ─────────────────────────────────────────────── */
        .summary-text {
            font-size: 14px;
            line-height: 1.65;
            color: var(--color-default-700);
        }

        /* ── Prompt toggle ────────────────────────────────────────────── */
        .prompt-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            cursor: pointer;
            user-select: none;
        }

        .prompt-toggle:hover { background: var(--color-default-50); }

        .prompt-toggle i { font-size: 16px; color: var(--color-default-400); transition: transform var(--duration-fast); }

        .prompt-body {
            padding: 0 20px 20px;
            border-top: 1px solid var(--color-default-100);
        }

        .prompt-body pre {
            font-size: 12px;
            line-height: 1.55;
            font-family: monospace;
            background: var(--color-default-50);
            border-radius: 7px;
            padding: 14px 16px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
            color: var(--color-default-700);
            margin: 0;
        }

        /* ── Insights layout ──────────────────────────────────────────── */
        .ins-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        /* ── Criterion chart (insights) ───────────────────────────────── */
        .critchart { display: flex; flex-direction: column; gap: 8px; }

        .critchart__row { display: flex; align-items: center; gap: 10px; }

        .critchart__label {
            width: 160px;
            font-size: 12px;
            color: var(--color-default-600);
            flex-shrink: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .critchart__track {
            flex: 1;
            height: 6px;
            background: var(--color-default-100);
            border-radius: 3px;
            overflow: hidden;
        }

        .critchart__fill {
            height: 100%;
            border-radius: 3px;
            background: var(--sc, var(--color-primary-500));
        }

        .critchart__num {
            font-size: 12px;
            font-weight: 600;
            width: 30px;
            text-align: right;
            color: var(--sc-text, var(--color-default-600));
            flex-shrink: 0;
        }

        /* ── Leaderboard rows ─────────────────────────────────────────── */
        .lb-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--color-default-100);
        }

        .lb-row:last-child { border-bottom: none; }

        .lb-icon {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            background: var(--color-primary-50);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-primary-600);
            font-size: 18px;
            flex-shrink: 0;
        }

        .lb-info { flex: 1; min-width: 0; }
        .lb-name { font-size: 13px; font-weight: 600; color: var(--color-default-800); }
        .lb-path { font-size: 11px; color: var(--color-fg-secondary); font-family: monospace; }

        /* ── Coverage donut legend ────────────────────────────────────── */
        .cov-legend {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-top: 12px;
        }

        .cov-legend__item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--color-default-600);
        }

        .cov-legend__dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .cov-backlog { margin-top: 16px; }
        .cov-backlog__title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--color-fg-secondary);
            margin-bottom: 8px;
        }

        .cov-backlog__row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid var(--color-default-100);
            font-size: 12px;
        }

        .cov-backlog__row:last-child { border-bottom: none; }
        .cov-backlog__name { color: var(--color-default-700); font-weight: 500; }
        .cov-backlog__count {
            background: var(--color-orange-50);
            color: var(--color-orange-700);
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 11px;
        }

        /* ── Trend chart ──────────────────────────────────────────────── */
        .trend-chart {
            width: 100%;
            overflow: hidden;
            padding: 0;
        }

        .trend-chart svg { display: block; width: 100%; }

        /* ── Pagination ───────────────────────────────────────────────── */
        .pagination { display: flex; gap: 4px; align-items: center; }
        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--color-default-600);
            border: 1px solid var(--color-default-200);
            background: #fff;
            transition: all var(--duration-fast);
        }
        .pagination a:hover { border-color: var(--color-primary-400); color: var(--color-primary-600); }
        .pagination .active span, .pagination [aria-current="page"] span {
            background: var(--color-primary-600);
            color: #fff;
            border-color: var(--color-primary-600);
        }

        /* ── Fade-in ──────────────────────────────────────────────────── */
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
        .fade-in { animation: fadeIn 0.25s var(--ease-standard) both; }

        /* ── Toast ────────────────────────────────────────────────────── */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            z-index: 100;
        }

        .toast.error { background: var(--color-red-50); color: var(--color-red-700); }

        /* ── Chevron ──────────────────────────────────────────────────── */
        .chevron { color: var(--color-default-300); font-size: 16px; }
    </style>
</head>
<body>
@php
    $currentRoute = request()->routeIs('ai-companion.insights') ? 'insights' : 'agents';
    $agentCount = isset($agents) ? $agents->count() : \AgentSoftware\LaravelAiCompanion\Models\AiResponseLog::query()->distinct('agent')->count('agent');
@endphp
<div class="app">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="sidebar__mark">
                <i class="ph ph-sparkle"></i>
            </div>
            <div>
                <div class="sidebar__name">AI Companion</div>
                <div class="sidebar__tag">Evaluation</div>
            </div>
        </div>

        <nav class="sidebar__nav">
            <a href="{{ route('ai-companion.index') }}" class="nav-item {{ $currentRoute === 'agents' ? 'active' : '' }}">
                <i class="ph ph-squares-four"></i>
                Agents
                <span class="nav-badge">{{ $agentCount }}</span>
            </a>
            <a href="{{ route('ai-companion.insights') }}" class="nav-item {{ $currentRoute === 'insights' ? 'active' : '' }}">
                <i class="ph ph-chart-line-up"></i>
                Insights
            </a>
        </nav>

        <div class="sidebar__footer">
            <i class="ph ph-git-branch"></i>
            agentsoftware/laravel-ai-companion
        </div>
    </aside>

    <!-- Main content -->
    <main class="main">
        <div class="content">
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
