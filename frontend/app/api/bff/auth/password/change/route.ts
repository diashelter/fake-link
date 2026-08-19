import { NextResponse } from 'next/server';

import { performBffPasswordChange } from '@/modules/auth/services/bff-password-change';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffPasswordChange(request);
  return result.response;
}
