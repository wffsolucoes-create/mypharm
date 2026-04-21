---
tags: [deploy, env, segurança]
---

# Variáveis de Ambiente

## Arquivo `.env` (raiz do projeto)

```env
# Banco de Dados
DB_HOST=srv1845.hstgr.io
DB_NAME=u936212550_my_pharm
DB_USER=u936212550_my_pharm
DB_PASS=...
DB_CHARSET=utf8mb4

# RD Station CRM
RDSTATION_CRM_TOKEN=...
```

> 🔒 Nunca commitar este arquivo. Está no `.gitignore` e protegido pelo `.htaccess`.

## Como é lido no PHP

```php
// api/bootstrap.php e tv/api/config.php
$lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') !== false) {
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}
```

## Arquivo `tv/.env` (opcional — frontend React)

```env
VITE_API_URL=https://seudominio.com/tv/api/  # override
VITE_USE_MOCKS=false                          # true = dados mockados
```

Se não existir, o Vite usa `BASE_URL` como prefixo da API.

## Variáveis de Ambiente do Vite

| Variável | Valor em build | Uso |
|---|---|---|
| `import.meta.env.BASE_URL` | `/mypharm/tv/` | Prefixo da URL da API |
| `import.meta.env.DEV` | `false` | Detectar modo dev |
| `import.meta.env.PROD` | `true` | Detectar produção |
| `VITE_API_URL` | definida no .env | Override da URL da API |

## Links Relacionados
- [[Deploy e Infraestrutura]]
- [[Seguranca]]
