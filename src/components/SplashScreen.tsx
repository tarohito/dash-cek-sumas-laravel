import React from 'react';
import { SumasLogo } from './BrandLogo';

interface SplashScreenProps {
  message?: string;
}

export const SplashScreen: React.FC<SplashScreenProps> = ({
  message = 'Memuat sistem...',
}) => {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy="true"
      aria-label={message}
      className="min-h-[100dvh] w-full flex flex-col items-center justify-center px-6 bg-[#f8fafc] dark:bg-black select-none"
    >
      <div className="flex flex-col items-center gap-5">
        <SumasLogo className="h-9 sm:h-10 w-auto" />
        <div
          aria-hidden="true"
          className="w-44 h-[3px] rounded-full bg-slate-200 dark:bg-zinc-800 overflow-hidden"
        >
          <div className="sumas-loading-bar h-full w-2/5 rounded-full bg-[#c28e62]" />
        </div>
        <span className="sr-only">{message}</span>
      </div>
    </div>
  );
};
