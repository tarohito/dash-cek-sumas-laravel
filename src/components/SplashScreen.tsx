import React from 'react';

interface SplashScreenProps {
  message?: string;
}

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
      <div className="flex flex-col items-center gap-5 w-full max-w-xs text-center">
        {/* Flat Emblem Badge */}
        <div className="w-16 h-16 rounded-2xl bg-white dark:bg-zinc-900 border border-slate-200/90 dark:border-zinc-800 flex items-center justify-center">
          <img
            src="/favicon.svg"
            alt="SUMAS Emblem"
            className="w-9 h-9 object-contain"
          />
        </div>

        {/* Crisp Brand Wordmark */}
        <span className="text-[20px] font-bold tracking-[0.24em] text-[#242156] dark:text-white leading-none pl-[0.24em]">
          SUMAS
        </span>

        {/* Smooth Gold Indeterminate Progress Bar */}
        <div className="w-44 h-[3px] rounded-full bg-slate-200/90 dark:bg-zinc-800 overflow-hidden relative mt-1">
          <div className="sumas-progress-bar absolute inset-y-0 left-0 w-2/5 rounded-full bg-gradient-to-r from-[#b07d53] via-[#d8aa7e] to-[#c28e62]" />
        </div>

        {/* Status Message */}
        <p className="text-[11px] font-medium tracking-wide text-slate-400 dark:text-zinc-500">
          {message}
        </p>
      </div>

      {/* Subtle Footer Wordmark */}
      <div className="absolute bottom-6 inset-x-0 text-center px-4">
        <span className="text-[10px] font-semibold tracking-[0.18em] uppercase text-slate-400/75 dark:text-zinc-600">
          SUMAS · Monitoring &amp; Otentikasi Tag
        </span>
      </div>
    </div>
  );
};
