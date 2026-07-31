import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Button } from './button';

afterEach(() => {
  cleanup();
});

describe('Button', () => {
  it('renders children and defaults to type button', () => {
    render(<Button>Salvar</Button>);
    const button = screen.getByRole('button', { name: 'Salvar' });
    expect(button.getAttribute('type')).toBe('button');
  });

  it('respects disabled state', () => {
    render(<Button disabled>Enviar</Button>);
    const button = screen.getByRole('button', { name: 'Enviar' });
    expect(button).toBeDisabled();
  });

  it('supports submit type and primary variant class', () => {
    render(
      <Button type="submit" variant="primary">
        Confirmar
      </Button>,
    );
    const button = screen.getByRole('button', { name: 'Confirmar' });
    expect(button.getAttribute('type')).toBe('submit');
    expect(button.className).toContain('bg-accent');
  });
});
