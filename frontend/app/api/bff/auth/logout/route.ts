import { NextResponse } from 'next/server';

import { performBffLogout } from '@/modules/auth/services/bff-logout';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffLogout(request);
  return result.response;
}
