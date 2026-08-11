import { NextResponse } from 'next/server';

import { performBffLogin } from '@/modules/auth/services/bff-login';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffLogin(request);
  return result.response;
}
