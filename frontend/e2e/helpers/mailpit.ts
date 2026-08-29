export interface MailpitMessage {
  ID: string;
  To: { Address: string }[];
  Subject: string;
  Text: string;
  HTML: string;
}

const baseUrl = (): string => {
  const url = process.env.E2E_MAILPIT_URL;
  if (!url) throw new Error('E2E_MAILPIT_URL is not set');
  return url.replace(/\/$/, '');
};

/** Clear all messages in the Mailpit mailbox. */
export async function clearMailbox(): Promise<void> {
  const res = await fetch(`${baseUrl()}/api/v1/messages`, {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({}),
  });
  if (!res.ok) {
    throw new Error(`clearMailbox failed: ${res.status} ${res.statusText}`);
  }
}

/** Poll until a message arrives for `to`, or throw on timeout. */
export async function waitForMessage(
  to: string,
  opts: { timeoutMs?: number } = {},
): Promise<MailpitMessage> {
  const timeoutMs = opts.timeoutMs ?? 10_000;
  const pollIntervalMs = 500;
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const res = await fetch(
      `${baseUrl()}/api/v1/search?q=${encodeURIComponent(`to:${to}`)}`,
    );
    if (!res.ok) {
      throw new Error(`waitForMessage search failed: ${res.status} ${res.statusText}`);
    }
    const data = (await res.json()) as { messages: MailpitMessage[] | null };
    const messages = data.messages ?? [];
    if (messages.length > 0) {
      // Fetch the full message body
      const msgId = messages[0].ID;
      const msgRes = await fetch(`${baseUrl()}/api/v1/message/${msgId}`);
      if (!msgRes.ok) {
        throw new Error(`waitForMessage fetch failed: ${msgRes.status} ${msgRes.statusText}`);
      }
      return msgRes.json() as Promise<MailpitMessage>;
    }
    await new Promise((resolve) => setTimeout(resolve, pollIntervalMs));
  }

  // Fetch current count for the error message
  const res = await fetch(
    `${baseUrl()}/api/v1/search?q=${encodeURIComponent(`to:${to}`)}`,
  );
  const data = res.ok ? ((await res.json()) as { messages: MailpitMessage[] | null }) : { messages: null };
  const count = (data.messages ?? []).length;
  throw new Error(
    `waitForMessage timed out after ${timeoutMs}ms for <${to}>; found ${count} message(s)`,
  );
}

/** Extract the ?token= (or &token=) query param from a Mailpit message body. */
export function extractLinkToken(msg: MailpitMessage): string {
  const body = msg.Text || msg.HTML;
  const match = body.match(/[?&]token=([A-Za-z0-9._~-]+)/);
  if (!match) {
    throw new Error('extractLinkToken: no token found in message body');
  }
  return match[1];
}
