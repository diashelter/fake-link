import { NextResponse } from 'next/server';

import { performBffLogoutAll } from '@/modules/auth/services/bff-logout-all';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffLogoutAll(request);
  return result.response;
}
