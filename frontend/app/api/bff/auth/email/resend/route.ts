import { NextResponse } from 'next/server';

import { performBffResendVerification } from '@/modules/auth/services/bff-resend-verification';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffResendVerification(request);
  return result.response;
}
