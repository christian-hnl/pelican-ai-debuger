@once
<style>
    /* ---------- AI Debugger - base ---------- */
    .aid {
        --aid-primary: var(--primary-500, #6366f1);
        --aid-success: var(--success-500, #22c55e);
        --aid-danger: var(--danger-500, #ef4444);
        --aid-warning: var(--warning-500, #f59e0b);
        --aid-info: var(--info-500, #3b82f6);
        --aid-border: var(--gray-200, #e5e7eb);
        --aid-muted: var(--gray-500, #6b7280);
        --aid-text: var(--gray-950, #030712);
        --aid-card: #fff;
        --aid-soft: var(--gray-50, #f9fafb);
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        color: var(--aid-text);
    }

    .dark .aid {
        --aid-border: color-mix(in oklab, #fff 10%, transparent);
        --aid-muted: var(--gray-400, #9ca3af);
        --aid-text: #fff;
        --aid-card: var(--gray-900, #111827);
        --aid-soft: color-mix(in oklab, #fff 4%, transparent);
    }

    .aid-card {
        position: relative;
        background: var(--aid-card);
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        outline: 1px solid var(--aid-border);
        padding: 1.5rem;
        overflow: hidden;
    }

    .aid-muted { color: var(--aid-muted); }
    .aid-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .aid-row { display: flex; align-items: center; gap: 0.75rem; }
    .aid-between { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .aid-h2 { font-size: 1.125rem; font-weight: 600; line-height: 1.5; }
    .aid-h3 { font-size: 0.95rem; font-weight: 600; }
    .aid-small { font-size: 0.8rem; }
    .aid-ico { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
    .aid-ico-sm { width: 1rem; height: 1rem; flex-shrink: 0; }

    .aid-code {
        max-height: 60vh;
        overflow: auto;
        padding: 1rem;
        border-radius: 0.5rem;
        background: var(--gray-950, #030712);
        color: var(--gray-100, #f3f4f6);
        font: 0.78rem/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* ---------- Hero ---------- */
    .aid-hero {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 1.75rem;
        align-items: center;
        isolation: isolate;
    }

    .aid-hero::before {
        content: "";
        position: absolute;
        inset: -40%;
        z-index: -1;
        background: conic-gradient(from 0deg, transparent 0deg, color-mix(in oklab, var(--aid-primary) 18%, transparent) 60deg, transparent 140deg, color-mix(in oklab, var(--aid-info) 14%, transparent) 220deg, transparent 300deg);
        animation: aid-spin 18s linear infinite;
        opacity: 0.7;
    }

    .aid-hero::after {
        content: "";
        position: absolute;
        inset: 1px;
        z-index: -1;
        border-radius: inherit;
        background: var(--aid-card);
        opacity: 0.86;
    }

    @media (max-width: 640px) {
        .aid-hero { grid-template-columns: 1fr; text-align: center; }
        .aid-hero .aid-bot { margin: 0 auto; }
        .aid-flow { justify-content: center; }
    }

    .aid-bot {
        width: 112px;
        height: 112px;
        animation: aid-float 4s ease-in-out infinite;
        filter: drop-shadow(0 12px 18px color-mix(in oklab, var(--aid-primary) 35%, transparent));
    }

    .aid-bot .aid-bot-head { fill: var(--aid-primary); }
    .aid-bot .aid-bot-face { fill: var(--aid-card); }
    .aid-bot .aid-bot-eye { fill: var(--aid-primary); transform-box: fill-box; transform-origin: center; animation: aid-blink 5s infinite; }
    .aid-bot .aid-bot-antenna { fill: var(--aid-warning); animation: aid-glow 1.6s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
    .aid-bot .aid-bot-scan { fill: color-mix(in oklab, var(--aid-info) 55%, transparent); animation: aid-scan 2.8s ease-in-out infinite; }
    .aid-bot .aid-bot-wrench { fill: var(--aid-muted); transform-box: fill-box; transform-origin: 20% 80%; animation: aid-wrench 2.4s ease-in-out infinite; }

    .aid-hero-title {
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        background: linear-gradient(90deg, var(--aid-text), var(--aid-primary), var(--aid-info), var(--aid-text));
        background-size: 300% 100%;
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        animation: aid-gradient 8s ease-in-out infinite;
    }

    .aid-flow { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.9rem; }

    .aid-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 500;
        background: color-mix(in oklab, var(--aid-primary) 10%, transparent);
        color: var(--aid-primary);
        opacity: 0;
        animation: aid-pop 0.5s cubic-bezier(.2, .9, .3, 1.4) forwards;
    }

    .aid-chip-arrow { color: var(--aid-muted); opacity: 0; animation: aid-fade 0.4s forwards; }

    .aid-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
        margin-top: 1.25rem;
    }

    .aid-stat {
        padding: 0.85rem 1rem;
        border-radius: 0.6rem;
        background: var(--aid-soft);
        outline: 1px solid var(--aid-border);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .aid-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -12px color-mix(in oklab, var(--aid-primary) 60%, transparent); }
    .aid-stat-value { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .aid-stat-label { font-size: 0.75rem; color: var(--aid-muted); text-transform: uppercase; letter-spacing: 0.04em; }

    .aid-banner {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        margin-top: 1.25rem;
        padding: 0.85rem 1rem;
        border-radius: 0.6rem;
        background: color-mix(in oklab, var(--aid-info) 10%, transparent);
        outline: 1px solid color-mix(in oklab, var(--aid-info) 30%, transparent);
        text-decoration: none;
        animation: aid-slide-in 0.5s ease both;
    }

    .aid-banner--warning {
        background: color-mix(in oklab, var(--aid-warning) 12%, transparent);
        outline-color: color-mix(in oklab, var(--aid-warning) 35%, transparent);
    }

    .aid-banner:hover { background: color-mix(in oklab, var(--aid-info) 16%, transparent); }

    /* ---------- Status header ---------- */
    .aid-status-head { display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap; }

    .aid-orb {
        --c: var(--aid-primary);
        position: relative;
        display: grid;
        place-items: center;
        width: 64px;
        height: 64px;
        border-radius: 999px;
        color: #fff;
        background: radial-gradient(circle at 30% 30%, color-mix(in oklab, var(--c) 70%, #fff), var(--c));
        box-shadow: 0 10px 25px -10px var(--c);
        flex-shrink: 0;
    }

    .aid-orb svg { width: 30px; height: 30px; }
    .aid-orb--info { --c: var(--aid-info); }
    .aid-orb--success { --c: var(--aid-success); animation: aid-pop 0.6s cubic-bezier(.2, .9, .3, 1.5) both; }
    .aid-orb--danger { --c: var(--aid-danger); animation: aid-shake 0.6s ease both; }
    .aid-orb--warning { --c: var(--aid-warning); }
    .aid-orb--gray { --c: var(--gray-400, #9ca3af); }

    .aid-orb--active::before,
    .aid-orb--active::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        border: 2px solid var(--c);
        animation: aid-ripple 2s cubic-bezier(0, 0.4, 0.4, 1) infinite;
    }

    .aid-orb--active::after { animation-delay: 1s; }
    .aid-orb--active svg { animation: aid-breathe 2s ease-in-out infinite; }

    .aid-check path { stroke-dasharray: 30; stroke-dashoffset: 30; animation: aid-draw 0.6s 0.25s ease forwards; }

    .aid-status-label { font-size: 1.35rem; font-weight: 700; }

    /* Stepper */
    .aid-steps { display: flex; align-items: flex-start; margin-top: 1.75rem; }

    .aid-step {
        position: relative;
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 0.5rem;
        font-size: 0.78rem;
        color: var(--aid-muted);
    }

    .aid-step:not(:last-child)::after {
        content: "";
        position: absolute;
        top: 17px;
        left: calc(50% + 22px);
        right: calc(-50% + 22px);
        height: 3px;
        border-radius: 3px;
        background: var(--aid-border);
    }

    .aid-step:not(:last-child)::before {
        content: "";
        position: absolute;
        z-index: 1;
        top: 17px;
        left: calc(50% + 22px);
        height: 3px;
        width: 0;
        border-radius: 3px;
        background: linear-gradient(90deg, var(--aid-success), var(--aid-primary));
        transition: width 0.8s ease;
    }

    .aid-step--done:not(:last-child)::before { width: calc(100% - 44px); }

    .aid-step--current:not(:last-child)::before {
        width: calc(100% - 44px);
        background: linear-gradient(90deg, var(--aid-primary) 0%, transparent 0%, transparent 100%);
        background: repeating-linear-gradient(90deg, var(--aid-primary) 0 10px, transparent 10px 20px);
        background-size: 40px 3px;
        animation: aid-march 1s linear infinite;
        opacity: 0.6;
    }

    .aid-step-dot {
        position: relative;
        z-index: 2;
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border-radius: 999px;
        background: var(--aid-card);
        border: 2px solid var(--aid-border);
        color: var(--aid-muted);
        transition: all 0.4s ease;
    }

    .aid-step-dot svg { width: 18px; height: 18px; }

    .aid-step--done .aid-step-dot { background: var(--aid-success); border-color: var(--aid-success); color: #fff; animation: aid-pop 0.45s ease both; }
    .aid-step--done { color: var(--aid-text); }

    .aid-step--current .aid-step-dot { border-color: var(--aid-primary); color: var(--aid-primary); box-shadow: 0 0 0 0 color-mix(in oklab, var(--aid-primary) 50%, transparent); animation: aid-pulse 1.8s infinite; }
    .aid-step--current { color: var(--aid-primary); font-weight: 600; }
    .aid-step--current .aid-step-dot svg { animation: aid-spin 2.4s linear infinite; }

    .aid-step--error .aid-step-dot { background: var(--aid-danger); border-color: var(--aid-danger); color: #fff; animation: aid-shake 0.6s ease both; }
    .aid-step--error { color: var(--aid-danger); font-weight: 600; }

    @media (max-width: 640px) {
        .aid-step-label { display: none; }
    }

    /* Progress shimmer */
    .aid-progress {
        position: absolute;
        left: 0;
        right: 0;
        top: 0;
        height: 3px;
        overflow: hidden;
        background: color-mix(in oklab, var(--aid-primary) 15%, transparent);
    }

    .aid-progress::after {
        content: "";
        position: absolute;
        inset: 0;
        width: 40%;
        background: linear-gradient(90deg, transparent, var(--aid-primary), var(--aid-info), transparent);
        animation: aid-indeterminate 1.6s ease-in-out infinite;
    }

    /* Layout */
    .aid-columns { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 1.5rem; align-items: start; }

    @media (max-width: 1180px) {
        .aid-columns { grid-template-columns: minmax(0, 1fr); }
    }

    .aid-side { display: flex; flex-direction: column; gap: 1.5rem; }

    /* ---------- Live log ---------- */
    .aid-live {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--aid-danger);
    }

    .aid-live::before {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: currentColor;
        animation: aid-blink-dot 1.2s ease-in-out infinite;
    }

    .aid-log {
        position: relative;
        margin-top: 1rem;
        max-height: 70vh;
        overflow-y: auto;
        padding-right: 0.25rem;
        scroll-behavior: smooth;
    }

    .aid-log::before {
        content: "";
        position: absolute;
        top: 0.75rem;
        bottom: 0.75rem;
        left: 15px;
        width: 2px;
        background: linear-gradient(var(--aid-border), var(--aid-border) 80%, transparent);
    }

    .aid-entry {
        position: relative;
        display: grid;
        grid-template-columns: 32px minmax(0, 1fr);
        gap: 0.75rem;
        padding: 0.45rem 0;
        animation: aid-slide-in 0.45s cubic-bezier(.2, .8, .2, 1) both;
    }

    .aid-entry-icon {
        --c: var(--aid-muted);
        position: relative;
        z-index: 1;
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        border-radius: 999px;
        background: var(--aid-card);
        color: var(--c);
        outline: 2px solid color-mix(in oklab, var(--c) 35%, transparent);
    }

    .aid-entry-icon svg { width: 16px; height: 16px; }

    .aid-entry--success .aid-entry-icon { --c: var(--aid-success); }
    .aid-entry--warning .aid-entry-icon { --c: var(--aid-warning); }
    .aid-entry--danger .aid-entry-icon { --c: var(--aid-danger); }
    .aid-entry--primary .aid-entry-icon { --c: var(--aid-primary); background: color-mix(in oklab, var(--aid-primary) 12%, var(--aid-card)); }
    .aid-entry--info .aid-entry-icon { --c: var(--aid-info); }

    .aid-entry--running .aid-entry-icon::after {
        content: "";
        position: absolute;
        inset: -4px;
        border-radius: inherit;
        border: 2px solid transparent;
        border-top-color: var(--aid-primary);
        border-right-color: var(--aid-primary);
        animation: aid-spin 0.9s linear infinite;
    }

    .aid-entry-body { min-width: 0; padding-top: 0.3rem; }
    .aid-entry-title { font-size: 0.875rem; font-weight: 500; word-break: break-word; }
    .aid-entry-time { font-size: 0.72rem; color: var(--aid-muted); white-space: nowrap; font-variant-numeric: tabular-nums; }

    .aid-entry--thinking .aid-entry-title { font-style: italic; color: var(--aid-muted); font-weight: 400; }

    .aid-bubble {
        margin-top: 0.4rem;
        padding: 0.75rem 1rem;
        border-radius: 0.2rem 0.75rem 0.75rem 0.75rem;
        background: color-mix(in oklab, var(--aid-primary) 8%, transparent);
        outline: 1px solid color-mix(in oklab, var(--aid-primary) 20%, transparent);
        font-size: 0.875rem;
        line-height: 1.6;
        word-break: break-word;
    }

    .aid-prose p { margin: 0 0 0.5rem; }
    .aid-prose p:last-child { margin-bottom: 0; }
    .aid-prose ul, .aid-prose ol { margin: 0.25rem 0 0.5rem 1.25rem; list-style: disc; }
    .aid-prose ol { list-style: decimal; }
    .aid-prose code { font-family: ui-monospace, monospace; font-size: 0.8em; padding: 0.1rem 0.3rem; border-radius: 0.25rem; background: var(--aid-soft); outline: 1px solid var(--aid-border); }
    .aid-prose strong { font-weight: 600; }
    .aid-prose h1, .aid-prose h2, .aid-prose h3 { font-weight: 600; margin: 0.5rem 0 0.25rem; }

    .aid-details summary {
        cursor: pointer;
        list-style: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        margin-top: 0.25rem;
        font-size: 0.75rem;
        color: var(--aid-muted);
        user-select: none;
    }

    .aid-details summary::-webkit-details-marker { display: none; }
    .aid-details summary::before { content: "▸"; display: inline-block; transition: transform 0.2s ease; }
    .aid-details[open] summary::before { transform: rotate(90deg); }
    .aid-details[open] > :not(summary) { animation: aid-expand 0.3s ease both; }
    .aid-details .aid-code { margin-top: 0.4rem; max-height: 320px; }

    .aid-tag {
        display: inline-flex;
        align-items: center;
        padding: 0.05rem 0.45rem;
        border-radius: 0.3rem;
        font-size: 0.7rem;
        font-weight: 600;
        background: var(--aid-soft);
        outline: 1px solid var(--aid-border);
        color: var(--aid-muted);
    }

    .aid-typing { display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0 0.2rem 2.75rem; font-size: 0.8rem; color: var(--aid-muted); }
    .aid-typing span.aid-dots { display: inline-flex; gap: 4px; }
    .aid-typing span.aid-dots i { width: 6px; height: 6px; border-radius: 999px; background: var(--aid-primary); animation: aid-bounce 1.2s infinite ease-in-out; }
    .aid-typing span.aid-dots i:nth-child(2) { animation-delay: 0.15s; }
    .aid-typing span.aid-dots i:nth-child(3) { animation-delay: 0.3s; }

    .aid-more { display: block; width: 100%; padding: 0.4rem; margin-bottom: 0.5rem; border-radius: 0.5rem; font-size: 0.8rem; color: var(--aid-primary); background: var(--aid-soft); }

    .aid-jump {
        position: sticky;
        bottom: 0.5rem;
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 0.3rem;
        width: max-content;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        font-size: 0.75rem;
        color: #fff;
        background: var(--aid-primary);
        box-shadow: 0 6px 16px -6px var(--aid-primary);
        animation: aid-pop 0.3s ease both;
    }

    /* ---------- Report ---------- */
    .aid-report-ok {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.85rem 1rem;
        border-radius: 0.6rem;
        font-weight: 600;
        background: color-mix(in oklab, var(--aid-success) 12%, transparent);
        color: var(--aid-success);
    }

    .aid-report-ok--bad { background: color-mix(in oklab, var(--aid-warning) 14%, transparent); color: var(--aid-warning); }
    .aid-report-ok svg { width: 28px; height: 28px; flex-shrink: 0; }

    .aid-list { display: flex; flex-direction: column; gap: 0.45rem; margin-top: 0.6rem; }

    .aid-list li {
        display: grid;
        grid-template-columns: 1.1rem 1fr;
        gap: 0.5rem;
        font-size: 0.85rem;
        line-height: 1.45;
        opacity: 0;
        animation: aid-slide-in 0.4s ease forwards;
    }

    .aid-list li svg { width: 1.1rem; height: 1.1rem; margin-top: 0.1rem; }
    .aid-list--success svg { color: var(--aid-success); }
    .aid-list--warning svg { color: var(--aid-warning); }
    .aid-list--info svg { color: var(--aid-info); }

    .aid-kv { display: grid; grid-template-columns: auto 1fr; gap: 0.4rem 1rem; font-size: 0.85rem; margin-top: 0.75rem; }
    .aid-kv dt { color: var(--aid-muted); }
    .aid-kv dd { text-align: right; font-weight: 500; word-break: break-word; }

    .aid-error {
        margin-top: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem;
        background: color-mix(in oklab, var(--aid-danger) 10%, transparent);
        color: var(--aid-danger);
        font-size: 0.85rem;
        word-break: break-word;
    }

    /* Confetti on success */
    .aid-confetti { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
    .aid-confetti i {
        position: absolute;
        top: -12px;
        width: 8px;
        height: 14px;
        border-radius: 2px;
        opacity: 0;
        animation: aid-confetti 2.6s ease-out forwards;
    }

    /* ---------- Keyframes ---------- */
    @keyframes aid-spin { to { transform: rotate(360deg); } }
    @keyframes aid-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
    @keyframes aid-blink { 0%, 92%, 100% { transform: scaleY(1); } 95% { transform: scaleY(0.1); } }
    @keyframes aid-glow { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.8); } }
    @keyframes aid-scan { 0%, 100% { transform: translateY(0); opacity: 0; } 15% { opacity: 1; } 50% { transform: translateY(22px); opacity: 1; } 85% { opacity: 0; } }
    @keyframes aid-wrench { 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(-25deg); } }
    @keyframes aid-gradient { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
    @keyframes aid-pop { 0% { opacity: 0; transform: scale(0.6); } 100% { opacity: 1; transform: scale(1); } }
    @keyframes aid-fade { to { opacity: 1; } }
    @keyframes aid-slide-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes aid-expand { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes aid-ripple { 0% { transform: scale(1); opacity: 0.9; } 100% { transform: scale(1.8); opacity: 0; } }
    @keyframes aid-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(0.88); } }
    @keyframes aid-draw { to { stroke-dashoffset: 0; } }
    @keyframes aid-shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }
    @keyframes aid-pulse { 0% { box-shadow: 0 0 0 0 color-mix(in oklab, var(--aid-primary) 50%, transparent); } 70% { box-shadow: 0 0 0 10px transparent; } 100% { box-shadow: 0 0 0 0 transparent; } }
    @keyframes aid-march { to { background-position: 40px 0; } }
    @keyframes aid-indeterminate { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
    @keyframes aid-blink-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }
    @keyframes aid-bounce { 0%, 80%, 100% { transform: translateY(0); opacity: 0.4; } 40% { transform: translateY(-6px); opacity: 1; } }
    @keyframes aid-confetti { 0% { opacity: 1; transform: translateY(0) rotate(0deg); } 100% { opacity: 0; transform: translateY(260px) rotate(720deg); } }

    @media (prefers-reduced-motion: reduce) {
        .aid *, .aid *::before, .aid *::after, .aid::before, .aid::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>
@endonce
