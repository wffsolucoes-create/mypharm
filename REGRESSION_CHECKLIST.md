# Checklist de Regressão (Refatoração Gradual)

## API (PHP)
- [x] `api.php` com sintaxe válida (`C:\xampp\php\php.exe -l api.php`)
- [x] `api/modules/prescritores.php` com sintaxe válida
- [x] Ações de prescritor continuam roteadas em `api.php`:
  - `save_prescritor_whatsapp`
  - `get_prescritor_contatos`
  - `get_prescritor_dados`
  - `update_prescritor_dados`
  - `transfer_prescritor`
  - `add_prescritor`

## Front-end (Estrutura)
- [x] `visitador.html` carrega `js/visitador/prescritores-feature.js`
- [x] `prescritores.html` carrega `js/prescritores/actions-feature.js`
- [x] `visitador.html` e `prescritores.html` carregam `js/shared/mypharm-utils.js`
- [x] Funções extraídas removidas dos blocos inline para evitar duplicidade

## Qualidade estática
- [x] `ReadLints` sem erros nos arquivos modificados

## Observação
Este checklist cobre regressão técnica/estática da refatoração. Recomenda-se validação funcional manual em navegador para:
- login/sessão;
- listar prescritores;
- transferir prescritor;
- editar dados de prescritor;
- relatório de visitas;
- aprovados/recusados;
- novo prescritor.
