import { NextResponse } from 'next/server';

import { performBffPasswordResetRequest } from '@/modules/auth/services/bff-password-reset-request';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffPasswordResetRequest(request);
  return result.response;
}
