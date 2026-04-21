---
tags: [frontend, css, design-system]
---

# Padrão Visual e CSS

## Paleta de Cores (Sistema Principal)

```css
/* Cores principais */
--primary: #4f46e5       /* roxo/indigo — ações principais */
--primary-dark: #3730a3
--accent: #f97316        /* laranja — destaques, alertas */
--success: #10b981       /* verde */
--danger: #ef4444        /* vermelho */
--warning: #f59e0b       /* amarelo */

/* Fundo e superfícies */
--bg: #0f172a            /* fundo escuro */
--surface: #1e293b       /* cards */
--surface-2: #334155     /* cards secundários */
--border: #475569

/* Texto */
--text: #f1f5f9
--text-muted: #94a3b8
```

## Paleta TV Ranking (Tailwind)

```css
/* Definido em tailwind.config.js */
primary: '#4f46e5'    /* indigo */
accent:  '#f97316'    /* laranja */
dark:    '#0f172a'
surface: '#1e293b'
```

## Componentes Reutilizáveis (Sistema Principal)

### gc-chart-panel
Card padrão para gráficos no painel de gestão:
```html
<div class="gc-chart-panel">
  <div class="gc-panel-header">
    <h3>Título</h3>
    <div class="gc-panel-actions"><!-- filtros --></div>
  </div>
  <div class="gc-panel-body">
    <canvas id="meuGrafico"></canvas>
  </div>
</div>
```

### KPI Card
```html
<div class="kpi-card">
  <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
  <div class="kpi-info">
    <span class="kpi-label">Vendas</span>
    <span class="kpi-value">R$ 45.000</span>
    <span class="kpi-delta positive">+12%</span>
  </div>
</div>
```

### Tabela Padrão
```html
<div class="table-container">
  <table class="data-table">
    <thead><tr><th>Col</th></tr></thead>
    <tbody><tr><td>Dado</td></tr></tbody>
  </table>
</div>
```

## Hierarquia Visual

1. **Título da página** — `h1.page-title`
2. **Seções** — `section.dashboard-section`
3. **Cards** — `.card` ou `.gc-chart-panel`
4. **Tabelas** — `.data-table` dentro de `.table-container`

## TV Ranking — Cores do Pódio

| Posição | Cor da borda | Altura do card |
|---|---|---|
| 1º | Azul primário (`primary`) | 176px |
| 2º | Cinza (`slate-400`) | 128px |
| 3º | Laranja (`accent`) | 96px |

## Barra de Progresso de Meta

```tsx
// percentual < 100% → laranja
// percentual >= 100% → azul (atingiu meta)
<ProgressBar value={percentual_meta} max={100} />
```

## Links Relacionados
- [[TV Ranking — React App]]
- [[Arquitetura Geral]]
