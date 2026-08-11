export function messageForAuthError(code: string | undefined, status: number): string {
  switch (code) {
    case 'INVALID_CREDENTIALS':
      return 'E-mail ou senha incorretos.';
    case 'ACCOUNT_SUSPENDED':
      return 'Esta conta está suspensa.';
    case 'ACCOUNT_PENDING_DELETION':
      return 'Esta conta está em processo de exclusão.';
    case 'RATE_LIMIT_EXCEEDED':
      return 'Muitas tentativas. Aguarde antes de tentar novamente.';
    case 'VALIDATION_FAILED':
      return 'Verifique os campos informados.';
    default:
      break;
  }

  if (status === 504) {
    return 'Não foi possível conectar ao serviço. Tente novamente.';
  }

  if (status === 500 || status === 503) {
    return 'Algo deu errado. Tente novamente.';
  }

  return 'Algo deu errado. Tente novamente.';
}

export function formatRetryAfter(seconds: number | null): string | null {
  if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) {
    return null;
  }

  const rounded = Math.ceil(seconds);
  if (rounded < 60) {
    return `Aguarde ${rounded} segundo${rounded === 1 ? '' : 's'} antes de tentar novamente.`;
  }

  const minutes = Math.ceil(rounded / 60);
  return `Aguarde cerca de ${minutes} minuto${minutes === 1 ? '' : 's'} antes de tentar novamente.`;
}
