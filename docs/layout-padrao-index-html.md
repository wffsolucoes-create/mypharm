# Layout padrão MyPharm — referência a partir de `index.html`

Este documento descreve a estrutura visual e semântica do **admin principal** (`index.html`) e os tokens de design em `css/styles.css`, para que **outras páginas** (ex.: `clientes.html`, `gestao-comercial.html`, `visitador.html`) possam replicar o **mesmo padrão de shell** (sidebar, topo, conteúdo, rodapé, tema e feedback).

---

## 1. Objetivo

- **Unificar** largura de menu, topbar, padding de conteúdo, cards, grids e overlay de carregamento.
- **Declarar** os nomes de classes e IDs usados no `index` como **contrato** ao portar telas standalone.
- **Evitar** divergências (margens diferentes, sidebar com outra largura, cores fora do tema).

---

## 2. Ficheiros base

| Ficheiro | Função |
|----------|--------|
| `index.html` | Shell completo: login (opcional), `appLayout`, sidebar, topbar, secções internas (`section-page`), modais globais. |
| `css/styles.css` | **Design system**: variáveis `:root` / `[data-theme="light"]`, layout (`.app-layout`, `.sidebar`, `.main-content`, `.topbar`), componentes (KPI, gráficos, tabelas, loading). |
| `css/ui-feedback.css` | Toasts / feedback UI (se usado na página). |
| `css/login.css` | Estilos específicos do bloco de login quando aplicável. |
| Fonte | **Inter** (Google Fonts), importada em `styles.css`. |
| Ícones | **Font Awesome 6** (CDN em `index.html`). |

Páginas “filhas” que **não** são o SPA do `index` devem incluir no mínimo: `styles.css` + Font Awesome + `data-theme` no `<html>` alinhado ao resto do sistema.

---

## 3. Estrutura de alto nível do documento

```
html[data-theme="light|dark"]
└── body
    ├── #loginPage                    — tela de login (quando visível)
    ├── #forcePasswordModal           — overlay troca obrigatória de senha (z-index alto)
    └── #appLayout.app-layout        — aplicação autenticada (flex; min-height 100vh)
        ├── #sidebarOverlay.sidebar-overlay
        └── aside#sidebar.sidebar    — menu lateral (fixed)
        └── div.main-content         — coluna principal (margin-left = largura sidebar)
            ├── div.topbar
            ├── div#page-XXX.section-page — uma ou mais “páginas” (só .active visível)
            │   └── div.page-content
            └── footer.app-footer
```

No `index.html`, **várias** áreas coexistem em `main-content`: cada `div#page-<nome>.section-page` contém um módulo; apenas a classe `.active` define a secção visível (`display: block` vs `none`).

---

## 4. Shell: `.app-layout`

- **Classe:** `.app-layout`
- **ID recomendado:** `#appLayout` (no `index`; páginas espelho podem usar `#gcApp` ou outro ID, mantendo a **classe** `.app-layout`).
- **CSS:** `display: flex`, `min-height: 100vh`, `width: 100%`, `overflow-x: hidden`.

Toda a área autenticada fica dentro deste wrapper.

---

## 5. Sidebar (`aside.sidebar`)

### 5.1 Dimensões e posição

| Token / regra | Valor |
|----------------|--------|
| `--sidebar-width` | **252px** (expandida) |
| `--sidebar-collapsed` | **72px** (recolhida) |
| Posição | `fixed`, `left: 0`, `top: 0`, `height: 100vh`, `z-index: 100` |
| `.sidebar.collapsed` | Reduz largura; borda esquerda de destaque (`--sidebar-accent-line`, no claro usa `--primary`). |

### 5.2 Regiões internas

