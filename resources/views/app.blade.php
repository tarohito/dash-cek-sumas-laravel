<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="apple-touch-icon" href="/favicon.svg" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Kode+Mono:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <title>Sumas Dashboard</title>
    <meta name="description" content="Dashboard Monitoring Geolokasi Pemindaian &amp; Manajemen Tag Fisik Sumas" />
    <meta property="og:title" content="Sumas Dashboard" />
    <meta property="og:description" content="Dashboard Monitoring Geolokasi Pemindaian &amp; Manajemen Tag Fisik Sumas" />
    <meta property="og:type" content="website" />
    <meta name="twitter:card" content="summary_large_image" />
    <script>
      (function() {
        try {
          var saved = localStorage.getItem('sumas_theme');
          var isDark = saved === 'dark' || (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
          if (isDark) {
            document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = 'dark';
          } else {
            document.documentElement.classList.remove('dark');
            document.documentElement.style.colorScheme = 'light';
          }
        } catch (_) {}
      })();
    </script>
    <style>
      #sumas-boot-splash {
        position: relative;
        min-height: 100dvh;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 0 24px;
        background: #f8fafc;
        color: #334155;
        font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, sans-serif;
        user-select: none;
        overflow: hidden;
      }
      html.dark #sumas-boot-splash {
        background: #000000;
        color: #d4d4d8;
      }
      .sumas-boot-dots {
        pointer-events: none;
        position: absolute;
        inset: 0;
        opacity: 0.38;
        background-image: radial-gradient(circle, rgba(194, 142, 98, 0.42) 1px, transparent 1px);
        background-size: 26px 26px;
        mask-image: radial-gradient(circle at center, black 12%, transparent 68%);
        -webkit-mask-image: radial-gradient(circle at center, black 12%, transparent 68%);
      }
      html.dark .sumas-boot-dots { opacity: 0.22; }
      .sumas-boot-inner {
        position: relative;
        z-index: 10;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 24px;
        width: 100%;
        max-width: 20rem;
        text-align: center;
      }
      .sumas-boot-seal {
        position: relative;
        width: 128px;
        height: 128px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .sumas-boot-rings {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
      }
      .sumas-boot-ring-cw {
        stroke: #cbd5e1;
        transform-origin: center;
        animation: sumas-b-cw 11s linear infinite;
      }
      html.dark .sumas-boot-ring-cw { stroke: #3f3f46; }
      .sumas-boot-ring-ccw {
        stroke: #c28e62;
        opacity: 0.85;
        transform-origin: center;
        animation: sumas-b-ccw 7.5s linear infinite;
      }
      .sumas-boot-reticle {
        position: absolute;
        width: 86px;
        height: 86px;
        pointer-events: none;
        animation: sumas-b-breathe 2.4s ease-in-out infinite;
      }
      .sumas-boot-reticle span {
        position: absolute;
        width: 14px;
        height: 14px;
        border-color: #c28e62;
        border-style: solid;
        border-width: 0;
      }
      .sumas-boot-reticle .tl { top: 0; left: 0; border-top-width: 2px; border-left-width: 2px; border-top-left-radius: 6px; }
      .sumas-boot-reticle .tr { top: 0; right: 0; border-top-width: 2px; border-right-width: 2px; border-top-right-radius: 6px; }
      .sumas-boot-reticle .bl { bottom: 0; left: 0; border-bottom-width: 2px; border-left-width: 2px; border-bottom-left-radius: 6px; }
      .sumas-boot-reticle .br { bottom: 0; right: 0; border-bottom-width: 2px; border-right-width: 2px; border-bottom-right-radius: 6px; }
      .sumas-boot-badge {
        position: relative;
        width: 64px;
        height: 64px;
        border-radius: 16px;
        background: #ffffff;
        border: 1px solid rgba(226, 232, 240, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
      }
      html.dark .sumas-boot-badge {
        background: #18181b;
        border-color: #27272a;
      }
      .sumas-boot-beam {
        position: absolute;
        left: 4px;
        right: 4px;
        top: 50%;
        margin-top: -1px;
        height: 2px;
        z-index: 20;
        border-radius: 9999px;
        background: linear-gradient(90deg, transparent 0%, #c28e62 22%, #f1cca7 50%, #c28e62 78%, transparent 100%);
        animation: sumas-b-beam 2.1s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
      }
      .sumas-boot-meta {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
      }
      .sumas-boot-wordmark {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: 0.28em;
        padding-left: 0.28em;
        color: #242156;
        line-height: 1;
      }
      html.dark .sumas-boot-wordmark { color: #ffffff; }
      .sumas-boot-pill {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 6px 14px;
        border-radius: 9999px;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(226, 232, 240, 0.9);
      }
      html.dark .sumas-boot-pill {
        background: rgba(24, 24, 27, 0.9);
        border-color: #27272a;
      }
      .sumas-boot-qr {
        display: grid;
        grid-template-columns: repeat(3, 4px);
        gap: 2.5px;
      }
      .sumas-boot-qr i {
        display: block;
        width: 4px;
        height: 4px;
        border-radius: 1px;
        background: #c28e62;
        animation: sumas-b-wave 1.5s ease-in-out infinite;
      }
      .sumas-boot-tag {
        font-family: 'Kode Mono', ui-monospace, monospace;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #475569;
      }
      html.dark .sumas-boot-tag { color: #d4d4d8; }
      .sumas-boot-msg {
        font-size: 11px;
        font-weight: 500;
        letter-spacing: 0.025em;
        color: #94a3b8;
        margin: 0;
      }
      html.dark .sumas-boot-msg { color: #71717a; }
      .sumas-boot-footer {
        position: absolute;
        bottom: 24px;
        left: 0;
        right: 0;
        text-align: center;
        padding: 0 16px;
        font-family: 'Kode Mono', ui-monospace, monospace;
        font-size: 10px;
        font-weight: 500;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: rgba(148, 163, 184, 0.75);
      }
      html.dark .sumas-boot-footer { color: #52525b; }
      @keyframes sumas-b-beam {
        0%, 100% { transform: translateY(-28px); opacity: 0.25; }
        15%, 85% { opacity: 1; }
        50% { transform: translateY(28px); opacity: 1; }
      }
      @keyframes sumas-b-cw { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
      @keyframes sumas-b-ccw { from { transform: rotate(360deg); } to { transform: rotate(0deg); } }
      @keyframes sumas-b-breathe { 0%, 100% { transform: scale(1); opacity: 0.85; } 50% { transform: scale(1.06); opacity: 1; } }
      @keyframes sumas-b-wave { 0%, 100% { opacity: 0.18; transform: scale(0.75); } 45% { opacity: 1; transform: scale(1); } }
      @media (prefers-reduced-motion: reduce) {
        .sumas-boot-ring-cw, .sumas-boot-ring-ccw, .sumas-boot-reticle, .sumas-boot-beam, .sumas-boot-qr i {
          animation: none !important;
          transform: none !important;
          opacity: 0.85 !important;
        }
      }
    </style>
    @viteReactRefresh
    @vite(['src/index.css', 'src/main.tsx'])
  </head>
  <body class="bg-[#f8fafc] dark:bg-black text-slate-800 dark:text-zinc-200 antialiased min-h-screen">
    <div id="root">
      <div id="sumas-boot-splash" role="status" aria-live="polite" aria-busy="true">
        <div class="sumas-boot-dots" aria-hidden="true"></div>
        <div class="sumas-boot-inner">
          <div class="sumas-boot-seal">
            <svg viewBox="0 0 128 128" class="sumas-boot-rings" aria-hidden="true">
              <circle cx="64" cy="64" r="58" fill="none" stroke-width="1" stroke-dasharray="3 7" class="sumas-boot-ring-cw" />
              <circle cx="64" cy="64" r="49" fill="none" stroke-width="1.5" stroke-dasharray="24 14 6 14" stroke-linecap="round" class="sumas-boot-ring-ccw" />
            </svg>
            <div class="sumas-boot-reticle" aria-hidden="true">
              <span class="tl"></span><span class="tr"></span><span class="bl"></span><span class="br"></span>
            </div>
            <div class="sumas-boot-badge">
              <img src="/favicon.svg" alt="SUMAS Emblem" width="36" height="36" style="object-fit:contain;position:relative;z-index:10;" />
              <div class="sumas-boot-beam" aria-hidden="true"></div>
            </div>
          </div>
          <div class="sumas-boot-meta">
            <div class="sumas-boot-wordmark">SUMAS</div>
            <div class="sumas-boot-pill">
              <div class="sumas-boot-qr" aria-hidden="true">
                <i style="animation-delay:0ms"></i><i style="animation-delay:120ms"></i><i style="animation-delay:240ms"></i>
                <i style="animation-delay:120ms"></i><i style="animation-delay:240ms"></i><i style="animation-delay:360ms"></i>
                <i style="animation-delay:240ms"></i><i style="animation-delay:360ms"></i><i style="animation-delay:480ms"></i>
              </div>
              <span class="sumas-boot-tag">SEAL &middot; VERIFY</span>
            </div>
            <p class="sumas-boot-msg">Memuat sistem...</p>
          </div>
        </div>
        <div class="sumas-boot-footer">SUMAS // ANTI-COUNTERFEIT TELEMETRY</div>
      </div>
    </div>
  </body>
</html>
