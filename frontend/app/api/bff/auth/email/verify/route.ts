import { NextResponse } from 'next/server';

import { performBffVerifyEmail } from '@/modules/auth/services/bff-verify-email';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffVerifyEmail(request);
  return result.response;
}
