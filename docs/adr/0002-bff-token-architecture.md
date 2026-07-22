# BFF protege o token da API

O frontend oficial usará o Next.js como BFF e entregará ao browser somente uma sessão opaca em cookie seguro; o token Bearer permanecerá criptografado no servidor, com chave externa ao Redis. A complexidade adicional foi aceita para impedir que JavaScript do browser tenha acesso ao token reutilizável da API.
