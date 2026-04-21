---
tags: [seguranca, auth, sessao]
---

# Fluxo de Autenticação

## Regras de Sessão

| Regra | Valor |
|---|---|
| Sessões simultâneas | **1 por usuário** (kick automático no outro dispositivo) |
| Duração máxima | **12 horas** |
| Inatividade | **5 horas** sem atividade → logout |
| Rate limit login | **5 tentativas** → bloqueio de **5 minutos** |

## Fluxo de Login

```
1. POST /api.php?action=login
   → Valida rate limit (tabela auth_audit_logs)
   → Verifica usuario + senha (password_verify)
   → Registra session_token único
   → Invalida sessão anterior do mesmo usuário
   → Inicia $_SESSION com: usuario_id, nome, tipo, setor, token

2. Frontend recebe { success: true, usuario: {...} }
   → Salva em localStorage para UX
   → Redireciona para página principal do perfil
```

## Proteção CSRF

Todas as requisições POST devem incluir o token CSRF:

```javascript
// Frontend — js/app.js
const csrf = document.querySelector('meta[name="csrf-token"]').content;
fetch('/api.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
    body: JSON.stringify({ action: 'salvar', ... })
});
```

```php
// Backend — valida o token
function validateCsrf() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit(json_encode(['error' => 'CSRF inválido']));
    }
}
```

## Auditoria

Tabela `auth_audit_logs` registra:
- Tentativas de login (sucesso e falha)
- IP do cliente
- User-agent
- Timestamp

## Logout

```javascript
// Chama api.php?action=logout
// Backend: destroi $_SESSION, invalida session_token no banco
// Frontend: limpa localStorage, redireciona para login
```

## Links Relacionados
- [[Perfis de Acesso]]
- [[Seguranca]]
- [[Tabela usuarios]]
