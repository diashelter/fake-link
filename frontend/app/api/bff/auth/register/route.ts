import { NextResponse } from 'next/server';

import { performBffRegister } from '@/modules/auth/services/bff-register';

export async function POST(request: Request): Promise<NextResponse> {
  const result = await performBffRegister(request);
  return result.response;
}
