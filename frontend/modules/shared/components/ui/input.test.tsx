import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Input } from './input';
import { Label } from './label';

afterEach(() => {
  cleanup();
});

describe('Input and Label', () => {
  it('associates label with input via htmlFor and id', () => {
    render(
      <>
        <Label htmlFor="email">E-mail</Label>
        <Input id="email" type="email" />
      </>,
    );

    expect(screen.getByLabelText('E-mail')).toBeTruthy();
    expect(screen.getByLabelText('E-mail').getAttribute('type')).toBe('email');
  });

  it('supports text email and password types', () => {
    const { rerender } = render(<Input aria-label="campo" type="text" />);
    expect(screen.getByLabelText('campo').getAttribute('type')).toBe('text');

    rerender(<Input aria-label="campo" type="email" />);
    expect(screen.getByLabelText('campo').getAttribute('type')).toBe('email');

    rerender(<Input aria-label="campo" type="password" />);
    expect(screen.getByLabelText('campo').getAttribute('type')).toBe('password');
  });
});
