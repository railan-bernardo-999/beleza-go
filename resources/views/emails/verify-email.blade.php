
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verificação de Email</title>
</head>
<body>
    <h1>Olá, {{ $user->name }}!</h1>
    <p>Obrigado por se registrar em {{ config('app.name') }}.</p>
    <p>Para verificar seu email, clique no link abaixo:</p>
    <a href="{{ $verificationUrl }}">Verificar meu email</a>
    <p>Ou copie e cole o link no seu navegador:</p>
    <p>{{ $verificationUrl }}</p>
    <p>Este link expira em 24 horas.</p>
    <p>Atenciosamente,<br>{{ config('app.name') }}</p>
</body>
</html>
