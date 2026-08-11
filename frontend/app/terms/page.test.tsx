import { describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

const getAuthTermsCurrentVersionMock = vi.fn(() => '2026-01');

vi.mock('@/modules/auth/lib/auth-terms', () => ({
  getAuthTermsCurrentVersion: () => getAuthTermsCurrentVersionMock(),
}));

import TermsPage from './page';

describe('TermsPage (RGR-17, BFFUI-41)', () => {
  it('renders the current Terms version from getAuthTermsCurrentVersion', async () => {
    getAuthTermsCurrentVersionMock.mockReturnValue('2026-01');

    const page = await TermsPage();
    const serialized = JSON.stringify(page);

    expect(getAuthTermsCurrentVersionMock).toHaveBeenCalled();
    expect(serialized).toContain('2026-01');
    expect(serialized).toMatch(/Termos de uso/i);
  });

  it('renders pt-BR placeholder legal copy', async () => {
    getAuthTermsCurrentVersionMock.mockReturnValue('2026-01');

    const page = await TermsPage();
    const serialized = JSON.stringify(page);

    expect(serialized).toContain('Esta é uma versão provisória dos Termos de uso');
    expect(serialized).not.toContain('Bearer');
  });

  it('renders an overridden terms version from the helper', async () => {
    getAuthTermsCurrentVersionMock.mockReturnValue('2099-12');

    const page = await TermsPage();
    const serialized = JSON.stringify(page);

    expect(serialized).toContain('2099-12');
  });
});
