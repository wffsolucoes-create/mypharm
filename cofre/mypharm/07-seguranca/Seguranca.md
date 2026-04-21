---
tags: [seguranca, csp, csrf, sessao]
---

# Segurança

## Content Security Policy (CSP)

Definida no `.htaccess` da raiz. Aplicada a todos os arquivos `.html`.

```apache
Content-Security-Policy:
  default-src 'self';
  script-src  'self' 'unsafe-inline' 'unsafe-eval'
              https://cdn.jsdelivr.net
              https://cdnjs.cloudflare.com
              https://unpkg.com;
  style-src   'self' 'unsafe-inline'
              https://cdnjs.cloudflare.com
              https://unpkg.com
              https://fonts.googleapis.com;
  font-src    'self'
              https://cdnjs.cloudflare.com
              https://fonts.gstatic.com;
  img-src     'self' data:
              https://*.tile.openstreetmap.org
              https://*.basemaps.cartocdn.com
              https://unpkg.com
              https://i.pravatar.cc
              https://www.gravatar.com
              https://secure.gravatar.com
              https://ui-avatars.com;
  connect-src 'self'
              https://cdn.jsdelivr.net
              https://cdnjs.cloudflare.com
              https://unpkg.com
              https://viacep.com.br
              https://servicodados.ibge.gov.br
              https://nominatim.openstreetmap.org;
```

> ⚠️ Se adicionar novas fontes de imagem (avatares, CDNs), adicionar ao `img-src`.

## Proteção de Arquivos Sensíveis

```apache
# .htaccess raiz
<FilesMatch "\.(env|sql|log|bak|json)$">
    Require all denied
</FilesMatch>
```

## Sessões

- 1 sessão ativa por usuário (outros dispositivos são deslogados)
- Duração máxima: 12h
- Inatividade: 5h → logout automático
- `session_regenerate_id()` no login

## Rate Limiting de Login

- Máximo 5 tentativas de login
- Bloqueio de 5 minutos após exceder
- Registrado em `auth_audit_logs`

## CSRF

- Token gerado no `$_SESSION['csrf_token']`
- Enviado no header `X-CSRF-Token`
- Validado em toda requisição POST

## Senhas

- `password_hash($senha, PASSWORD_BCRYPT)` no cadastro
- `password_verify($senha, $hash)` no login
- Nunca armazenadas em texto plano

## Credenciais

- Todas em `.env` na raiz
- `.htaccess` bloqueia acesso direto ao `.env`
- Nunca commitar credenciais no git

## Links Relacionados
- [[Fluxo de Autenticacao]]
- [[Variaveis de Ambiente]]
- [[Deploy e Infraestrutura]]
