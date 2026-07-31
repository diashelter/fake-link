import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { FormField } from './form-field';
import { Input } from './input';

afterEach(() => {
  cleanup();
});

describe('FormField', () => {
  it('renders label and control without error', () => {
    render(
      <FormField name="email" label="E-mail">
        <Input id="email" type="email" />
      </FormField>,
    );

    expect(screen.getByLabelText('E-mail')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows accessible error message', () => {
    render(
      <FormField name="email" label="E-mail" error="Informe um e-mail válido.">
        <Input id="email" type="email" aria-describedby="email-error" invalid />
      </FormField>,
    );

    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe('Informe um e-mail válido.');
    expect(alert.getAttribute('id')).toBe('email-error');
  });
});
