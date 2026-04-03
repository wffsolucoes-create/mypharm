# Conexao com RD Station CRM (via `.env`)

Este guia mostra como configurar e validar a conexao do MyPharm com o RD Station CRM.

## 1) Gerar o token no RD Station

1. Entre no RD Station CRM com um usuario com permissao de leitura nas negociacoes.
2. Va em **Menu do usuario -> Preferencias -> Token de acesso**.
3. Copie o token gerado.

## 2) Configurar no arquivo `.env`

No arquivo `.env` do projeto, preencha a variavel:

```env
RDSTATION_CRM_TOKEN=SEU_TOKEN_AQUI
```

Boas praticas:

- Nao usar aspas no valor.
- Nao deixar espacos antes/depois do `=`.
- Nao versionar o `.env` no git.

## 3) Reiniciar o ambiente local

Como o `.env` e lido no bootstrap do PHP, reinicie Apache/PHP (XAMPP) para garantir que a nova variavel foi carregada.

## 4) Testar se a conexao esta funcionando

Com o sistema logado, abra no navegador:

- `api_gestao.php?action=gestao_comercial_dashboard_rd&data_de=2026-04-01&data_ate=2026-04-30`

Resultado esperado:

- JSON com `success: true`
- Campo `fonte` igual a `rdstation_crm`

Se houver erro de token:

- `RDSTATION_CRM_TOKEN nao configurado no .env`
- ou `Token invalido (401)`

Nesses casos, confira token, permissoes no RD e reinicie o servidor.

## 5) Endpoints do projeto que usam esse token

- `api_gestao.php` (painel de gestao comercial / TV)
- `api/modules/gestao_comercial.php`
- `api/rdstation_tv.php` (proxy e processamento)

## 6) Seguranca

- Trate o token como segredo (equivalente a senha de API).
- Se o token for exposto em print/chat/log, **revogue e gere outro** no RD Station imediatamente.
- Evite compartilhar token em commits, screenshots e mensagens.

