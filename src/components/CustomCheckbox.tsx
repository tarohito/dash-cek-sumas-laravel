import React from 'react';
import { Check, Minus } from 'lucide-react';

export interface CustomCheckboxProps {
  checked: boolean;
  indeterminate?: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  locked?: boolean;
  id?: string;
  label?: string;
  ariaLabel?: string;
  'aria-label'?: string;
  className?: string;
}

export const CustomCheckbox: React.FC<CustomCheckboxProps> = ({
  checked,
  indeterminate = false,
  onChange,
  disabled = false,
  locked = false,
  id,
  label,
  ariaLabel,
  'aria-label': nativeAriaLabel,
  className = '',
}) => {
  const isDisabled = disabled || locked;
  const isFilled = checked || indeterminate;
  const resolvedAriaLabel = ariaLabel || nativeAriaLabel || label;

  const box = (
    <button
      type="button"
      role="checkbox"
      aria-checked={indeterminate ? 'mixed' : checked}
      aria-label={resolvedAriaLabel}
      id={id}
      disabled={isDisabled}
      onClick={(e) => {
        if (label) e.stopPropagation();
        if (!isDisabled) onChange(!checked);
      }}
      className={`w-[18px] h-[18px] rounded-[6px] inline-flex items-center justify-center shrink-0 border transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-[#c28e62]/40 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-zinc-900 select-none ${
        isFilled
          ? locked
            ? 'bg-slate-600 dark:bg-zinc-600 border-slate-600 dark:border-zinc-600 text-white'
            : 'bg-[#c28e62] border-[#b07d53] text-white'
          : 'bg-white dark:bg-zinc-900 border-slate-300 dark:border-zinc-600 hover:border-[#c28e62] dark:hover:border-[#c28e62]'
      } ${
        isDisabled
          ? 'opacity-65 cursor-not-allowed'
          : 'cursor-pointer active:scale-90'
      }`}
    >
      {indeterminate ? (
        <Minus
          size={12}
          strokeWidth={3}
          className="text-white animate-in zoom-in-75 duration-150"
        />
      ) : checked ? (
        <Check
          size={12}
          strokeWidth={3}
          className="text-white animate-in zoom-in-75 duration-150"
        />
      ) : null}
    </button>
  );

  if (!label) {
    return <span className={`inline-flex items-center justify-center ${className}`}>{box}</span>;
  }

  return (
    <div
      onClick={() => {
        if (!isDisabled) onChange(!checked);
      }}
      className={`inline-flex items-center gap-2.5 select-none ${
        isDisabled ? 'cursor-not-allowed opacity-70' : 'cursor-pointer group'
      } ${className}`}
    >
      {box}
      <span className="text-xs sm:text-sm font-medium text-slate-600 dark:text-zinc-300 group-hover:text-slate-900 dark:group-hover:text-zinc-100 transition-colors">
        {label}
      </span>
    </div>
  );
};
