import 'server-only';

export type UserStatus = 'pending_verification' | 'active' | 'suspended' | 'deletion_pending';

export type BffPublicUser = {
  id: string;
  name: string;
  email: string;
  status: UserStatus;
  email_verified_at: string | null;
  terms_version: string;
  terms_accepted_at: string;
  created_at: string;
  updated_at: string;
};

export type UpstreamTokenKind = 'session' | 'verification';

export type UpstreamAuthData = {
  token: string;
  token_type: 'Bearer';
  token_kind: UpstreamTokenKind;
  expires_at: string;
  user: BffPublicUser;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isUserStatus(value: unknown): value is UserStatus {
  return (
    value === 'pending_verification' ||
    value === 'active' ||
    value === 'suspended' ||
    value === 'deletion_pending'
  );
}

function isPublicUser(value: unknown): value is BffPublicUser {
  if (!isRecord(value)) {
    return false;
  }

  return (
    typeof value.id === 'string' &&
    typeof value.name === 'string' &&
    typeof value.email === 'string' &&
    isUserStatus(value.status) &&
    (value.email_verified_at === null || typeof value.email_verified_at === 'string') &&
    typeof value.terms_version === 'string' &&
    typeof value.terms_accepted_at === 'string' &&
    typeof value.created_at === 'string' &&
    typeof value.updated_at === 'string'
  );
}

function isTokenKind(value: unknown): value is UpstreamTokenKind {
  return value === 'session' || value === 'verification';
}

export function parseUpstreamAuthResponse(json: unknown): UpstreamAuthData | null {
  if (!isRecord(json) || !isRecord(json.data)) {
    return null;
  }

  const data = json.data;

  if (
    typeof data.token !== 'string' ||
    data.token.trim() === '' ||
    data.token_type !== 'Bearer' ||
    !isTokenKind(data.token_kind) ||
    typeof data.expires_at !== 'string' ||
    !isPublicUser(data.user)
  ) {
    return null;
  }

  return {
    token: data.token,
    token_type: 'Bearer',
    token_kind: data.token_kind,
    expires_at: data.expires_at,
    user: data.user,
  };
}

export function toPublicUser(user: BffPublicUser): BffPublicUser {
  return { ...user };
}

export function mapTokenKindToSessionKind(
  tokenKind: UpstreamTokenKind,
): 'session' | 'verification' {
  return tokenKind === 'verification' ? 'verification' : 'session';
}
