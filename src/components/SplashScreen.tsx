import React from 'react';

interface SplashScreenProps {
  message?: string;
}

// 9-block diagonal cryptographic QR wave delays (3x3 micro matrix)
const QR_WAVE_DELAYS = [0, 120, 240, 120, 240, 360, 240, 360, 480];

export const SplashScreen: React.FC<SplashScreenProps> = ({
  message = 'Memeriksa sesi autentikasi...',
}) => {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy="true"
      className="relative min-h-[100dvh] w-full flex flex-col items-center justify-center px-6 bg-[#f8fafc] dark:bg-black text-slate-700 dark:text-zinc-300 select-none overflow-hidden"
    >
      {/* Architectural blueprint dot matrix backdrop */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.38] dark:opacity-[0.22]"
        style={{
          backgroundImage:
            'radial-gradient(circle, rgba(194, 142, 98, 0.42) 1px, transparent 1px)',
          backgroundSize: '26px 26px',
          maskImage:
            'radial-gradient(circle at center, black 12%, transparent 68%)',
          WebkitMaskImage:
            'radial-gradient(circle at center, black 12%, transparent 68%)',
        }}
      />

      <div className="relative z-10 flex flex-col items-center gap-6 w-full max-w-xs text-center">
        {/* KINETIC QR AUTHENTICITY SEAL */}
        <div className="relative w-32 h-32 flex items-center justify-center">
          {/* Dual Counter-Rotating Cryptographic Security Rings */}
          <svg
            viewBox="0 0 128 128"
            className="absolute inset-0 w-full h-full"
            aria-hidden="true"
          >
            <circle
              cx="64"
              cy="64"
              r="58"
              fill="none"
              stroke="currentColor"
              strokeWidth="1"
              strokeDasharray="3 7"
              className="sumas-orbit-cw text-slate-300 dark:text-zinc-700"
            />
            <circle
              cx="64"
              cy="64"
              r="49"
              fill="none"
              stroke="#c28e62"
              strokeWidth="1.5"
              strokeDasharray="24 14 6 14"
              strokeLinecap="round"
              className="sumas-orbit-ccw opacity-85"
            />
          </svg>

          {/* Breathing Scanner Viewfinder Corner Brackets */}
          <div className="sumas-reticle absolute w-[86px] h-[86px] pointer-events-none">
            <span className="absolute top-0 left-0 w-3.5 h-3.5 border-t-2 border-l-2 border-[#c28e62] rounded-tl-md" />
            <span className="absolute top-0 right-0 w-3.5 h-3.5 border-t-2 border-r-2 border-[#c28e62] rounded-tr-md" />
            <span className="absolute bottom-0 left-0 w-3.5 h-3.5 border-b-2 border-l-2 border-[#c28e62] rounded-bl-md" />
            <span className="absolute bottom-0 right-0 w-3.5 h-3.5 border-b-2 border-r-2 border-[#c28e62] rounded-br-md" />
          </div>

          {/* Center Emblem Core with Optical Laser Sweep */}
          <div className="relative w-16 h-16 rounded-2xl bg-white dark:bg-zinc-900 border border-slate-200/90 dark:border-zinc-800 flex items-center justify-center overflow-hidden">
            <img
              src="/favicon.svg"
              alt="SUMAS Emblem"
              className="w-9 h-9 object-contain relative z-10"
            />
            {/* Optical Gold Laser Sweep */}
            <div
              aria-hidden="true"
              className="sumas-scan-beam absolute inset-x-1 top-1/2 -mt-[1px] h-[2px] z-20 rounded-full bg-[#c28e62]"
              style={{
                background:
                  'linear-gradient(90deg, transparent 0%, #c28e62 22%, #f1cca7 50%, #c28e62 78%, transparent 100%)',
              }}
            />
          </div>
        </div>

        {/* Brand Wordmark + Telemetry Status */}
        <div className="flex flex-col items-center gap-3">
          <span className="text-[20px] font-bold tracking-[0.28em] text-[#242156] dark:text-white leading-none pl-[0.28em]">
            SUMAS
          </span>

          {/* Micro 3x3 QR Matrix Wave + Monospace Tag Pill */}
          <div className="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-white/90 dark:bg-zinc-900/90 border border-slate-200/90 dark:border-zinc-800">
            <div className="grid grid-cols-3 gap-[2.5px]" aria-hidden="true">
              {QR_WAVE_DELAYS.map((delay, idx) => (
                <span
                  key={idx}
                  className="sumas-qr-cell w-[4px] h-[4px] rounded-[1px] bg-[#c28e62]"
                  style={{ animationDelay: `${delay}ms` }}
                />
              ))}
            </div>
            <span className="font-kode-mono text-[10px] font-semibold tracking-[0.14em] uppercase text-slate-600 dark:text-zinc-300">
              SEAL · VERIFY
            </span>
          </div>

          <p className="text-[11px] font-medium tracking-wide text-slate-400 dark:text-zinc-500">
            {message}
          </p>
        </div>
      </div>

      {/* Subtle Architectural Footer */}
      <div className="absolute bottom-6 inset-x-0 text-center px-4">
        <span className="font-kode-mono text-[10px] font-medium tracking-[0.18em] uppercase text-slate-400/75 dark:text-zinc-600">
          SUMAS // ANTI-COUNTERFEIT TELEMETRY
        </span>
      </div>
    </div>
  );
};
