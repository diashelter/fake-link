/**
 * Auth module public surface — safe types only.
 *
 * Server-only session facade (create/get/touch/rotate/destroy) must be imported
 * from `@/modules/auth/services/bff-session` in Route Handlers / server modules.
 * Do not re-export bearer crypto or session facade helpers from this barrel.
 */
export type {
  CreateSessionInput,
  CreateSessionResult,
  GetSessionResult,
  SessionContext,
  SessionEnvelope,
  SessionKind,
  SessionRecord,
} from './lib/session/types';