| Bloco | Classes principais | Notas |
|--------|-------------------|--------|
| Cabeçalho / logo | `.sidebar-header` → `.sidebar-brand` | Duas imagens: `.sidebar-brand-logo--expanded` (logo larga) e `.sidebar-brand-logo--collapsed` (ícone 40×40). Só uma visível conforme `.collapsed` ou breakpoint. |
| Botão recolher | `.sidebar-toggle` (#sidebarToggle) | Círculo à direita da sidebar, fora da borda (posição absoluta). |
| Navegação | `nav.sidebar-nav` | Scroll vertical; grupos com `.nav-section-title` + links `.nav-item`. |
| Rodapé utilizador | `.sidebar-footer` → `.user-info` | Avatar `.sidebar-avatar-wrap` / `#userAvatar`, `#userAvatarImg`, texto `#userName`, `#userRole`. |

### 5.3 Itens de menu (`.nav-item`)

- Estrutura típica: `<a class="nav-item">` ou `<button>` com `<i class="fas …">` + `<span>Texto</span>`.
- Estado ativo: `.nav-item.active`.
- Itens só admin: classe `.admin-only-nav` (controlada por JS).
- Secções: `.nav-section-title` (uppercase, letter-spacing, cor `--text-muted` no claro).

### 5.4 Mobile

- Overlay: `#sidebarOverlay.sidebar-overlay` — escurece o fundo; classe `.visible` para mostrar.
- Botão hambúrger na topbar: `.mobile-menu-btn` (#mobileMenuBtn).
- Sidebar pode receber `.open` em `<768px` para menu em gaveta (logo expandida quando aberta).

---

## 6. Conteúdo principal (`.main-content`)

- **Regra chave:** `margin-left: var(--sidebar-width)`; com sidebar recolhida: `.sidebar.collapsed ~ .main-content` → `margin-left: var(--sidebar-collapsed)`.
- **Importante:** no HTML, `.main-content` deve ser **irmão imediato** de `aside.sidebar` para o seletor `~` funcionar (igual ao `index`).
- `min-width: 0` evita overflow horizontal em flex/grid internos.

---

## 7. Topbar (`.topbar`)

Container fixo ao scroll da coluna: `position: sticky`, `top: 0`, `z-index: 50`, `backdrop-filter: blur(20px)`.

### 7.1 Esquerda (`.topbar-left`)

- Botão mobile: `.mobile-menu-btn` (visível só em breakpoints menores).
- Título da página: **`<h1 id="pageTitle">`** — tipografia ~1.35rem, peso 700, cor `--text-primary`, ellipsis se longo.

### 7.2 Direita (`.topbar-right`)

Ordem típica no `index` (ajustar conforme a página, mas manter harmonia de espaçamento `gap: 16px`):

| Bloco | Classes / IDs | Função |
|--------|-----------------|--------|
| Período | `.topbar-period-wrap` | Label “Período”, inputs `#dataDeFilter` / `#dataAdeFilter`, classe `.filter-date-input` |
| Tema | `.theme-toggle` | Alternância claro/escuro; persiste geralmente em `localStorage` |
| Notificações | `.notifications-wrap`, `#btnNotifications`, `#notificationsPanel` | Painel lateral com lista e envio de mensagens |
| Atualizar | `.topbar-btn` | Ícone sync |
| Sair | `.logout-btn` | Logout |

Páginas que não usam filtro de datas podem **omitir** `.topbar-period-wrap`, mas devem manter alinhamento e altura visual com os botões.

---

## 8. Secções de página (`.section-page`)

```html
<div id="page-dashboard" class="section-page active">
  <div class="page-content">
    <!-- conteúdo -->
  </div>
</div>
```

- **Ocultação:** `.section-page { display: none; }` / `.section-page.active { display: block; }`.
- IDs usados no `index` seguem o padrão `#page-<nome>` (ex.: `#page-visitas`, `#page-importar`).

Em HTML **standalone** (uma página = um “módulo”), costuma haver **uma** secção sempre `.active`, ou o corpo principal sem `display:none` — o importante é manter **`.page-content`** dentro do fluxo equivalente.

---

## 9. Área de conteúdo (`.page-content`)

- **Padding padrão:** `28px 32px` (reduz em mobile; ver media queries em `styles.css`).
- **Regra:** `min-width: 0`, `max-width: 100%`, `box-sizing: border-box` para não rebentar layout com grids.

---

## 10. Padrões de componentes internos

### 10.1 KPIs (`.kpi-grid` + `.kpi-card`)

- Grid: **3 colunas** por defeito (`repeat(3, minmax(0, 1fr))`), gap **20px**, `margin-bottom: 28px`.
- Classe extra `.stagger` no grid para animação escalonada nos filhos.
- Card: borda `--border`, fundo `--bg-card`, raio `--radius-md`, barra colorida no topo via `::before` e `nth-child` para ícones.

Subpáginas podem usar variações (ex.: `.visitadores-kpi-grid` com 4 colunas) definidas no próprio `styles.css` por `#page-visitadores`.

### 10.2 Gráficos (`.charts-grid` + `.chart-card`)

- Grid **2 colunas**; `.charts-grid.single` → uma coluna.
- `.chart-card`: cabeçalho `.chart-header` com `.chart-title` e `.chart-subtitle`, corpo `.chart-body` (normalmente `<canvas>`).
- `.chart-card.full-width` ocupa todas as colunas.

### 10.3 Tabelas

- Wrapper: `.table-responsive` (scroll horizontal quando necessário).
- Tabela: `.data-table`; cabeçalho com fundo adaptado ao tema claro em `[data-theme="light"]`.

### 10.4 Banners e texto introdutório

- Ex.: `.visitas-crosslink-banner`, `.visitas-page-lead` — páginas complexas usam blocos de nota no topo; manter o mesmo tom tipográfico (`--text-secondary` para secundário).

### 10.5 Rodapé da app

- **Classe:** `.app-footer` dentro de `.main-content` (não dentro de cada `section-page`).
- Estilo: padding `20px 32px`, borda superior, texto centrado, `--text-muted`, fonte ~0.78rem.

---

## 11. Tema claro / escuro

- Atributo: **`html data-theme="light"`** ou **`dark`**.
- Variáveis críticas a respeitar em qualquer CSS novo:

| Uso | Variáveis |
|-----|------------|
| Fundo geral | `--bg-dark` (no claro é cinza claro) |
| Cartões | `--bg-card`, `--border` |
| Texto | `--text-primary`, `--text-secondary`, `--text-muted` |
| Ação primária | `--primary` (#E63946), `--primary-dark` |
| Sombra | `--shadow-sm`, `--shadow-md`, `--shadow-lg` |
| Raio | `--radius-sm` … `--radius-xl` |

Componentes novos devem **Nunca** fixar cinzentos hardcoded sem alternar com `[data-theme="light"]`, salvo exceção pontual.

---

## 12. Loading global (`.loading-overlay`)

- **Uso:** overlay fullscreen com `.loading-spinner` + `.loading-text`.
- **z-index:** 9999 (modal de senha forçada no `index` usa ~12000 — atenção à ordem se empilhar modais).
- No tema claro, fundo do overlay pode ser mais claro (`rgba(245, 246, 250, 0.85)`).

---

## 13. Modais (padrão no `index`)

Padrão recorrente:

- `.modal-overlay` (fullscreen semitransparente)
- `.modal-content` → `.modal-header`, `.modal-body`, `.modal-footer`
- Botões `.btn-cancel`, `.btn-save`, `.modal-close`

Manter **foco** e **Escape** coerentes com o restante sistema (JS partilhado quando existir).

---

## 14. Breakpoints relevantes (resumo)

- **≤768px:** menu mobile, `mobile-menu-btn`, sidebar como drawer, `page-content` com padding menor.
- **769px–992px:** em alguns casos a sidebar pode mostrar só o ícone (media queries próximas de `.sidebar-brand`).

Consultar secções “Responsive” no final de `styles.css` para `.topbar`, `.page-content`, `.kpi-grid`, `.charts-grid`.

---

## 15. Checklist para alinhar uma página nova ao `index`

1. [ ] `<html lang="pt-BR" data-theme="…">` coerente com `localStorage` / `applyTheme` do restante app.
2. [ ] Estrutura `aside.sidebar` + `div.main-content` como **irmãos**; IDs de toggle/overlay alinhados ao JS da página.
3. [ ] Mesmas **larguras** de sidebar via variáveis CSS (`--sidebar-width` / `--sidebar-collapsed`).
4. [ ] Topbar com **`.topbar`**, `.topbar-left` (título `h1`), `.topbar-right` com espaçamento consistente.
5. [ ] Conteúdo dentro de **`.page-content`** (e `section-page` se multi-secção).
6. [ ] KPIs: **`.kpi-grid`** + **`.kpi-card`**; gráficos: **`.charts-grid`** + **`.chart-card`**.
7. [ ] Tabelas: **`.data-table`** + **`.table-responsive`**.
8. [ ] Rodapé: **`.app-footer`** no fundo de `.main-content`.
9. [ ] Carregamento: **`.loading-overlay`** com mensagem curta em `.loading-text`.
10. [ ] Incluir **Inter** + **Font Awesome** se a página for autónoma.
11. [ ] Não duplicar variáveis de cor: preferir classes existentes em `styles.css`.

---

## 16. Referência rápida de IDs frequentes no `index.html`

| ID | Função |
|----|--------|
| `sidebar`, `sidebarToggle`, `sidebarOverlay`, `mobileMenuBtn` | Menu lateral |
| `pageTitle` | Título na topbar |
| `dataDeFilter`, `dataAteFilter` | Janela de datas global |
| `userName`, `userRole`, `userAvatar`, `userAvatarImg`, `inputFotoPerfil` | Utilizador no rodapé da sidebar |
| `btnNotifications`, `notificationsPanel`, `notificationsBadge` | Centro de notificações |

Em páginas standalone, estes IDs são frequentemente **replicados** para o mesmo JS (`api.php` / helpers) reconhecer o painel.

---

## 17. Referência de secções internas do `index` (páginas `page-*`)

Exemplos de `id` no SPA:

- `#page-dashboard` — Dashboard principal (KPI + gráficos).
- `#page-visitadores`, `#page-visitas` — CRM / visitas (KPIs específicos, tabelas, mapas).
- Outras: faturamento, prescritores, bonificações, clientes (legado SPA), produtos, equipe, insights, importar, admin.

Ao extrair uma funcionalidade para um **HTML separado**, copiar **apenas** o bloco `section-page` + estilos necessários, montando o **mesmo shell** (sidebar + topbar + footer).

---

## 18. Nota sobre login e `forcePasswordModal`

- `login-wrapper` / `login-card-new` vivem **fora** de `.app-layout` no `index`.
- Modais globais podem ficar no `body` raiz com `z-index` superior ao `.loading-overlay`.

Páginas que só pedem login numa rota diferente podem reutilizar `css/login.css` e a mesma estrutura de cartão.

---

**Documento gerado como referência de layout.**  
**Fonte da verdade visual:** `index.html` + `css/styles.css` (MyPharm).
