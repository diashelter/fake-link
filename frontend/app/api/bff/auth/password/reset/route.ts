import { NextResponse } from 'next/server';

import { performBffPasswordReset } from '@/modules/auth/services/bff-password-reset';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffPasswordReset(request);
  return result.response;
}
