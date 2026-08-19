import { NextResponse } from 'next/server';

import { performBffMeGet, performBffMePatch } from '@/modules/auth/services/bff-me';

export async function GET(request: Request): Promise<NextResponse> {
  const result = await performBffMeGet(request);
  return result.response;
}

export async function PATCH(request: Request): Promise<NextResponse> {
  const result = await performBffMePatch(request);
  return result.response;
}

export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ message: 'Method Not Allowed.' }, { status: 405 });
}
