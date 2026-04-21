---
tags: [decisoes, arquitetura, adr]
---

# Decisões de Arquitetura

## PHP sem framework

**Decisão:** Usar PHP puro com PDO, sem Laravel/Symfony.
**Por quê:** Sistema legado iniciado simples, equipe conhece PHP básico, XAMPP local sem complexidade de setup.
**Trade-off:** Menos estrutura, mas mais controle e zero dependências de framework.

---

## JS Vanilla no sistema principal

**Decisão:** HTML + JS puro, sem React/Vue no sistema principal.
**Por quê:** Sistema cresceu incrementalmente. Adicionar framework exigiria reescrever tudo.
**Exceção:** TV Ranking usa React porque foi criado do zero com necessidade de animações e polling complexo.

---

## TV Ranking como SPA separada

**Decisão:** TV Ranking em pasta `/tv/` separada como app React/Vite.
**Por quê:** Necessidades diferentes — animações Framer Motion, polling automático, efeitos sonoros, confetti. Não faz sentido misturar com o PHP vanilla do sistema principal.
**Integração:** API PHP do TV usa o mesmo `.env` e banco do sistema principal.

---

## RD Station API v1 (token, não OAuth)

**Decisão:** Usar API v1 com token fixo.
**Por quê:** Mais simples de implementar. API v2 requer OAuth flow mais complexo.
**Limitação:** Token exposto nos parâmetros de URL. Mitigado pelo proxy PHP (frontend nunca chama o RD Station diretamente).

---

## Cache local de 60s para TV Ranking

**Decisão:** Cachear resposta do RD Station em JSON local por 60 segundos.
**Por quê:** O frontend faz polling a cada 15s. Sem cache, cada usuário assistindo geraria 4 chamadas/minuto à API externa. Com cache, são no máximo 1/minuto independente de quantos estão assistindo.

---

## Sessão única por usuário

**Decisão:** Um usuário não pode estar logado em dois dispositivos ao mesmo tempo.
**Por quê:** Segurança e controle de acesso. Evita compartilhamento de credenciais.

---

## Timezone America/Porto_Velho

**Decisão:** Todo o sistema usa UTC-4 (Porto Velho/RO).
**Por quê:** A empresa está em Rondônia. Sem ajuste, relatórios ficavam com 1h de diferença.

---

## phusion_pedidos no banco principal

**Decisão:** A tabela de importação CSV do Phusion fica no banco `u936212550_my_pharm`.
**Antes:** Era num banco separado `u936212550_ranking`.
**Por quê:** Simplifica credenciais e manutenção. Um `.env` serve para tudo.

## Links Relacionados
- [[Arquitetura Geral]]
- [[Stack Tecnologica]]
- [[Divida Tecnica]]
