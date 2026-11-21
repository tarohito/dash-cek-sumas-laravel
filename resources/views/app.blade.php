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
      .sumas-boot-inner {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
        width: 100%;
        max-width: 20rem;
        text-align: center;
      }
      .sumas-boot-badge {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        background: #ffffff;
        border: 1px solid rgba(226, 232, 240, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
      }
      html.dark .sumas-boot-badge {
        background: #18181b;
        border-color: #27272a;
      }
      .sumas-boot-wordmark {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: 0.24em;
        padding-left: 0.24em;
        color: #242156;
        line-height: 1;
      }
      html.dark .sumas-boot-wordmark {
        color: #ffffff;
      }
      .sumas-boot-track {
        width: 176px;
        height: 3px;
        border-radius: 9999px;
        background: rgba(226, 232, 240, 0.9);
        overflow: hidden;
        position: relative;
        margin-top: 4px;
      }
      html.dark .sumas-boot-track {
        background: #27272a;
      }
      .sumas-boot-bar {
        position: absolute;
        top: 0;
        bottom: 0;
        left: 0;
        width: 40%;
        border-radius: 9999px;
        background: linear-gradient(90deg, #b07d53, #d8aa7e, #c28e62);
        animation: sumas-boot-slide 1.45s cubic-bezier(0.4, 0, 0.2, 1) infinite;
      }
      .sumas-boot-msg {
        font-size: 11px;
        font-weight: 500;
        letter-spacing: 0.025em;
        color: #94a3b8;
        margin: 0;
      }
      html.dark .sumas-boot-msg {
        color: #71717a;
      }
      .sumas-boot-footer {
        position: absolute;
        bottom: 24px;
        left: 0;
        right: 0;
        text-align: center;
        padding: 0 16px;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: rgba(148, 163, 184, 0.75);
      }
      html.dark .sumas-boot-footer {
        color: #52525b;
      }
      @keyframes sumas-boot-slide {
        0% { transform: translateX(-110%); }
        50% { transform: translateX(95%); }
        100% { transform: translateX(260%); }
      }
      @media (prefers-reduced-motion: reduce) {
        .sumas-boot-bar { animation: none; width: 100%; transform: none; }
      }
    </style>
    @viteReactRefresh
    @vite(['src/index.css', 'src/main.tsx'])
  </head>
  <body class="bg-[#f8fafc] dark:bg-black text-slate-800 dark:text-zinc-200 antialiased min-h-screen">
    <div id="root">
      <div id="sumas-boot-splash" role="status" aria-live="polite" aria-busy="true">
        <div class="sumas-boot-inner">
          <div class="sumas-boot-badge">
            <img src="/favicon.svg" alt="SUMAS Emblem" width="36" height="36" style="object-fit:contain;" />
          </div>
          <div class="sumas-boot-wordmark">SUMAS</div>
          <div class="sumas-boot-track">
            <div class="sumas-boot-bar"></div>
          </div>
          <p class="sumas-boot-msg">Memuat dashboard...</p>
        </div>
        <div class="sumas-boot-footer">SUMAS &middot; Monitoring &amp; Otentikasi Tag</div>
      </div>
    </div>
  </body>
</html>
