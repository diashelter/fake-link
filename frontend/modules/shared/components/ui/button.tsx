import type { ButtonHTMLAttributes, ReactNode } from 'react';

type ButtonVariant = 'primary' | 'secondary' | 'destructive';

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  children: ReactNode;
};

const variantClassName: Record<ButtonVariant, string> = {
  primary: 'bg-accent text-accent-foreground hover:opacity-90',
  secondary: 'bg-transparent text-foreground ring-1 ring-foreground/20 hover:bg-foreground/5',
  destructive: 'bg-red-700 text-white hover:bg-red-800',
};

export function Button({
  type = 'button',
  variant = 'primary',
  disabled = false,
  className = '',
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled}
      className={`inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${variantClassName[variant]} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}
