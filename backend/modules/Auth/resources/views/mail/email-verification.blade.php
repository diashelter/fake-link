<x-mail::message>
# Confirme seu e-mail

Olá {{ $recipientName }},

Para ativar sua conta no Fake Link, use o botão abaixo. O link contém um token de verificação de uso único.

<x-mail::button :url="$verificationUrl">
Confirmar e-mail
</x-mail::button>

Se o botão não funcionar, copie e cole este endereço no navegador:

{{ $verificationUrl }}

Depois de abrir o link, a confirmação é concluída pela aplicação com um pedido explícito de verificação (não basta apenas abrir a URL).

Se você não criou esta conta, ignore este e-mail.

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
