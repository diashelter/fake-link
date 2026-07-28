<x-mail::message>
# Redefina sua senha

Olá {{ $recipientName }},

Recebemos um pedido para redefinir a senha da sua conta no Fake Link. Use o botão abaixo. O link contém um token de recuperação de uso único.

<x-mail::button :url="$resetUrl">
Redefinir senha
</x-mail::button>

Se o botão não funcionar, copie e cole este endereço no navegador:

{{ $resetUrl }}

Depois de abrir o link, a redefinição é concluída pela aplicação com um pedido explícito (não basta apenas abrir a URL).

Se você não solicitou esta recuperação, ignore este e-mail. Sua senha permanecerá a mesma.

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
