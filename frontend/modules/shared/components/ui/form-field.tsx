import type { ReactNode } from 'react';
import { Label } from './label';

export type FormFieldProps = {
  name: string;
  label: string;
  error?: string;
  children: ReactNode;
};

export function FormField({ name, label, error, children }: FormFieldProps) {
  const errorId = `${name}-error`;

  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={name}>{label}</Label>
      {children}
      {error ? (
        <p id={errorId} role="alert" className="text-sm text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}
