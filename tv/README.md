# Ranking Comercial Gamificado 🏆

Painel visual moderno e gamificado para acompanhamento de vendas em tempo real, focado num deploy extremamente rápido e simples via Hostinger ou similares. Construído em React JS com TypeScript, TailwindCSS e Framer Motion.

## 🚀 Como Rodar Localmente

1. Certifique-se de ter o [Node.js](https://nodejs.org/) instalado.
2. Baixe o projeto e abra o terminal na pasta raiz do projeto (`c:\xampp\htdocs\ranking`).
3. Instale as dependências:
   ```bash
   npm install
   ```
4. Inicie o servidor de desenvolvimento:
   ```bash
   npm run dev
   ```

## 🛠️ Build e Deploy na Hostinger

Este projeto foi construído como uma SPA (Single Page Application) puramente estática. O deploy no hPanel/cPanel é trivial. Não há necessidade de configurar um banco de dados local ou um interpretador Node.js próprio via PM2.

1. Gere os arquivos super otimizados de produção rodando:
   ```bash
   npm run build
   ```
2. Após rodar o comando, você terá uma nova pasta chamada `dist`.
3. Acesse o **Gerenciador de Arquivos** da Hostinger.
4. Arraste **todo o conteúdo da pasta `dist`** (arquivos como `index.html` e a pasta `assets`) para dentro da pasta `public_html` associada ao seu domínio.
5. Acesse seu site. Pronto! Ele já deve estar renderizando instantaneamente com animações super leves.

## ⚙️ Variáveis de Ambiente e Troca para API da RD Station

Por padrão, a aplicação vai exibir "dados mockados" definidos em `src/mocks/rankingData.ts` caso você inicie o projeto sem URL de API. Essa etapa permite testar design sem consumir sua banda e limites na Hostinger.

Para apontar para a sua API REST real (onde estão os dados provindos da RD Station / próprio back-end):

1. Crie um arquivo com o exato nome `.env` na pasta raiz do projeto.
2. Edite o arquivo `.env` para apontar seu endpoint (O VITE_ exige esse prefixo):
   ```env
   VITE_API_URL=https://api.seuminidominio.com/painelvendas
   VITE_USE_MOCKS=false
   ```
3. Salve o arquivo `.env`.
4. Importante: Você precisa regerar o projeto rodando novamente `npm run build` após salvar e hospedar as alterações. Variáveis de ambiente no frontend Vite são queimadas nos arquivos finais estáticos.

> **Importante para a API:** A funcionalidade de "Polling Suave" configurada no Frontend fará com que o sistema busque novos dados na sua API a cada X segundos (padrão 15s). Use uma arquitetura simples na sua API de back-end (PHP/Node/Python). O ideal é a API responder sempre um ARRAY do JSON listado sem precisar reprocessar do RD na requisição (sincronizando eles num DB à parte). Isso garante que o site não sofrerá instabilidade ou "piscará a tela" durante a atualização.

## 👉 Funcionalidades Implementadas

- [x] Ranking Premium TV/Desktop com Framer Motion Animations 
- [x] Atualização silenciosa/dinâmica no background (TanStack Query) sem Flickering
- [x] Detecção visual de subida ou descida individual com ícones Trends
- [x] Contadores numéricos que giram graciosamente e barras de meta.
- [x] Estratégia de Eventos Sonoros previnindo Cacofonia e permitindo o Mute do Painel.
- [x] Tela em MODO TV/APRESENTAÇÃO (`/tv`) focada apenas na exibição limpa sem distrações do top vendas.
- [x] Janela limpa de detalhes focando no sucesso das conversões do indivíduo selecionado.
