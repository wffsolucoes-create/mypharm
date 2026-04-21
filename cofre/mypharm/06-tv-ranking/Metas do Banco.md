---
tags: [tv, ranking, banco, metas]
---

# Metas do Banco (Abril 2026)

## Mudança Implementada

As metas do TV Ranking agora vêm de `usuarios.meta_mensal` em vez de estarem hardcoded em `config.php`.

**Antes:** Todas as vendedoras com `meta: 25000` em `$SELLER_CONFIG` → engessado, sem flexibilidade.

**Depois:** Busca dinâmica no banco pela função `getMetaFromDB()` → cada vendedora tem sua meta individual.

## Lógica

1. **Ranking busca email do RD Station** → `deal['user']['email']`
2. **Função busca na tabela `usuarios` por email**:
   ```sql
   SELECT meta_mensal FROM usuarios
   WHERE email = 'nereida@mypharm.com.br' AND ativo = 1
   ```
3. **Se encontrar e meta > 0** → usa aquela
4. **Se não encontrar** → fallback para `META_GLOBAL` (25.000)

## Código

### config.php (nova função)
```php
function getMetaFromDB($nomeCrm, $email = '') {
    $pdo = getDB();
    
    // Tenta por email (mais confiável)
    if (!empty($email)) {
        $stmt = $pdo->prepare('SELECT meta_mensal FROM usuarios WHERE email = ? AND ativo = 1');
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['meta_mensal'] > 0) {
            return (float)$result['meta_mensal'];
        }
    }
    
    // Fallback: busca por nome
    $stmt = $pdo->prepare('SELECT meta_mensal FROM usuarios WHERE nome LIKE ? AND ativo = 1');
    $stmt->execute(['%' . $nomeCrm . '%']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && $result['meta_mensal'] > 0) {
        return (float)$result['meta_mensal'];
    }
    
    return null;
}
```

### index.php (uso)
```php
// Busca meta do banco usando email
$metaValor = getMetaFromDB($nomeCrm, $dados['email'] ?? '');
if ($metaValor === null) {
    $metaValor = $config['meta'] ?? META_GLOBAL;  // fallback
}
```

## Estratégia de Matching

Ordem de busca:

1. **Email exato** (mais confiável)
   ```sql
   WHERE email = 'nereida@mypharm.com.br'
   ```

2. **Alias configurado** — se existe em `$NAME_ALIASES`
   ```php
   'Vitória Carvalho' => 'Vitória'  // busca por "Vitória"
   ```

3. **Primeiro nome** — extrai "Carla" de "Carla Pires - Consultora"
   ```sql
   WHERE nome LIKE '%Carla%'
   ```

4. **Nome completo** — busca parcial
   ```sql
   WHERE nome LIKE '%Carla Pires%'
   ```

## Aliases (Apelidos e Variações)

Editar `tv/api/config.php` → `$NAME_ALIASES`:

```php
$NAME_ALIASES = [
    'Vitória Carvalho' => 'Vitória',      // RD Station → banco
    'Jéssica' => 'Jessica',               // variação de acento
    'Jhennyffer' => 'Jhennyffer',         // apelido
];
```

## Mapeamento Email → Vendedora

| Nome no RD | Email | meta_mensal |
|---|---|---|
| Vitória Carvalho | vitoria@mypharm.com.br | 250.000 |
| Nereida | nereida@mypharm.com.br | 65.670 |
| Nailena | nailena@mypharm.com.br | 65.670 |
| Ananda | anandareis@mypharm.com.br | 65.670 |
| Clara Letícia | clara@mypharm.com.br | 65.670 |
| Carla Pires | carla@mypharm.com.br | 100.000 |
| Giovanna | giovanna@mypharm.com.br | 50.000 |
| Micaela Nicolle | micaela@mypharm.com.br | 50.000 |
| Mariana | mariana@mypharm.com.br | 65.670 |

> ⚠️ Se vendedora não existir na tabela `usuarios` ou tiver `meta_mensal = 0`, usa `META_GLOBAL = 25.000`.

## Links Relacionados
- [[Modulo TV Ranking]]
- [[Tabela usuarios]]
