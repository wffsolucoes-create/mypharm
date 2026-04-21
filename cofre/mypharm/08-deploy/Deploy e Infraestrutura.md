---
tags: [deploy, infraestrutura, hostinger, xampp]
---

# Deploy e Infraestrutura

## Ambientes

| | Local (Dev) | Produção |
|---|---|---|
| **Servidor** | XAMPP (Apache 2.4 + PHP 8.2) | Hostinger |
| **URL** | `http://localhost/mypharm/` | `https://seudominio.com/` |
| **Banco** | `mypharm_db` (local) | `u936212550_my_pharm` |
| **DB Host** | `localhost` | `srv1845.hstgr.io` |

## Trocar Ambiente no .env

```env
# PRODUÇÃO (ativo):
DB_HOST=srv1845.hstgr.io
DB_NAME=u936212550_my_pharm
DB_USER=u936212550_my_pharm
DB_PASS=senha

# LOCAL (descomentar para dev local):
# DB_HOST=localhost
# DB_NAME=mypharm_db
# DB_USER=root
# DB_PASS=
```

## Estrutura no Hostinger

```
public_html/
└── mypharm/
    ├── .htaccess
    ├── .env
    ├── api.php
    ├── *.html
    ├── js/
    ├── api/
    ├── tv/          ← React app buildada
    │   ├── .htaccess
    │   ├── dist/
    │   └── api/
    └── uploads/
        └── avatars/
```

## TV Ranking

### Desenvolvimento local
```bash
cd /c/xampp/htdocs/mypharm/tv
npm run dev        # Vite dev server (porta 5173)
# ou para testar via Apache:
npm run build      # gera dist/
# acessar: http://localhost/mypharm/tv/
```

### Deploy
```bash
npm run build
# Fazer upload da pasta tv/ inteira para o Hostinger
# .htaccess já configurado para servir dist/
```

### Configuração do base no Vite
```typescript
// vite.config.ts
base: '/mypharm/tv/'   // local XAMPP
// base: '/'           // Hostinger (raiz do domínio)
```

> ⚠️ Após mudar o `base`, fazer `npm run build` obrigatoriamente.

## .htaccess Principal

Responsabilidades:
- CSP headers
- Bloquear acesso a `.env`, `.sql`, logs
- Rewrite rules para SPA e APIs
- Compressão gzip
- Cache de assets estáticos

## TV .htaccess

```apache
RewriteBase /mypharm/tv/
RewriteRule ^favicon\.svg$ dist/favicon.svg [L]
RewriteRule ^assets/(.*)$ dist/assets/$1 [L]
RewriteRule ^audio/(.*)$ dist/audio/$1 [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ dist/index.html [L]
DirectoryIndex dist/index.html
```

## Links Relacionados
- [[Variaveis de Ambiente]]
- [[Seguranca]]
- [[Modulo TV Ranking]]
