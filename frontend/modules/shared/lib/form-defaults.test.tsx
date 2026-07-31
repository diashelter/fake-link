import { zodResolver } from '@hookform/resolvers/zod';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useForm } from 'react-hook-form';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { z } from 'zod';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { emailSchema } from '@/modules/shared/schemas/email';
import { focusFirstError, shouldBlockSubmit } from './form-defaults';

const harnessSchema = z.object({
  email: emailSchema,
});

type HarnessValues = z.infer<typeof harnessSchema>;

function EmailFormHarness({
  onSubmit,
  serverError,
}: {
  onSubmit: (values: HarnessValues) => Promise<void> | void;
  serverError?: string;
}) {
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<HarnessValues>({
    resolver: zodResolver(harnessSchema),
    defaultValues: { email: '' },
  });

  return (
    <form
      onSubmit={handleSubmit(
        async (values) => {
          if (serverError) {
            setError('email', { type: 'server', message: serverError });
            return;
          }
          await onSubmit(values);
        },
        (formErrors) => {
          focusFirstError(formErrors);
        },
      )}
      noValidate
    >
      <FormField name="email" label="E-mail" error={errors.email?.message}>
        <Input
          id="email"
          type="email"
          invalid={Boolean(errors.email)}
          aria-describedby={errors.email ? 'email-error' : undefined}
          {...register('email')}
        />
      </FormField>
      <Button type="submit" disabled={shouldBlockSubmit(isSubmitting)}>
        Enviar
      </Button>
    </form>
  );
}

afterEach(() => {
  cleanup();
});

describe('form defaults', () => {
  it('blocks submit while isSubmitting is true', () => {
    expect(shouldBlockSubmit(true)).toBe(true);
    expect(shouldBlockSubmit(false)).toBe(false);
  });

  it('focuses the first invalid field after invalid submit', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    render(<EmailFormHarness onSubmit={onSubmit} />);

    await user.click(screen.getByRole('button', { name: 'Enviar' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toMatch(/e-mail/i);
    });
    expect(document.activeElement).toBe(screen.getByLabelText('E-mail'));
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('disables submit while submitting so a second click cannot fire', async () => {
    const user = userEvent.setup();
    let release!: () => void;
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });

    const onSubmit = vi.fn(async () => {
      await gate;
    });

    render(<EmailFormHarness onSubmit={onSubmit} />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');

    const submit = screen.getByRole('button', { name: 'Enviar' });
    await user.click(submit);

    await waitFor(() => {
      expect(submit).toBeDisabled();
    });
    expect(onSubmit).toHaveBeenCalledTimes(1);

    await user.click(submit);
    expect(onSubmit).toHaveBeenCalledTimes(1);

    release();
    await waitFor(() => {
      expect(submit).not.toBeDisabled();
    });
  });

  it('shows injected server-side field error without stack traces', async () => {
    const user = userEvent.setup();
    render(<EmailFormHarness onSubmit={vi.fn()} serverError="Este e-mail já está em uso." />);

    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe('Este e-mail já está em uso.');
    });
    expect(screen.queryByText(/Error:|at Object|stack/i)).toBeNull();
  });
});
