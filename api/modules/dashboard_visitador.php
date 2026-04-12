<?php
/** Visitador dashboard: dados reais do painel (extra�do de api.php) */
function dashboardVisitadorDashboardReal(PDO $pdo): void
{
                $nome = $_GET['nome'] ?? '';
                $ano = $_GET['ano'] ?? date('Y');
                $mes = $_GET['mes'] ?? '';
                $dia = $_GET['dia'] ?? '';
    
                if (!$nome) {
                    echo json_encode(['error' => 'Nome do visitador n%�o fornecido']);
                    return;
                }
    
                // Filtro de m%�s e dia para queries (quando selecionado)
                $filtroMes = $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";
                $filtroDia = ($dia && $mes && $ano) ? "AND DATE(gp.data_aprovacao) = :data_filtro" : "";
                $dataFiltroVis = ($dia && $mes && $ano) ? sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, (int)$dia) : null;
                $filtroMesPresc = $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";
    
                // L%%gica para My Pharm (visitador vazio ou NULL ou literal 'My Pharm')
                $nomeLimpo = trim($nome);
                $isMyPharm = (strcasecmp($nomeLimpo, 'My Pharm') === 0);
    
                $visitadorWhere = "visitador = :nome";
                $visitadorWherePr = "pr.visitador = :nome";
                $visitadorWhereP = "p.visitador = :nome";
                $visitadorWhereVis = "visitador = :nome";
    
                if ($isMyPharm) {
                    $visitadorWhere = "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')";
                    $visitadorWherePr = "(pr.visitador IS NULL OR TRIM(pr.visitador) = '' OR LOWER(TRIM(pr.visitador)) = 'my pharm')";
                    $visitadorWhereP = "(p.visitador IS NULL OR TRIM(p.visitador) = '' OR LOWER(TRIM(p.visitador)) = 'my pharm')";
                    $visitadorWhereVis = "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')";
                }
    
                // Total prescritores: 1 por pessoa (mesmo que tenha or%�amento em v%�rios anos).
                // Fonte: prescritores_cadastro (cadastro %Q%nico), filtrado por visitador.
                $sqlCarteira = "SELECT COUNT(*) as total FROM prescritores_cadastro pc WHERE 1=1";
                if ($isMyPharm) {
                    $sqlCarteira .= " AND (pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')";
                } else {
                    $sqlCarteira .= " AND pc.visitador = :nome";
                }
                $stmtCarteira = $pdo->prepare($sqlCarteira);
                $stmtCarteira->execute($isMyPharm ? [] : ['nome' => $nome]);
                $total_prescritores = (int)$stmtCarteira->fetchColumn();
    
                // Recusados e no carrinho: soma de prescritor_resumido
                $sqlKpi = "
                    SELECT 
                        COALESCE(SUM(pr.recusados), 0) as total_recusados,
                        COALESCE(SUM(pr.no_carrinho), 0) as total_no_carrinho
                    FROM prescritor_resumido pr
                    WHERE pr.ano_referencia = :ano
                ";
                if (!$isMyPharm)
                    $sqlKpi .= " AND $visitadorWherePr";
    
                $stmt = $pdo->prepare($sqlKpi);
                $qKpi = ['ano' => $ano];
                if (!$isMyPharm)
                    $qKpi['nome'] = $nome;
                $stmt->execute($qKpi);
                $kpiRow = $stmt->fetch();
                $total_recusados = $kpiRow['total_recusados'] ?? 0;
                $total_no_carrinho = $kpiRow['total_no_carrinho'] ?? 0;
    
                // === QUERY 2: Vendas consolidadas (anual SEM filtro de m%�s; mensal por CASE; %Q%ltimo m%�s) ===
                // Meta Anual e total_anual devem ser sempre do ano inteiro, sem interfer%�ncia do filtro mensal
                $sqlVendas = "
                    SELECT 
                        SUM(gp.preco_liquido) as total_anual,
                        SUM(CASE WHEN MONTH(gp.data_aprovacao) = :mesRef THEN gp.preco_liquido ELSE 0 END) as total_mensal,
                        MAX(MONTH(gp.data_aprovacao)) as ultimo_mes,
                        MAX(gp.data_aprovacao) as ultima_atualizacao
                    FROM gestao_pedidos gp
                    LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                    WHERE gp.ano_referencia = :ano2
                      AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Or�amento')
 AND gp.status_financeiro NOT LIKE '%carrinho%'))
                ";
                if ($isMyPharm) {
                    $sqlVendas .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
                }
                else {
                    $sqlVendas .= " AND $visitadorWherePr";
                }
                $stmt = $pdo->prepare($sqlVendas);
    
                $mesRef = $mes ?: date('m');
                $qVendas = ['ano1' => $ano, 'ano2' => $ano, 'mesRef' => (int)$mesRef];
                if (!$isMyPharm)
                    $qVendas['nome'] = $nome;
                $stmt->execute($qVendas);
                $vendasRow = $stmt->fetch();
                $total_aprovado_anual = $vendasRow['total_anual'] ?: 0;
                $total_aprovado_mensal = $vendasRow['total_mensal'] ?: 0;
                $ultimo_mes = $vendasRow['ultimo_mes'] ?: date('m');
                $ultima_atualizacao = $vendasRow['ultima_atualizacao'];
    
                // Total com filtro de m%�s (para KPI)
                if ($mes) {
                    $total_aprovado = $total_aprovado_mensal;
                }
                else {
                    $total_aprovado = $total_aprovado_anual;
                }
    
                // Buscar Metas do Usu%�rio
                $stmtUser = $pdo->prepare("SELECT meta_mensal, meta_anual, comissao_percentual, meta_visitas_semana, meta_visitas_mes, premio_visitas FROM usuarios WHERE nome = :nome LIMIT 1");
                $stmtUser->execute(['nome' => $nome]);
                $userData = $stmtUser->fetch();
    
                $meta_mensal = $userData ? ($userData['meta_mensal'] ?? 50000) : 50000;
                $meta_anual = $userData ? ($userData['meta_anual'] ?? 600000) : 600000;
                $comissao = $userData ? ($userData['comissao_percentual'] ?? 1) : 1;
                $metaVisitaSemanaActual = $userData ? ($userData['meta_visitas_semana'] ?? 30) : 30;
                $metaVisitasMensalActual = $userData ? ($userData['meta_visitas_mes'] ?? 100) : 100;
                $valorPremioActual = $userData ? ($userData['premio_visitas'] ?? 600.00) : 600.00;
    
                $kpis = [
                    'total_aprovado_anual' => $total_aprovado_anual,
                    'total_aprovado_mensal' => $total_aprovado_mensal,
                    'total_prescritores' => $total_prescritores,
                    'total_recusados' => $total_recusados,
                    'total_no_carrinho' => $total_no_carrinho,
                    'meta_mensal' => $meta_mensal,
                    'meta_anual' => $meta_anual,
                    'comissao' => $comissao,
                    'ultimo_mes_com_dados' => intval($ultimo_mes),
                    'ultima_atualizacao' => $ultima_atualizacao
                ];
    
                // === QUERY 3: %�ltima visita por prescritor (pr%�-carregada) ===
                $stmtVisitas = $pdo->prepare("
                    SELECT prescritor, MAX(data_visita) as ultima_visita
                    FROM historico_visitas
                    WHERE $visitadorWhere
                    GROUP BY prescritor
                ");
                $qVis = [];
                if (!$isMyPharm)
                    $qVis['nome'] = $nome;
                $stmtVisitas->execute($qVis);
                $visitasMap = [];
                while ($vr = $stmtVisitas->fetch()) {
                    $visitasMap[$vr['prescritor']] = $vr['ultima_visita'];
                }
    
                // Listar TODOS os prescritores da carteira (prescritor_resumido),
                // n%�o s%% os que t%�m pedidos ��� para o modal mostrar 74 e n%�o apenas 45
                $sqlPresc = "
                    SELECT 
                        pr.nome as prescritor,
                        COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Or�amento') THEN gp.preco_liquido ELSE 0 END), 0) as total_aprovado,
                        COUNT(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Or�amento') THEN 1 END) as qtd_aprovados,
                        (COALESCE(pr.valor_recusado, 0) + COALESCE(pr.valor_no_carrinho, 0)) as total_recusado,
                        (COALESCE(pr.recusados, 0) + COALESCE(pr.no_carrinho, 0)) as qtd_recusados,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(NOW(), MAX(gp.data_aprovacao)) as dias_sem_compra
                    FROM prescritor_resumido pr
                    LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome 
                        AND gp.ano_referencia = :ano2
                        AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Or�amento')
                        $filtroMesPresc
                        $filtroDia
                    WHERE pr.ano_referencia = :ano1
                ";
                if ($isMyPharm) {
                    $sqlPresc .= " AND ($visitadorWherePr)";
                }
                else {
                    $sqlPresc .= " AND $visitadorWherePr";
                }
                $sqlPresc .= " GROUP BY pr.nome, pr.valor_recusado, pr.valor_no_carrinho, pr.recusados, pr.no_carrinho
                              ORDER BY total_aprovado DESC";
    
                $stmt = $pdo->prepare($sqlPresc);
                $paramsPresc = ['ano1' => $ano, 'ano2' => $ano];
                if (!$isMyPharm)
                    $paramsPresc['nome'] = $nome;
                if ($mes)
                    $paramsPresc['mes'] = (int)$mes;
                if ($dataFiltroVis)
                    $paramsPresc['data_filtro'] = $dataFiltroVis;
                $stmt->execute($paramsPresc);
                $top_prescritores_raw = $stmt->fetchAll();
    
                // Adicionar ultima_visita do mapa pr%�-carregado
                $top_prescritores = [];
                foreach ($top_prescritores_raw as $tp) {
                    $tp['ultima_visita'] = $visitasMap[$tp['prescritor']] ?? null;
                    $top_prescritores[] = $tp;
                }
    
                // === QUERY 5: Evolu%�%�o Mensal + Top Produtos + Alertas (usando subquery %Q%nica para nomes) ===
                // Evolu%�%�o Mensal
                $sqlEv = "
                    SELECT 
                        MONTH(gp.data_aprovacao) as mes,
                        SUM(gp.preco_liquido) as total
                    FROM gestao_pedidos gp
                    LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                    WHERE gp.ano_referencia = :ano2 
                    AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Or�amento')
                    AND gp.data_aprovacao IS NOT NULL
                    $filtroMes
                    $filtroDia
                ";
                if ($isMyPharm) {
                    $sqlEv .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
                }
                else {
                    $sqlEv .= " AND $visitadorWherePr";
                }
                $sqlEv .= " GROUP BY mes ORDER BY mes";
    
                $stmt = $pdo->prepare($sqlEv);
                $qEv = ['ano1' => $ano, 'ano2' => $ano];
                if (!$isMyPharm)
                    $qEv['nome'] = $nome;
                if ($mes)
                    $qEv['mes'] = (int)$mes;
                if ($dataFiltroVis)
                    $qEv['data_filtro'] = $dataFiltroVis;
                $stmt->execute($qEv);
                $evolucao = $stmt->fetchAll();
    
                // Top Fam%�lias de Produtos
                $sqlProd = "
                    SELECT 
                        gp.produto as familia,
                        SUM(gp.preco_liquido) as total
                    FROM gestao_pedidos gp
                    LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                    WHERE gp.ano_referencia = :ano2 
                    AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Or�amento')
                    $filtroMesPresc
                    $filtroDia
                ";
                if ($isMyPharm) {
                    $sqlProd .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
                }
                else {
                    $sqlProd .= " AND $visitadorWherePr";
                }
                $sqlProd .= " GROUP BY gp.produto ORDER BY total DESC LIMIT 5";
    
                $stmt = $pdo->prepare($sqlProd);
                $paramsProd = ['ano1' => $ano, 'ano2' => $ano];
                if (!$isMyPharm)
                    $paramsProd['nome'] = $nome;
                if ($mes)
                    $paramsProd['mes'] = (int)$mes;
                if ($dataFiltroVis)
                    $paramsProd['data_filtro'] = $dataFiltroVis;
                $stmt->execute($paramsProd);
                $top_produtos = $stmt->fetchAll();
    
                // Profissão no gráfico: mesma base da carteira que all_prescritores (prescritores_cadastro + prescritor_dados).
                // Antes usava só prescritor_resumido por ano — sem linha na importação do ano, o gráfico ficava vazio mesmo com profissão no cadastro.
                $naoInformada = 'Não informada';
                $sqlEsp = "
                    SELECT sub.familia, COUNT(*) as total
                    FROM (
                        SELECT
                            COALESCE(
                                NULLIF(TRIM(MAX(pd.profissao)), ''),
                                NULLIF(TRIM(MAX(pr.profissao)), ''),
                                :nao_inf
                            ) as familia
                        FROM prescritores_cadastro pc
                        LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
                        LEFT JOIN prescritor_resumido pr ON pr.nome = pc.nome AND pr.ano_referencia = :ano
                        WHERE 1=1
                ";
                if ($isMyPharm) {
                    $sqlEsp .= " AND (pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')";
                } else {
                    $sqlEsp .= " AND pc.visitador = :nome_carteira";
                }
                $sqlEsp .= "
                        GROUP BY pc.nome, pc.visitador, pc.usuario_id
                    ) sub
                    GROUP BY sub.familia
                    ORDER BY total DESC
                    LIMIT 10
                ";
                $stmtEsp = $pdo->prepare($sqlEsp);
                $paramsEsp = ['ano' => $ano, 'nao_inf' => $naoInformada];
                if (!$isMyPharm) {
                    $paramsEsp['nome_carteira'] = $nome;
                }
                $stmtEsp->execute($paramsEsp);
                $top_especialidades = $stmtEsp->fetchAll(PDO::FETCH_ASSOC);
    
                // Alertas inteligentes: prescritores com potencial mas inativos OU abaixo da media
                // Usa $visitasMap ja carregado para evitar subqueries correlacionadas lentas
                $ano_anterior = (int)$ano - 1;
                $mes_ytd = $mes ? (int)$mes : (int)date('n');
                $sqlAlerts = "
                    SELECT 
                        COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') as prescritor,
                        SUM(gp.preco_liquido) as valor_total_aprovado,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(NOW(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        COUNT(*) as total_pedidos,
                        SUM(CASE WHEN gp.ano_referencia = :ano_ant THEN gp.preco_liquido ELSE 0 END) as total_ano_anterior,
                        SUM(CASE WHEN gp.ano_referencia = :ano_ytd AND MONTH(gp.data_aprovacao) <= :mes_ytd THEN gp.preco_liquido ELSE 0 END) as total_ano_atual_ytd
                    FROM gestao_pedidos gp
                    INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome
                    WHERE gp.status_financeiro NOT IN ('Recusado', 'Cancelado', CONCAT('Or', CHAR(231), 'amento'))
                      AND gp.status_financeiro NOT LIKE '%carrinho%'
                      AND gp.preco_liquido > 0
                ";
                if ($isMyPharm) {
                    $sqlAlerts .= " AND (pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')";
                } else {
                    $sqlAlerts .= " AND pc.visitador = :nome";
                }
                $sqlAlerts .= "
                    GROUP BY COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
                    HAVING valor_total_aprovado >= 100 AND dias_sem_compra >= 7
                    ORDER BY valor_total_aprovado DESC
                    LIMIT 30
                ";
                $stmt = $pdo->prepare($sqlAlerts);
                $qAlerts = ['ano_ant' => $ano_anterior, 'ano_ytd' => $ano, 'mes_ytd' => $mes_ytd];
                if (!$isMyPharm) $qAlerts['nome'] = $nome;
                $stmt->execute($qAlerts);
                $alertasRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $alertas = [];
                foreach ($alertasRaw as $a) {
                    $pNome = $a['prescritor'];
                    $uv = $visitasMap[$pNome] ?? null;
                    $diasSemVisita = null;
                    if ($uv) {
                        try { $diasSemVisita = (new DateTime($uv))->diff(new DateTime())->days; } catch (Exception $e) {}
                    }
                    $diasSemCompra = (int)($a['dias_sem_compra'] ?? 0);
                    $totAnt = (float)($a['total_ano_anterior'] ?? 0);
                    $totYtd = (float)($a['total_ano_atual_ytd'] ?? 0);
                    $mediaMensalAnoAnt = $totAnt > 0 ? $totAnt / 12 : 0;
                    $esperadoYtd = $mediaMensalAnoAnt * $mes_ytd;
                    $abaixoMedia = $totAnt >= 1000 && $totYtd < $esperadoYtd;

                    $passaFiltro = (($diasSemCompra >= 15 || ($diasSemVisita === null || $diasSemVisita >= 15)) && $diasSemCompra >= 7)
                        || $abaixoMedia;
                    if (!$passaFiltro) continue;

                    $score = round(
                        (min((float)$a['valor_total_aprovado'], 100000) / 100000) * 40
                        + min($diasSemCompra, 365) / 365 * 35
                        + min($diasSemVisita ?? 365, 365) / 365 * 25
                    , 1);

                    $a['ultima_visita'] = $uv;
                    $a['dias_sem_visita'] = $diasSemVisita;
                    $a['score'] = $score;
                    $a['media_mensal_ano_anterior'] = round($mediaMensalAnoAnt, 2);
                    $a['media_mensal_ano_atual'] = $mes_ytd > 0 ? round($totYtd / $mes_ytd, 2) : 0;
                    $a['abaixo_da_media'] = $abaixoMedia;
                    $a['ano_anterior_ref'] = $ano_anterior;
                    $a['ano_atual_ref'] = (int)$ano;
                    $alertas[] = $a;
                }
                usort($alertas, function ($a, $b) { return $b['score'] <=> $a['score']; });
                $alertas = array_slice($alertas, 0, 12);

                // Metas de Visitas e Premia%�%�o (Usando valores reais do banco)
                $metaVisitaSemana = $metaVisitaSemanaActual;
                $metaVisitasMensal = $metaVisitasMensalActual;
                $valorPremio = $valorPremioActual;
    
                // Visitas M%�s Selecionado (usa m%�s atual quando n%�o filtrado)
                $mesRefVisitas = $mes ? (int)$mes : (int)date('m');
                $anoRefVisitas = $mes ? (int)$ano : (int)date('Y');
                $stmtVisitasMes = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas WHERE data_visita IS NOT NULL AND $visitadorWhereVis AND (status_visita IS NULL OR UPPER(TRIM(status_visita)) <> 'RECUSADA') AND YEAR(data_visita) = :y AND MONTH(data_visita) = :m");
                $qVMes = ['y' => $anoRefVisitas, 'm' => $mesRefVisitas];
                if (!$isMyPharm)
                    $qVMes['nome'] = $nome;
                $stmtVisitasMes->execute($qVMes);
                $visitasMes = (int)$stmtVisitasMes->fetchColumn();
                // Visitas no mês = apenas registros em historico_visitas (não usar fallback por prescritores com vendas)

                // Visitas na semana atual = quantidade de registros em historico_visitas (semana ISO: seg���dom)
                $stmtVisitasSemana = $pdo->prepare("
                    SELECT COUNT(*) FROM historico_visitas 
                    WHERE data_visita IS NOT NULL 
                      AND $visitadorWhereVis 
                      AND (status_visita IS NULL OR UPPER(TRIM(status_visita)) <> 'RECUSADA')
                      AND YEARWEEK(data_visita, 1) = YEARWEEK(CURDATE(), 1)
                ");
                $qVSem = [];
                if (!$isMyPharm)
                    $qVSem['nome'] = $nome;
                $stmtVisitasSemana->execute($qVSem);
                $visitasSemana = (int)$stmtVisitasSemana->fetchColumn();
                // Visitas na semana = apenas historico_visitas (não usar fallback por prescritores com vendas)

                // - Sem filtro de m%�s, mostrar as %Q%ltimas 4 semanas (cont%�nuo)
                $stmtVps = $pdo->prepare("
                    SELECT COUNT(*) FROM historico_visitas 
                    WHERE data_visita IS NOT NULL AND $visitadorWhereVis
                      AND (status_visita IS NULL OR UPPER(TRIM(status_visita)) <> 'RECUSADA')
                      AND YEARWEEK(data_visita, 1) = :yw
                ");
                $visitasPorSemana = [];
    
                if ($mes) {
                    // No filtro mensal: sempre 4 blocos (mesmo se o m%�s tiver 5 segundas/semanas)
                    // 01���07, 08���14, 15���21, 22���%Q%ltimo dia do m%�s
                    $ini = new DateTime(sprintf('%04d-%02d-01', (int)$anoRefVisitas, (int)$mesRefVisitas));
                    $fim = (clone $ini)->modify('last day of this month');
                    $ultimoDia = (int)$fim->format('d');
    
                    $ranges = [
                        [1, 7],
                        [8, 14],
                        [15, 21],
                        [22, $ultimoDia],
                    ];
    
                    $stmtVpsRange = $pdo->prepare("
                        SELECT COUNT(*) FROM historico_visitas
                        WHERE data_visita IS NOT NULL AND $visitadorWhereVis
                          AND (status_visita IS NULL OR UPPER(TRIM(status_visita)) <> 'RECUSADA')
                          AND data_visita >= :ini AND data_visita <= :fim
                    ");
    
                    foreach ($ranges as $rg) {
                        [$dIni, $dFim] = $rg;
                        $dtIni = new DateTime(sprintf('%04d-%02d-%02d', (int)$anoRefVisitas, (int)$mesRefVisitas, $dIni));
                        $dtFim = new DateTime(sprintf('%04d-%02d-%02d', (int)$anoRefVisitas, (int)$mesRefVisitas, $dFim));
    
                        $qVps = ['ini' => $dtIni->format('Y-m-d'), 'fim' => $dtFim->format('Y-m-d')];
                        if (!$isMyPharm) $qVps['nome'] = $nome;
                        $stmtVpsRange->execute($qVps);
                        $total = (int)$stmtVpsRange->fetchColumn();
    
                        // status do bloco para UI: future (ainda n%�o come%�ou), current (em andamento), past (j%� terminou)
                        $hoje = new DateTime(date('Y-m-d'));
                        $status = 'past';
                        if ($hoje < $dtIni) $status = 'future';
                        else if ($hoje <= $dtFim) $status = 'current';
    
                        $visitasPorSemana[] = [
                            'semana' => $dtIni->format('Y-m-d'),
                            'fim' => $dtFim->format('Y-m-d'),
                            'total' => $total,
                            'label' => sprintf('%02d-%02d', $dIni, $dFim),
                            'status' => $status,
                        ];
                    }
                } else {
                    for ($i = 0; $i < 4; $i++) {
                        $ts = strtotime("-$i weeks");
                        $yw = (int)date('o', $ts) * 100 + (int)date('W', $ts);
                        $qVps = ['yw' => $yw];
                        if (!$isMyPharm) $qVps['nome'] = $nome;
                        $stmtVps->execute($qVps);
                        $total = (int)$stmtVps->fetchColumn();
                        $segunda = strtotime('monday this week', $ts);
                        $visitasPorSemana[] = [
                            'semana' => date('Y-m-d', $segunda),
                            'total' => $total,
                            'label' => date('d/m', $segunda)
                        ];
                    }
                }
    
                $metaSemanaMin = max(1, (int)$metaVisitaSemana);
    
                // Se estiver filtrando por m%�s: a "meta semanal" vira meta por 4 blocos do m%�s (quantas semanas bateram a meta)
                if ($mes) {
                    $semanasBatidas = 0;
                    foreach ($visitasPorSemana as &$s) {
                        $s['bateu'] = ((int)$s['total'] >= $metaSemanaMin);
                        if ($s['bateu'])
                            $semanasBatidas++;
                    }
                    unset($s);
    
                    $visitasSemanaExibir = $semanasBatidas; // ex.: 1
                    $metaSemanaExibir = 4; // sempre 4 blocos no m%�s
                    $pctSemanaExibir = min(100, round(($semanasBatidas / 4) * 100)); // ex.: 25%
                    $batouVisitasSemana = ($semanasBatidas >= 4); // s%% bate se bater nas 4
                } else {
                    $visitasSemanaExibir = (int)$visitasSemana; // visitas na semana atual
                    $metaSemanaExibir = $metaSemanaMin;         // meta semanal do usu%�rio (ex.: 30)
                    $pctSemanaExibir = min(100, round(($visitasSemanaExibir / max(1, $metaSemanaExibir)) * 100));
                    $batouVisitasSemana = ($visitasSemanaExibir >= (int)$metaVisitaSemana);
                }
    
                $batouVendas = ($total_aprovado_mensal >= $meta_mensal);
                $batouVisitasMes = ($visitasMes >= $metaVisitasMensal);
                $conquistouPremio = ($batouVendas && $batouVisitasMes && $batouVisitasSemana);
    
                $kpis_visitas = [
                    'semanal' => [
                        'atual' => (int)$visitasSemanaExibir,
                        'meta' => (int)$metaSemanaExibir,
                        'pct' => (int)$pctSemanaExibir
                    ],
                    'mes' => [
                        'atual' => (int)$visitasMes,
                        'meta' => max(1, (int)$metaVisitasMensal),
                        'pct' => min(100, round(($visitasMes / max(1, $metaVisitasMensal)) * 100))
                    ],
                    'visitas_por_semana' => $visitasPorSemana,
                    'premio' => [
                        'valor' => $valorPremio,
                        'conquistado' => $conquistouPremio,
                        'batouVendas' => $batouVendas,
                        'batouVisitasMes' => $batouVisitasMes,
                        'batouVisitasSemana' => $batouVisitasSemana
                    ]
                ];
    
                // =========================
                // RELAT%�RIOS (Semanal + Agendadas)
                // =========================
                // Garantir coluna inicio_visita para dura%�%�o (se ainda n%�o existir)
                try {
                    $pdo->exec("ALTER TABLE historico_visitas ADD COLUMN inicio_visita DATETIME NULL");
                } catch (Exception $e) {
                    // Coluna j%� existe
                }
                // Visitas realizadas na semana atual (seg���dom) + dura%�%�o quando houver inicio_visita
                $stmt = $pdo->prepare("
                    SELECT 
                        hv.id,
                        hv.prescritor,
                        hv.data_visita,
                        hv.horario,
                        hv.inicio_visita,
                        TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_minutos,
                        hv.status_visita,
                        hv.local_visita,
                        hv.resumo_visita,
                        hv.amostra,
                        hv.brinde,
                        hv.artigo,
                        hv.reagendado_para,
                        vg.lat  as geo_lat,
                        vg.lng  as geo_lng,
                        vg.accuracy_m as geo_accuracy
                    FROM historico_visitas hv
                    LEFT JOIN visitas_geolocalizacao vg ON vg.historico_id = hv.id
                    WHERE hv.data_visita IS NOT NULL
                      AND " . str_replace('visitador', 'hv.visitador', $visitadorWhereVis) . "
                      AND (hv.status_visita IS NULL OR UPPER(TRIM(hv.status_visita)) <> 'RECUSADA')
                      AND YEARWEEK(hv.data_visita, 1) = YEARWEEK(CURDATE(), 1)
                    ORDER BY hv.data_visita DESC, hv.horario DESC
                    LIMIT 50
                ");
                $qSem = [];
                if (!$isMyPharm) $qSem['nome'] = $nome;
                $stmt->execute($qSem);
                $relatorio_visitas_semana = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                // Visitas agendadas (pr%%ximas)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS visitas_agendadas (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        visitador VARCHAR(255) NOT NULL,
                        prescritor VARCHAR(255) NOT NULL,
                        data_agendada DATE NOT NULL,
                        hora TIME NULL,
                        observacao TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'agendada',
                        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_visita (visitador, prescritor, data_agendada),
                        INDEX idx_visitador_data (visitador, data_agendada),
                        INDEX idx_status (status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $stmt = $pdo->prepare("
                    SELECT 
                        prescritor,
                        data_agendada,
                        DATE_FORMAT(hora, '%H:%i') as hora,
                        status,
                        observacao
                    FROM visitas_agendadas
                    WHERE $visitadorWhereVis
                      AND status = 'agendada'
                      AND data_agendada >= CURDATE()
                    ORDER BY data_agendada ASC, hora ASC
                    LIMIT 50
                ");
                $qAg = [];
                if (!$isMyPharm) $qAg['nome'] = $nome;
                $stmt->execute($qAg);
                $visitas_agendadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                // Tamb%�m considerar reagendamentos do sistema antigo (historico_visitas.reagendado_para)
                // Aceita formatos comuns: YYYY-MM-DD, YYYY-MM-DD HH:MM, DD/MM/YYYY, DD/MM/YYYY HH:MM
                $expr = "COALESCE(
                    STR_TO_DATE(reagendado_para, '%Y-%m-%d %H:%i'),
                    STR_TO_DATE(reagendado_para, '%Y-%m-%d'),
                    STR_TO_DATE(reagendado_para, '%d/%m/%Y %H:%i'),
                    STR_TO_DATE(reagendado_para, '%d/%m/%Y')
                )";
                $stmt = $pdo->prepare("
                    SELECT 
                        prescritor,
                        DATE($expr) as data_agendada,
                        DATE_FORMAT(
                            COALESCE(
                                STR_TO_DATE(reagendado_para, '%Y-%m-%d %H:%i'),
                                STR_TO_DATE(reagendado_para, '%d/%m/%Y %H:%i')
                            ), '%H:%i'
                        ) as hora
                    FROM historico_visitas
                    WHERE data_visita IS NOT NULL
                      AND $visitadorWhereVis
                      AND reagendado_para IS NOT NULL AND TRIM(reagendado_para) != ''
                      AND $expr IS NOT NULL
                      AND DATE($expr) >= CURDATE()
                    ORDER BY DATE($expr) ASC
                    LIMIT 50
                ");
                $stmt->execute($qAg);
                $agOld = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                // Deduplicar por prescritor+data+hora
                $map = [];
                foreach ($visitas_agendadas as $r) {
                    $k = ($r['prescritor'] ?? '') . '|' . ($r['data_agendada'] ?? '') . '|' . ($r['hora'] ?? '');
                    $map[$k] = true;
                }
                foreach ($agOld as $r) {
                    if (empty($r['data_agendada'])) continue;
                    $row = [
                        'prescritor' => $r['prescritor'] ?? '',
                        'data_agendada' => $r['data_agendada'],
                        'hora' => $r['hora'] ?? null,
                        'status' => 'agendada',
                        'observacao' => null
                    ];
                    $k = ($row['prescritor'] ?? '') . '|' . ($row['data_agendada'] ?? '') . '|' . ($row['hora'] ?? '');
                    if (!isset($map[$k])) {
                        $visitas_agendadas[] = $row;
                        $map[$k] = true;
                    }
                }
    
                // Ordenar resultado final
                usort($visitas_agendadas, function ($a, $b) {
                    $da = ($a['data_agendada'] ?? '') . ' ' . ($a['hora'] ?? '');
                    $db = ($b['data_agendada'] ?? '') . ' ' . ($b['hora'] ?? '');
                    return strcmp($da, $db);
                });
    
                // ============================
                // Mapa de Visitas (GPS) no per%�odo
                // ============================
                // Garantir tabela (para ambientes onde ainda n%�o houve grava%�%�o de GPS)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS visitas_geolocalizacao (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        historico_id INT NULL,
                        visitador VARCHAR(255) NOT NULL,
                        prescritor VARCHAR(255) NOT NULL,
                        data_visita DATE NOT NULL,
                        horario TIME NULL,
                        lat DECIMAL(10,7) NOT NULL,
                        lng DECIMAL(10,7) NOT NULL,
                        accuracy_m DECIMAL(10,2) NULL,
                        provider VARCHAR(50) NOT NULL DEFAULT 'browser_geolocation',
                        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_hist (historico_id),
                        INDEX idx_visitador_data (visitador, data_visita),
                        INDEX idx_prescritor_data (prescritor, data_visita)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
    
                // Contadores do per%�odo (todas / realizadas)
                $whereHV = "WHERE data_visita IS NOT NULL AND YEAR(data_visita) = :ano AND $visitadorWhereVis";
                $whereHVReal = $whereHV . " AND status_visita = 'Realizada'";
                if ($mes) {
                    $whereHV .= " AND MONTH(data_visita) = :mes";
                    $whereHVReal .= " AND MONTH(data_visita) = :mes";
                }
                $qHv = ['ano' => (int)$ano];
                if (!$isMyPharm) $qHv['nome'] = $nome;
                if ($mes) $qHv['mes'] = (int)$mes;
    
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas $whereHV");
                $stmt->execute($qHv);
                $totalVisitasPeriodo = (int)$stmt->fetchColumn();
    
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas $whereHVReal");
                $stmt->execute($qHv);
                $totalVisitasRealizadas = (int)$stmt->fetchColumn();
    
                // Visitas com GPS (pontos) no per%�odo (por padr%�o: somente visitas realizadas)
                $visitadorWhereGeo = $isMyPharm
                    ? "(vg.visitador IS NULL OR TRIM(vg.visitador) = '' OR LOWER(TRIM(vg.visitador)) = 'my pharm')"
                    : "TRIM(COALESCE(vg.visitador, '')) = TRIM(:nome)";
                $whereGeo = "WHERE YEAR(vg.data_visita) = :ano AND $visitadorWhereGeo";
                if ($mes) $whereGeo .= " AND MONTH(vg.data_visita) = :mes";
    
                $stmt = $pdo->prepare("
                    SELECT 
                        vg.lat,
                        vg.lng,
                        vg.accuracy_m,
                        vg.data_visita,
                        DATE_FORMAT(vg.horario, '%H:%i') as horario,
                        vg.prescritor,
                        hv.status_visita,
                        hv.local_visita,
                        hv.resumo_visita
                    FROM visitas_geolocalizacao vg
                    LEFT JOIN historico_visitas hv ON hv.id = vg.historico_id
                    $whereGeo
                      AND (hv.status_visita IS NULL OR hv.status_visita = 'Realizada')
                    ORDER BY vg.data_visita ASC, vg.horario ASC, vg.id ASC
                    LIMIT 800
                ");
                $stmt->execute($qHv);
                $visitas_mapa = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                // Dist%�ncia padr%�o do per%�odo: rotas do dia (GPS cont%�nuo em rotas_pontos)
                $km_rotas_periodo = 0.0;
                try {
                    $whereRotaVis = "WHERE YEAR(rd.data_inicio) = :ano_r AND TRIM(COALESCE(rd.visitador_nome, '')) = TRIM(:nome_r)";
                    $paramsRotaVis = ['ano_r' => (int)$ano, 'nome_r' => $nome];
                    if ($mes) {
                        $whereRotaVis .= " AND MONTH(rd.data_inicio) = :mes_r";
                        $paramsRotaVis['mes_r'] = (int)$mes;
                    }
    
                    $stmtRotas = $pdo->prepare("
                        SELECT rd.id
                        FROM rotas_diarias rd
                        $whereRotaVis
                        ORDER BY rd.data_inicio DESC
                        LIMIT 800
                    ");
                    $stmtRotas->execute($paramsRotaVis);
                    $rotasIds = $stmtRotas->fetchAll(PDO::FETCH_COLUMN);
    
                    $haversine = function ($lat1, $lon1, $lat2, $lon2) {
                        $lat1 = (float)$lat1; $lon1 = (float)$lon1; $lat2 = (float)$lat2; $lon2 = (float)$lon2;
                        $R = 6371;
                        $dLat = deg2rad($lat2 - $lat1);
                        $dLon = deg2rad($lon2 - $lon1);
                        $a = sin($dLat / 2) * sin($dLat / 2)
                            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
                        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                        return $R * $c;
                    };
    
                    foreach ($rotasIds as $ridRaw) {
                        $rid = (int)$ridRaw;
                        if ($rid <= 0) continue;

                        $stmtRD = $pdo->prepare("SELECT DATE(data_inicio) as dia, data_fim FROM rotas_diarias WHERE id = :rid LIMIT 1");
                        $stmtRD->execute(['rid' => $rid]);
                        $rdRow = $stmtRD->fetch(PDO::FETCH_ASSOC);
                        $diaRota = $rdRow ? ($rdRow['dia'] ?? null) : null;
                        $dataFimRota = $rdRow ? ($rdRow['data_fim'] ?? null) : null;

                        if ($dataFimRota !== null) {
                            $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid AND criado_em <= :data_fim ORDER BY criado_em ASC");
                            $stmtP->execute(['rid' => $rid, 'data_fim' => $dataFimRota]);
                        } else {
                            $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
                            $stmtP->execute(['rid' => $rid]);
                        }
                        $pts = $stmtP->fetchAll(PDO::FETCH_ASSOC);

                        $allGps = [];
                        foreach ($pts as $p) {
                            $allGps[] = ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'ts' => strtotime($p['criado_em'])];
                        }
                        if ($diaRota) {
                            $stmtVG = $pdo->prepare("
                                SELECT vg.lat, vg.lng, COALESCE(hv.inicio_visita, CONCAT(hv.data_visita,' ',COALESCE(hv.horario,'00:00:00'))) as ts_ref
                                FROM visitas_geolocalizacao vg
                                INNER JOIN historico_visitas hv ON hv.id = vg.historico_id
                                WHERE TRIM(vg.visitador) = TRIM(:v) AND vg.data_visita = :dia
                            ");
                            $stmtVG->execute(['v' => $nome, 'dia' => $diaRota]);
                            foreach ($stmtVG->fetchAll(PDO::FETCH_ASSOC) as $vg) {
                                if ($vg['lat'] && $vg['lng']) {
                                    $allGps[] = ['lat' => (float)$vg['lat'], 'lng' => (float)$vg['lng'], 'ts' => strtotime($vg['ts_ref'])];
                                }
                            }
                        }
                        usort($allGps, function ($a, $b) { return $a['ts'] - $b['ts']; });

                        $allCount = count($allGps);
                        for ($i = 1; $i < $allCount; $i++) {
                            $segKm = $haversine($allGps[$i - 1]['lat'], $allGps[$i - 1]['lng'], $allGps[$i]['lat'], $allGps[$i]['lng']);
                            if ($segKm < 50) {
                                $km_rotas_periodo += $segKm;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // fallback silencioso: se n%�o conseguir calcular por rota, mant%�m 0 e frontend usa GPS de visitas
                }
    
                $visitas_mapa_resumo = [
                    'total_visitas_periodo' => $totalVisitasPeriodo,
                    'total_visitas_realizadas' => $totalVisitasRealizadas,
                    'total_pontos_gps' => is_array($visitas_mapa) ? count($visitas_mapa) : 0,
                    'km_rotas_periodo' => round($km_rotas_periodo, 2)
                ];
    
                $meta = 50000;
    
                echo json_encode([
                    'kpis' => $kpis,
                    'kpis_visitas' => $kpis_visitas,
                    'relatorio_visitas_semana' => $relatorio_visitas_semana,
                    'visitas_agendadas' => $visitas_agendadas,
                    'visitas_mapa' => $visitas_mapa,
                    'visitas_mapa_resumo' => $visitas_mapa_resumo,
                    'top_prescritores' => $top_prescritores,
                    'evolucao' => $evolucao,
                    'top_produtos' => $top_produtos,
                    'top_especialidades' => $top_especialidades,
                    'alertas' => $alertas,
                    'meta' => $meta
                ], JSON_UNESCAPED_UNICODE);
}

/**
 * Lista pedidos do visitador: aprovados e recusados+carinho (agrupados por numero_pedido + serie_pedido).
 * Par�metros: nome, ano, mes (opcional).
 */
function dashboardListPedidosVisitador(PDO $pdo): void
{
    $nome = trim($_GET['nome'] ?? '');
    $ano = (int)($_GET['ano'] ?? date('Y'));
    $mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;
    $prescritorFilter = isset($_GET['prescritor']) ? trim((string)$_GET['prescritor']) : null;
    if ($prescritorFilter === '') $prescritorFilter = null;
    // Se o usuário logado é visitador, usar sempre o nome da sessão (só vê pedidos da sua carteira)
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    $sessionNome = trim($_SESSION['user_nome'] ?? '');
    if (($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false) && $sessionNome !== '') {
        $nome = $sessionNome;
    }
    if (!$nome) {
        echo json_encode(['error' => 'Nome do visitador n�o fornecido'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $params = ['ano' => $ano, 'nome' => $nome];
    if ($mes) $params['mes'] = $mes;
    if ($prescritorFilter !== null) $params['prescritor'] = $prescritorFilter;
    $filtroMesGp = $mes ? " AND MONTH(gp.data_aprovacao) = :mes" : "";
    $filtroPrescritor = $prescritorFilter !== null ? " AND (COALESCE(NULLIF(TRIM(gp.prescritor),''), 'My Pharm') = :prescritor)" : "";

    // Aprovados: gestao_pedidos (Relatório de Gestão de Pedidos)
    $sqlAprovados = "
        SELECT gp.numero_pedido, gp.serie_pedido, gp.data_aprovacao, gp.data_orcamento,
               COALESCE(NULLIF(TRIM(gp.prescritor),''), 'My Pharm') as prescritor,
               COALESCE(NULLIF(TRIM(gp.cliente),''), gp.paciente) as cliente,
               gp.preco_liquido as valor
        FROM gestao_pedidos gp
        INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''), 'My Pharm') = pc.nome
           AND TRIM(COALESCE(pc.visitador, '')) = TRIM(:nome)
        WHERE gp.ano_referencia = :ano
          AND gp.data_aprovacao IS NOT NULL
          AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', CONCAT('Or', CHAR(231), 'amento')) AND (gp.status_financeiro NOT LIKE '%carrinho%')))
          $filtroMesGp
          $filtroPrescritor
        ORDER BY gp.data_aprovacao DESC, gp.numero_pedido DESC, gp.serie_pedido DESC
    ";
    $stmtA = $pdo->prepare($sqlAprovados);
    $stmtA->execute($params);
    $aprovados = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    // Recusados/No carrinho: itens + prescritor_resumido (mesma regra da importação: nome do CSV ↔ visitador)
    $nomeList = trim($nome);
    $isMyPharmList = (strcasecmp($nomeList, 'My Pharm') === 0);
    $visitadorPrSql = $isMyPharmList
        ? "(pr.visitador IS NULL OR TRIM(pr.visitador) = '' OR LOWER(TRIM(pr.visitador)) = 'my pharm' OR pr.visitador = 'My Pharm' OR UPPER(TRIM(pr.visitador)) = 'MY PHARM')"
        : 'TRIM(COALESCE(pr.visitador, \'\')) = TRIM(:nome)';

    $filtroMesItens = $mes
        ? " AND MONTH(COALESCE(i.data, gpad.dt_orc, gpad.dt_apr)) = :mes"
        : "";
    $filtroPrescritorItens = $prescritorFilter !== null
        ? " AND UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm'))) = UPPER(TRIM(:prescritor))"
        : "";

    $statusRecusadoSql = "(LOWER(TRIM(COALESCE(i.status,''))) IN ('recusado', 'no carrinho') OR TRIM(COALESCE(i.status,'')) IN ('Recusado', 'No carrinho'))";

    // Data do orçamento: qualquer linha do mesmo nº+ano (não filtrar por status — a data é do pedido)
    $subDataItem = "(SELECT MAX(x.data) FROM itens_orcamentos_pedidos x
        WHERE x.numero = i.numero AND x.ano_referencia = i.ano_referencia)";

    // Datas do pedido na gestão (mesmo nº/série/ano) — fallback quando itens.data vier vazio
    $joinGpad = "
        LEFT JOIN (
            SELECT numero_pedido, ano_referencia,
                MAX(data_orcamento) AS dt_orc,
                MAX(data_aprovacao) AS dt_apr
            FROM gestao_pedidos
            GROUP BY numero_pedido, ano_referencia
        ) gpad ON gpad.numero_pedido = i.numero AND gpad.ano_referencia = i.ano_referencia
    ";

    $sqlRecusadosPr = "
        SELECT i.numero as numero_pedido, i.serie as serie_pedido,
               NULL as data_aprovacao,
               COALESCE(DATE($subDataItem), DATE(MAX(gpad.dt_orc)), DATE(MAX(gpad.dt_apr))) as data_orcamento,
               COALESCE(NULLIF(TRIM(MAX(i.prescritor)),''), 'My Pharm') as prescritor,
               COALESCE(MAX(NULLIF(TRIM(i.paciente),'')), '-') as cliente,
               SUM(i.valor_liquido) as valor,
               MAX(CASE WHEN LOWER(TRIM(COALESCE(i.status,''))) LIKE 'recusad%' THEN 'Recusado' ELSE 'No carrinho' END) as status_financeiro
        FROM itens_orcamentos_pedidos i
        $joinGpad
        INNER JOIN prescritor_resumido pr
            ON pr.nome = COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm')
            AND pr.ano_referencia = i.ano_referencia
            AND $visitadorPrSql
        WHERE i.ano_referencia = :ano
          AND $statusRecusadoSql
          $filtroMesItens
          $filtroPrescritorItens
        GROUP BY i.numero, i.serie, COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm')
        ORDER BY i.numero DESC, i.serie DESC
    ";

    $visitadorPcSql = $isMyPharmList
        ? "(pc.visitador IS NULL OR TRIM(pc.visitador) = '' OR LOWER(TRIM(pc.visitador)) = 'my pharm' OR pc.visitador = 'My Pharm' OR UPPER(TRIM(pc.visitador)) = 'MY PHARM')"
        : 'TRIM(COALESCE(pc.visitador, \'\')) = TRIM(:nome)';

    $sqlRecusadosPc = "
        SELECT i.numero as numero_pedido, i.serie as serie_pedido,
               NULL as data_aprovacao,
               COALESCE(DATE($subDataItem), DATE(MAX(gpad.dt_orc)), DATE(MAX(gpad.dt_apr))) as data_orcamento,
               pc.nome as prescritor,
               COALESCE(MAX(NULLIF(TRIM(i.paciente),'')), '-') as cliente,
               SUM(i.valor_liquido) as valor,
               MAX(CASE WHEN LOWER(TRIM(COALESCE(i.status,''))) LIKE 'recusad%' THEN 'Recusado' ELSE 'No carrinho' END) as status_financeiro
        FROM itens_orcamentos_pedidos i
        $joinGpad
        INNER JOIN prescritores_cadastro pc
            ON UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm'))) = UPPER(TRIM(pc.nome))
            AND $visitadorPcSql
        WHERE i.ano_referencia = :ano
          AND $statusRecusadoSql
          $filtroMesItens
          $filtroPrescritorItens
        GROUP BY i.numero, i.serie, pc.nome
        ORDER BY i.numero DESC, i.serie DESC
    ";

    // Params para recusados: quando isMyPharmList, :nome NÃO existe no SQL
    // (a condição de visitador é hardcoded), então remover para evitar HY093.
    $paramsRec = $params;
    if ($isMyPharmList) {
        unset($paramsRec['nome']);
    }

    $recPr = [];
    $recPc = [];
    try {
        $stmtR = $pdo->prepare($sqlRecusadosPr);
        $stmtR->execute($paramsRec);
        $recPr = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignora */ }
    try {
        $stmtR2 = $pdo->prepare($sqlRecusadosPc);
        $stmtR2->execute($paramsRec);
        $recPc = $stmtR2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignora */ }

    $recusadosMap = [];
    foreach (array_merge($recPr, $recPc) as $row) {
        $k = (int)($row['numero_pedido'] ?? 0) . '_' . (int)($row['serie_pedido'] ?? 0);
        $dNew = trim((string)($row['data_orcamento'] ?? ''));
        $okNew = ($dNew !== '' && strpos($dNew, '0000-00-00') !== 0);
        if (!isset($recusadosMap[$k])) {
            $recusadosMap[$k] = $row;
        } else {
            $dOld = trim((string)($recusadosMap[$k]['data_orcamento'] ?? ''));
            $okOld = ($dOld !== '' && strpos($dOld, '0000-00-00') !== 0);
            if (!$okOld && $okNew) {
                $recusadosMap[$k] = $row;
            }
        }
    }
    $recusados_carrinho = array_values($recusadosMap);

    // Garantir data_orcamento: mapa em lote (evita NULL do GROUP BY / merge) + fallback gestão
    try {
        $stIt = $pdo->prepare("
            SELECT numero, serie, MAX(data) AS dt
            FROM itens_orcamentos_pedidos
            WHERE ano_referencia = :ano
            GROUP BY numero, serie
        ");
        $stIt->execute(['ano' => $ano]);
        $mapDataItem = [];
        while ($z = $stIt->fetch(PDO::FETCH_ASSOC)) {
            $dt = $z['dt'] ?? null;
            if ($dt === null || $dt === '' || strpos((string)$dt, '0000-00-00') === 0) {
                continue;
            }
            $mapDataItem[(int)$z['numero'] . '_' . (int)$z['serie']] = substr((string)$dt, 0, 10);
        }
        // Fonte oficial da data do orçamento: tabela orcamentos_pedidos (campo data por numero+serie)
        $mapOrcOp = [];
        try {
            $stOp = $pdo->query(
                'SELECT numero, serie, MAX(`data`) AS dt FROM orcamentos_pedidos GROUP BY numero, serie'
            );
            if ($stOp) {
                while ($z = $stOp->fetch(PDO::FETCH_ASSOC)) {
                    $dt = $z['dt'] ?? null;
                    if ($dt === null || $dt === '' || strpos((string)$dt, '0000-00-00') === 0) {
                        continue;
                    }
                    $mapOrcOp[(int)$z['numero'] . '_' . (int)($z['serie'] ?? 0)] = substr((string)$dt, 0, 10);
                }
            }
        } catch (Throwable $e) {
            /* tabela pode não existir em alguns ambientes */
        }
        $stGp = $pdo->prepare("
            SELECT numero_pedido, serie_pedido,
                MAX(data_orcamento) AS dorc, MAX(data_aprovacao) AS dapr
            FROM gestao_pedidos
            WHERE ano_referencia = :ano
            GROUP BY numero_pedido, serie_pedido
        ");
        $stGp->execute(['ano' => $ano]);
        $mapDataGp = [];
        while ($z = $stGp->fetch(PDO::FETCH_ASSOC)) {
            $tOrc = 0;
            $tApr = 0;
            foreach (['dorc', 'dapr'] as $idx => $col) {
                $v = $z[$col] ?? null;
                if ($v && strpos((string)$v, '0000-00-00') !== 0) {
                    $ts = @strtotime((string)$v);
                    if ($ts > 0) {
                        if ($idx === 0) {
                            $tOrc = max($tOrc, $ts);
                        } else {
                            $tApr = max($tApr, $ts);
                        }
                    }
                }
            }
            $kkGp = (int)$z['numero_pedido'] . '_' . (int)$z['serie_pedido'];
            if ($tOrc > 0) {
                $mapDataGp[$kkGp] = date('Y-m-d', $tOrc);
            } elseif ($tApr > 0) {
                $mapDataGp[$kkGp] = date('Y-m-d', $tApr);
            }
        }
        foreach ($recusados_carrinho as &$rw) {
            $kk = (int)($rw['numero_pedido'] ?? 0) . '_' . (int)($rw['serie_pedido'] ?? 0);
            if (isset($mapOrcOp[$kk])) {
                $rw['data_orcamento'] = $mapOrcOp[$kk];
            } else {
                $dorc = isset($rw['data_orcamento']) ? trim((string)$rw['data_orcamento']) : '';
                if ($dorc === '' || $dorc === '0000-00-00' || strpos($dorc, '0000-00-00') === 0) {
                    if (isset($mapDataItem[$kk])) {
                        $rw['data_orcamento'] = $mapDataItem[$kk];
                    } elseif (isset($mapDataGp[$kk])) {
                        $rw['data_orcamento'] = $mapDataGp[$kk];
                    }
                }
            }
        }
        unset($rw);
    } catch (Throwable $e) { /* mantém datas já vindas da query */ }

    usort($recusados_carrinho, function ($a, $b) {
        $ref = function ($r) {
            $o = substr((string)($r['data_orcamento'] ?? ''), 0, 10);
            $ap = substr((string)($r['data_aprovacao'] ?? ''), 0, 10);
            return $o !== '' ? $o : $ap;
        };
        $da = $ref($a);
        $db = $ref($b);
        $c = strcmp($db, $da);
        if ($c !== 0) return $c;
        $c = ((int)($b['numero_pedido'] ?? 0) - (int)($a['numero_pedido'] ?? 0));
        if ($c !== 0) return $c > 0 ? 1 : -1;
        return ((int)($b['serie_pedido'] ?? 0) - (int)($a['serie_pedido'] ?? 0)) > 0 ? 1 : -1;
    });

    $normalizarData = function (&$row) {
        foreach (['data_aprovacao', 'data_orcamento'] as $campo) {
            if (!empty($row[$campo]) && preg_match('/^\d{4}-\d{2}-\d{2}/', $row[$campo])) {
                $row[$campo] = substr($row[$campo], 0, 19);
            }
        }
    };
    foreach ($aprovados as &$r) { $normalizarData($r); }
    unset($r);
    foreach ($recusados_carrinho as &$r) { $normalizarData($r); }
    unset($r);

    echo json_encode([
        'aprovados' => $aprovados,
        'recusados_carrinho' => $recusados_carrinho
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Lista componentes (agregados) por prescritor: aprovados ou recusados.
 * Fonte: pedidos_detalhado_componentes, filtrando por pedidos do prescritor na carteira do visitador.
 * Parâmetros: nome (visitador), ano, prescritor, tipo (aprovados | recusados).
 */
function dashboardListComponentesPrescritor(PDO $pdo): void
{
    $nome = trim($_GET['nome'] ?? '');
    $ano = (int)($_GET['ano'] ?? date('Y'));
    $prescritor = isset($_GET['prescritor']) ? trim((string)$_GET['prescritor']) : null;
    $tipo = isset($_GET['tipo']) ? strtolower(trim((string)$_GET['tipo'])) : 'aprovados';
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
    if ($dataDe === '' || $dataDe === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) $dataDe = null;
    if ($dataAte === '' || $dataAte === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) $dataAte = null;
    $usePeriod = $dataDe !== null && $dataAte !== null && $dataDe <= $dataAte;
    if ($usePeriod) $ano = (int)substr($dataAte, 0, 4);

    if ($prescritor === '' || $prescritor === null) {
        echo json_encode(['error' => 'Prescritor não informado', 'componentes' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    $sessionNome = trim($_SESSION['user_nome'] ?? '');
    if (($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false) && $sessionNome !== '') {
        $nome = $sessionNome;
    }
    if ($nome === '') {
        echo json_encode(['error' => 'Nome do visitador não fornecido', 'componentes' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    $isMyPharm = (strcasecmp($nome, 'My Pharm') === 0);
    $visWhere = $isMyPharm
        ? "(pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')"
        : "pc.visitador = :nome";

    $params = ['ano_ord' => $ano, 'prescritor' => $prescritor];
    if (!$isMyPharm) $params['nome'] = $nome;
    if ($usePeriod) {
        $params['data_de'] = $dataDe;
        $params['data_ate'] = $dataAte;
    }

    $filtroPeriodoGp = $usePeriod ? " AND DATE(gp.data_aprovacao) BETWEEN :data_de AND :data_ate" : "";
    $filtroPeriodoI = $usePeriod ? " AND i.`data` BETWEEN :data_de AND :data_ate" : "";

    try {
        if ($tipo === 'recusados') {
            $sql = "
                SELECT
                    COALESCE(NULLIF(TRIM(c.componente), ''), '(sem nome)') as componente,
                    COALESCE(NULLIF(TRIM(c.unidade_componente), ''), '—') as unidade,
                    SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))) as quantidade_total,
                    COUNT(DISTINCT CONCAT(CAST(c.numero AS CHAR), '-', CAST(c.serie AS CHAR))) as qtd_pedidos
                FROM pedidos_detalhado_componentes c
                INNER JOIN (
                    SELECT DISTINCT i.numero, i.serie
                    FROM itens_orcamentos_pedidos i
                    INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm') = pc.nome AND " . $visWhere . "
                    WHERE i.ano_referencia = :ano_ord
                      AND (i.status = 'Recusado' OR i.status = 'No carrinho')
                      AND (COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm') = :prescritor)
                      " . $filtroPeriodoI . "
                ) ord ON ord.numero = c.numero AND ord.serie = c.serie
                GROUP BY COALESCE(NULLIF(TRIM(c.componente), ''), '(sem nome)'), COALESCE(NULLIF(TRIM(c.unidade_componente), ''), '—')
                ORDER BY quantidade_total DESC, componente ASC
            ";
        } else {
            $sql = "
                SELECT
                    COALESCE(NULLIF(TRIM(c.componente), ''), '(sem nome)') as componente,
                    COALESCE(NULLIF(TRIM(c.unidade_componente), ''), '—') as unidade,
                    SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))) as quantidade_total,
                    COUNT(DISTINCT CONCAT(CAST(c.numero AS CHAR), '-', CAST(c.serie AS CHAR))) as qtd_pedidos
                FROM pedidos_detalhado_componentes c
                INNER JOIN (
                    SELECT DISTINCT gp.numero_pedido as numero, gp.serie_pedido as serie
                    FROM gestao_pedidos gp
                    INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') = pc.nome AND " . $visWhere . "
                    WHERE gp.ano_referencia = :ano_ord
                      AND gp.data_aprovacao IS NOT NULL
                      AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orcamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
                      AND (COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') = :prescritor)
                      " . $filtroPeriodoGp . "
                ) ord ON ord.numero = c.numero AND ord.serie = c.serie
                GROUP BY COALESCE(NULLIF(TRIM(c.componente), ''), '(sem nome)'), COALESCE(NULLIF(TRIM(c.unidade_componente), ''), '—')
                ORDER BY quantidade_total DESC, componente ASC
            ";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $componentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['componentes' => $componentes], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao listar componentes: ' . $e->getMessage(), 'componentes' => []], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Evolução mensal do prescritor: vendas aprovadas/reprovadas e componentes aprovados/reprovados por mês.
 * Parâmetros: prescritor, ano, nome (visitador, opcional — usa sessão).
 * Retorna: vendas_mensal [{ mes, valor_aprovado, valor_reprovado }], componentes_mensal [{ mes, qtd_aprovado, qtd_reprovado }].
 */
function dashboardEvolucaoPrescritor(PDO $pdo): void
{
    $prescritor = trim($_GET['prescritor'] ?? '');
    $ano = (int)($_GET['ano'] ?? date('Y'));
    $nome = trim($_GET['nome'] ?? '');
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    if ($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false) {
        $nome = trim($_SESSION['user_nome'] ?? '');
    }
    if ($prescritor === '') {
        echo json_encode(['error' => 'Prescritor não informado', 'vendas_mensal' => [], 'componentes_mensal' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $prescritor = preg_replace('/\s+/', ' ', $prescritor);
    $nome = preg_replace('/\s+/', ' ', $nome);
    $isMyPharm = (strcasecmp($nome, 'My Pharm') === 0);
    $visWhere = $isMyPharm
        ? "(pc.visitador IS NULL OR TRIM(pc.visitador) = '' OR LOWER(TRIM(pc.visitador)) = 'my pharm')"
        : "LOWER(TRIM(pc.visitador)) = LOWER(TRIM(:nome))";
    $paramsBase = ['ano' => $ano, 'prescritor' => $prescritor];
    if (!$isMyPharm) {
        $paramsBase['nome'] = $nome;
    }

    try {
        // 1) Vendas por mês: aprovado e reprovado (gestao_pedidos)
        $sqlVendas = "
            SELECT
                MONTH(gp.data_aprovacao) as mes,
                COALESCE(SUM(CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                COALESCE(SUM(CASE WHEN gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%' THEN gp.preco_liquido ELSE 0 END), 0) as valor_reprovado
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') = pc.nome AND " . $visWhere . "
            WHERE gp.ano_referencia = :ano
              AND (LOWER(TRIM(COALESCE(NULLIF(gp.prescritor), ''), 'My Pharm')) = LOWER(TRIM(:prescritor)))
              AND gp.data_aprovacao IS NOT NULL
            GROUP BY MONTH(gp.data_aprovacao)
            ORDER BY mes
        ";
        $stmtV = $pdo->prepare($sqlVendas);
        $stmtV->execute($paramsBase);
        $vendasPorMes = [];
        while ($row = $stmtV->fetch(PDO::FETCH_ASSOC)) {
            $vendasPorMes[(int)$row['mes']] = [
                'mes' => (int)$row['mes'],
                'valor_aprovado' => (float)($row['valor_aprovado'] ?? 0),
                'valor_reprovado' => (float)($row['valor_reprovado'] ?? 0),
            ];
        }
        $vendas_mensal = [];
        for ($m = 1; $m <= 12; $m++) {
            $vendas_mensal[] = $vendasPorMes[$m] ?? ['mes' => $m, 'valor_aprovado' => 0.0, 'valor_reprovado' => 0.0];
        }

        // 2) Componentes por mês: quantidade total aprovada e reprovada
        $sqlCompAprov = "
            SELECT MONTH(gp.data_aprovacao) as mes,
                   COALESCE(SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))), 0) as qtd
            FROM pedidos_detalhado_componentes c
            INNER JOIN gestao_pedidos gp ON c.numero = gp.numero_pedido AND c.serie = gp.serie_pedido
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') = pc.nome AND " . $visWhere . "
            WHERE gp.ano_referencia = :ano
              AND (LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm'))) = LOWER(TRIM(:prescritor)))
              AND gp.data_aprovacao IS NOT NULL
              AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
            GROUP BY MONTH(gp.data_aprovacao)
        ";
        $stmtCA = $pdo->prepare($sqlCompAprov);
        $stmtCA->execute($paramsBase);
        $compAprov = [];
        while ($row = $stmtCA->fetch(PDO::FETCH_ASSOC)) {
            $compAprov[(int)$row['mes']] = (float)($row['qtd'] ?? 0);
        }

        $sqlCompRec = "
            SELECT MONTH(i.`data`) as mes,
                   COALESCE(SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))), 0) as qtd
            FROM pedidos_detalhado_componentes c
            INNER JOIN itens_orcamentos_pedidos i ON c.numero = i.numero AND c.serie = i.serie
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm') = pc.nome AND " . $visWhere . "
            WHERE i.ano_referencia = :ano
              AND (LOWER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'))) = LOWER(TRIM(:prescritor)))
              AND (i.status = 'Recusado' OR i.status = 'No carrinho')
            GROUP BY MONTH(i.`data`)
        ";
        $stmtCR = $pdo->prepare($sqlCompRec);
        $stmtCR->execute($paramsBase);
        $compRec = [];
        while ($row = $stmtCR->fetch(PDO::FETCH_ASSOC)) {
            $compRec[(int)$row['mes']] = (float)($row['qtd'] ?? 0);
        }

        $componentes_mensal = [];
        for ($m = 1; $m <= 12; $m++) {
            $componentes_mensal[] = [
                'mes' => $m,
                'qtd_aprovado' => $compAprov[$m] ?? 0.0,
                'qtd_reprovado' => $compRec[$m] ?? 0.0,
            ];
        }

        echo json_encode([
            'vendas_mensal' => $vendas_mensal,
            'componentes_mensal' => $componentes_mensal,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar evolução: ' . $e->getMessage(), 'vendas_mensal' => [], 'componentes_mensal' => []], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Retorna um prescritor com dados no ano (exemplo para demos / integrações).
 */
function dashboardGetExemploPrescritor(PDO $pdo): void
{
    $ano = (int)($_GET['ano'] ?? date('Y'));
    $stmt = $pdo->prepare("
        SELECT TRIM(gp.prescritor) AS prescritor, COALESCE(NULLIF(TRIM(pc.visitador), ''), 'My Pharm') AS visitador
        FROM gestao_pedidos gp
        LEFT JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') = pc.nome
        WHERE gp.ano_referencia = :ano AND gp.data_aprovacao IS NOT NULL
          AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
        GROUP BY TRIM(gp.prescritor), pc.visitador
        ORDER BY SUM(gp.preco_liquido) DESC
        LIMIT 1
    ");
    $stmt->execute(['ano' => $ano]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Nenhum prescritor com dados no ano'], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode([
        'success' => true,
        'prescritor' => $row['prescritor'] ?? '',
        'visitador' => $row['visitador'] ?? 'My Pharm',
        'ano' => $ano,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Análise completa do prescritor: KPIs, score, comparativos, distribuições, visitas, recorrência e evolução mensal.
 */
function dashboardAnalisePrescritor(PDO $pdo): void
{
    $prescritor = trim($_GET['prescritor'] ?? '');
    $ano = (int)($_GET['ano'] ?? date('Y'));
    $nome = trim($_GET['nome'] ?? '');
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    if ($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false) {
        $nome = trim($_SESSION['user_nome'] ?? '');
    }
    if ($prescritor === '') {
        echo json_encode(['error' => 'Prescritor não informado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $prescritor = preg_replace('/\s+/', ' ', $prescritor);
    $nome = preg_replace('/\s+/', ' ', $nome);
    $isMyPharm = (strcasecmp($nome, 'My Pharm') === 0);
    $visWhere = $isMyPharm
        ? "(pc.visitador IS NULL OR TRIM(pc.visitador) = '' OR LOWER(TRIM(pc.visitador)) = 'my pharm')"
        : "LOWER(TRIM(pc.visitador)) = LOWER(TRIM(:nome))";
    $paramsBase = ['ano' => $ano, 'prescritor' => $prescritor];
    if (!$isMyPharm) {
        $paramsBase['nome'] = $nome;
    }

    try {
        // ── 1) Evolução mensal de VENDAS (valores R$) ──
        // Aprovados: gestao_pedidos (pedidos efetivamente aprovados)
        $sqlVendas = "
            SELECT MONTH(gp.data_aprovacao) as mes,
                COALESCE(SUM(CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN gp.preco_liquido ELSE 0 END),0) as valor_aprovado
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor)) AND gp.data_aprovacao IS NOT NULL
            GROUP BY MONTH(gp.data_aprovacao) ORDER BY mes";
        $stmtV = $pdo->prepare($sqlVendas);
        $stmtV->execute($paramsBase);
        $vendasMap = [];
        while ($r = $stmtV->fetch(PDO::FETCH_ASSOC)) {
            $vendasMap[(int)$r['mes']] = ['valor_aprovado' => (float)$r['valor_aprovado']];
        }
        // Reprovados: itens_orcamentos_pedidos (pedidos recusados ou no carrinho não estão em gestao_pedidos)
        $sqlVendasRepr = "
            SELECT MONTH(i.`data`) as mes, COALESCE(SUM(i.valor_liquido),0) as valor_reprovado
            FROM itens_orcamentos_pedidos i
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE i.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (i.status = 'Recusado' OR i.status = 'No carrinho')
            GROUP BY MONTH(i.`data`) ORDER BY mes";
        $stmtVR = $pdo->prepare($sqlVendasRepr);
        $stmtVR->execute($paramsBase);
        $vendasReprMap = [];
        while ($r = $stmtVR->fetch(PDO::FETCH_ASSOC)) {
            $vendasReprMap[(int)$r['mes']] = (float)$r['valor_reprovado'];
        }
        $vendas_mensal = [];
        for ($m = 1; $m <= 12; $m++) {
            $vendas_mensal[] = [
                'mes' => $m,
                'valor_aprovado' => (float)($vendasMap[$m]['valor_aprovado'] ?? 0),
                'valor_reprovado' => $vendasReprMap[$m] ?? 0.0,
            ];
        }

        // ── 2) Evolução mensal de PEDIDOS (contagem) ──
        $sqlPedAprov = "
            SELECT MONTH(gp.data_aprovacao) as mes, COUNT(DISTINCT gp.numero_pedido, gp.serie_pedido) as qtd
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND gp.data_aprovacao IS NOT NULL
              AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
            GROUP BY MONTH(gp.data_aprovacao)";
        $stmtPA = $pdo->prepare($sqlPedAprov);
        $stmtPA->execute($paramsBase);
        $pedAprovMap = [];
        while ($r = $stmtPA->fetch(PDO::FETCH_ASSOC)) $pedAprovMap[(int)$r['mes']] = (int)$r['qtd'];

        $sqlPedRec = "
            SELECT MONTH(i.`data`) as mes, COUNT(DISTINCT i.numero, i.serie) as qtd
            FROM itens_orcamentos_pedidos i
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE i.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (i.status = 'Recusado' OR i.status = 'No carrinho')
            GROUP BY MONTH(i.`data`)";
        $stmtPR = $pdo->prepare($sqlPedRec);
        $stmtPR->execute($paramsBase);
        $pedRecMap = [];
        while ($r = $stmtPR->fetch(PDO::FETCH_ASSOC)) $pedRecMap[(int)$r['mes']] = (int)$r['qtd'];

        $pedidos_mensal = [];
        for ($m = 1; $m <= 12; $m++) {
            $pedidos_mensal[] = ['mes' => $m, 'qtd_aprovado' => $pedAprovMap[$m] ?? 0, 'qtd_reprovado' => $pedRecMap[$m] ?? 0];
        }

        // ── 3) Evolução mensal de COMPONENTES (quantidade) ──
        // Junção por ano: c.ano_referencia = gp.ano_referencia para usar componentes do mesmo ano (ex.: 2025).
        $sqlCA = "
            SELECT MONTH(gp.data_aprovacao) as mes, COALESCE(SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))),0) as qtd
            FROM pedidos_detalhado_componentes c
            INNER JOIN gestao_pedidos gp ON c.numero = gp.numero_pedido AND c.serie = gp.serie_pedido AND c.ano_referencia = gp.ano_referencia
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND gp.data_aprovacao IS NOT NULL
              AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
            GROUP BY MONTH(gp.data_aprovacao)";
        $stmtCA = $pdo->prepare($sqlCA);
        $stmtCA->execute($paramsBase);
        $compAprov = [];
        while ($r = $stmtCA->fetch(PDO::FETCH_ASSOC)) $compAprov[(int)$r['mes']] = (float)$r['qtd'];

        $sqlCR = "
            SELECT MONTH(i.`data`) as mes, COALESCE(SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))),0) as qtd
            FROM pedidos_detalhado_componentes c
            INNER JOIN itens_orcamentos_pedidos i ON c.numero = i.numero AND c.serie = i.serie AND c.ano_referencia = i.ano_referencia
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE i.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (i.status = 'Recusado' OR i.status = 'No carrinho')
            GROUP BY MONTH(i.`data`)";
        $stmtCR = $pdo->prepare($sqlCR);
        $stmtCR->execute($paramsBase);
        $compRec = [];
        while ($r = $stmtCR->fetch(PDO::FETCH_ASSOC)) $compRec[(int)$r['mes']] = (float)$r['qtd'];

        $componentes_mensal = [];
        for ($m = 1; $m <= 12; $m++) {
            $componentes_mensal[] = ['mes' => $m, 'qtd_aprovado' => $compAprov[$m] ?? 0.0, 'qtd_reprovado' => $compRec[$m] ?? 0.0];
        }

        // ── 4) KPIs do prescritor ──
        $sqlKpis = "
            SELECT
                COALESCE(SUM(CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN gp.preco_liquido ELSE 0 END),0) as total_aprovado,
                COALESCE(SUM(CASE WHEN gp.status_financeiro IN ('Recusado','Cancelado','Orçamento') OR gp.status_financeiro LIKE '%carrinho%' THEN gp.preco_liquido ELSE 0 END),0) as total_reprovado,
                COALESCE(SUM(gp.preco_liquido),0) as total_geral,
                COALESCE(SUM(gp.preco_custo),0) as total_custo,
                COALESCE(SUM(gp.desconto),0) as total_desconto,
                COUNT(DISTINCT CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN CONCAT(gp.numero_pedido,'-',gp.serie_pedido) END) as pedidos_aprovados,
                COUNT(DISTINCT CONCAT(gp.numero_pedido,'-',gp.serie_pedido)) as total_pedidos,
                COUNT(DISTINCT NULLIF(TRIM(gp.paciente),'')) as total_pacientes,
                COUNT(DISTINCT NULLIF(TRIM(gp.cliente),'')) as total_clientes,
                MAX(gp.data_aprovacao) as ultima_compra,
                COUNT(DISTINCT CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN MONTH(gp.data_aprovacao) END) as meses_ativos
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))";
        $stmtK = $pdo->prepare($sqlKpis);
        $stmtK->execute($paramsBase);
        $kr = $stmtK->fetch(PDO::FETCH_ASSOC);
        $totalAprov = (float)($kr['total_aprovado'] ?? 0);
        $totalCusto = (float)($kr['total_custo'] ?? 0);
        $pedAprov = (int)($kr['pedidos_aprovados'] ?? 0);
        $diasUltimaCompra = null;
        if ($kr['ultima_compra']) {
            $diasUltimaCompra = (int)((time() - strtotime($kr['ultima_compra'])) / 86400);
        }

        // Reprovados vêm de itens_orcamentos_pedidos (gestao_pedidos só tem aprovados)
        $sqlRepr = "
            SELECT COALESCE(SUM(i.valor_liquido),0) as total_reprovado,
                   COUNT(DISTINCT CONCAT(i.numero,'-',i.serie)) as pedidos_reprovados
            FROM itens_orcamentos_pedidos i
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE i.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (i.status = 'Recusado' OR i.status = 'No carrinho')";
        $stmtR = $pdo->prepare($sqlRepr);
        $stmtR->execute($paramsBase);
        $rr2 = $stmtR->fetch(PDO::FETCH_ASSOC);
        $totalRepr = (float)($rr2['total_reprovado'] ?? 0);
        $pedRepr = (int)($rr2['pedidos_reprovados'] ?? 0);
        $totalPed = $pedAprov + $pedRepr;
        $totalGeral = $totalAprov + $totalRepr;
        $taxaAprov = $totalGeral > 0 ? round($totalAprov / $totalGeral * 100, 1) : 0;
        $ticketMedio = $pedAprov > 0 ? round($totalAprov / $pedAprov, 2) : 0;
        $margemMedia = $totalAprov > 0 ? round(($totalAprov - $totalCusto) / $totalAprov * 100, 1) : 0;

        $kpis = [
            'total_aprovado' => $totalAprov,
            'total_reprovado' => $totalRepr,
            'taxa_aprovacao' => $taxaAprov,
            'ticket_medio' => $ticketMedio,
            'margem_media' => $margemMedia,
            'total_pacientes' => (int)($kr['total_pacientes'] ?? 0),
            'total_clientes' => (int)($kr['total_clientes'] ?? 0),
            'total_pedidos' => $totalPed,
            'pedidos_aprovados' => $pedAprov,
            'pedidos_reprovados' => $pedRepr,
            'dias_ultima_compra' => $diasUltimaCompra,
            'meses_ativos' => (int)($kr['meses_ativos'] ?? 0),
            'total_desconto' => (float)($kr['total_desconto'] ?? 0),
        ];

        // ── 5) Concentração na carteira ──
        $sqlCarteira = "
            SELECT COALESCE(SUM(CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN gp.preco_liquido ELSE 0 END),0) as total_carteira
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano";
        $pCart = $isMyPharm ? ['ano' => $ano] : ['ano' => $ano, 'nome' => $nome];
        $stmtC = $pdo->prepare($sqlCarteira);
        $stmtC->execute($pCart);
        $totalCarteira = (float)($stmtC->fetchColumn() ?: 0);
        $kpis['concentracao_carteira'] = $totalCarteira > 0 ? round($totalAprov / $totalCarteira * 100, 1) : 0;

        // ── 6) Comparativo com média da carteira ──
        $sqlComp = "
            SELECT pc2.nome as prescritor,
                COALESCE(SUM(CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN gp.preco_liquido ELSE 0 END),0) as tot_aprov,
                COALESCE(SUM(gp.preco_liquido),0) as tot_geral,
                COALESCE(SUM(gp.preco_custo),0) as tot_custo,
                COUNT(DISTINCT CASE WHEN gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%') THEN CONCAT(gp.numero_pedido,'-',gp.serie_pedido) END) as ped_aprov
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc2 ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc2.nome AND " .
            ($isMyPharm ? "(pc2.visitador IS NULL OR pc2.visitador = '' OR pc2.visitador = 'My Pharm' OR UPPER(pc2.visitador) = 'MY PHARM')" : "pc2.visitador = :nome") . "
            WHERE gp.ano_referencia = :ano
            GROUP BY pc2.nome";
        $stmtCo = $pdo->prepare($sqlComp);
        $stmtCo->execute($pCart);
        $prescTotais = $stmtCo->fetchAll(PDO::FETCH_ASSOC);
        $sumTickets = 0; $sumTaxas = 0; $sumMargens = 0; $cntPresc = 0;
        foreach ($prescTotais as $pt) {
            $tAprov = (float)$pt['tot_aprov'];
            $tGeral = (float)$pt['tot_geral'];
            $tCusto = (float)$pt['tot_custo'];
            $pApr = (int)$pt['ped_aprov'];
            if ($tGeral > 0) {
                $sumTaxas += $tAprov / $tGeral * 100;
                $sumMargens += $tAprov > 0 ? ($tAprov - $tCusto) / $tAprov * 100 : 0;
                $sumTickets += $pApr > 0 ? $tAprov / $pApr : 0;
                $cntPresc++;
            }
        }
        $comparativo = [
            'media_ticket_carteira' => $cntPresc > 0 ? round($sumTickets / $cntPresc, 2) : 0,
            'media_taxa_aprovacao_carteira' => $cntPresc > 0 ? round($sumTaxas / $cntPresc, 1) : 0,
            'media_margem_carteira' => $cntPresc > 0 ? round($sumMargens / $cntPresc, 1) : 0,
            'total_prescritores_carteira' => $cntPresc,
        ];

        // ── 7) Top formas farmacêuticas ──
        $sqlFormas = "
            SELECT gp.forma_farmaceutica as forma, COALESCE(SUM(gp.preco_liquido),0) as total, COUNT(*) as qtd
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
              AND gp.forma_farmaceutica IS NOT NULL AND gp.forma_farmaceutica != ''
            GROUP BY gp.forma_farmaceutica ORDER BY total DESC LIMIT 5";
        $stmtF = $pdo->prepare($sqlFormas);
        $stmtF->execute($paramsBase);
        $top_formas = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // ── 8) Distribuição por canal ──
        $sqlCanal = "
            SELECT gp.canal_atendimento as canal, COALESCE(SUM(gp.preco_liquido),0) as total, COUNT(*) as qtd
            FROM gestao_pedidos gp
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
              AND gp.canal_atendimento IS NOT NULL AND gp.canal_atendimento != ''
            GROUP BY gp.canal_atendimento ORDER BY total DESC LIMIT 5";
        $stmtCn = $pdo->prepare($sqlCanal);
        $stmtCn->execute($paramsBase);
        $dist_canal = $stmtCn->fetchAll(PDO::FETCH_ASSOC);

        // ── 9) Top 10 componentes aprovados (por pedido: ordenado por qtd de pedidos, depois qtd) ──
        // Filtra c.ano_referencia = :ano para que 2025 use componentes de 2025 (tabela pode ter vários anos).
        $sqlTopComp = "
            SELECT c.componente, COALESCE(SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))),0) as qtd, COUNT(DISTINCT gp.numero_pedido, gp.serie_pedido) as pedidos
            FROM pedidos_detalhado_componentes c
            INNER JOIN gestao_pedidos gp ON c.numero = gp.numero_pedido AND c.serie = gp.serie_pedido AND c.ano_referencia = gp.ano_referencia
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
              AND c.componente IS NOT NULL AND c.componente != ''
            GROUP BY c.componente ORDER BY pedidos DESC, qtd DESC LIMIT 10";
        $stmtTC = $pdo->prepare($sqlTopComp);
        $stmtTC->execute($paramsBase);
        $top_componentes = $stmtTC->fetchAll(PDO::FETCH_ASSOC);

        // ── 9b) Top 10 componentes reprovados (por pedido: itens_orcamentos_pedidos Recusado/No carrinho) ──
        $sqlTopCompRepr = "
            SELECT c.componente, COALESCE(SUM(CAST(c.quantidade_componente AS DECIMAL(20,6))),0) as qtd, COUNT(DISTINCT i.numero, i.serie) as pedidos
            FROM pedidos_detalhado_componentes c
            INNER JOIN itens_orcamentos_pedidos i ON c.numero = i.numero AND c.serie = i.serie AND c.ano_referencia = i.ano_referencia
            INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm') = pc.nome AND $visWhere
            WHERE i.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
              AND (i.status = 'Recusado' OR i.status = 'No carrinho')
              AND c.componente IS NOT NULL AND c.componente != ''
            GROUP BY c.componente ORDER BY pedidos DESC, qtd DESC LIMIT 10";
        $stmtTCR = $pdo->prepare($sqlTopCompRepr);
        $stmtTCR->execute($paramsBase);
        $top_componentes_reprovados = $stmtTCR->fetchAll(PDO::FETCH_ASSOC);

        // ── 10) Dados de visitas ──
        $sqlVis = "
            SELECT COUNT(*) as total_visitas, MAX(hv.data_visita) as ultima_visita
            FROM historico_visitas hv
            WHERE LOWER(TRIM(hv.prescritor)) = LOWER(:prescritor)
              AND hv.ano_referencia = :ano
              AND hv.status_visita = 'Realizada'";
        $stmtVis = $pdo->prepare($sqlVis);
        $stmtVis->execute(['prescritor' => $prescritor, 'ano' => $ano]);
        $vr = $stmtVis->fetch(PDO::FETCH_ASSOC);
        $totalVisitas = (int)($vr['total_visitas'] ?? 0);
        $ultimaVisita = $vr['ultima_visita'] ?? null;
        $diasSemVisita = $ultimaVisita ? (int)((time() - strtotime($ultimaVisita)) / 86400) : null;
        $visitas = [
            'total_visitas' => $totalVisitas,
            'ultima_visita' => $ultimaVisita,
            'dias_sem_visita' => $diasSemVisita,
        ];

        // ── 11) Recorrência de pacientes ──
        $sqlRec = "
            SELECT COUNT(*) as total, SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END) as recorrentes
            FROM (
                SELECT TRIM(gp.paciente) as pac, COUNT(DISTINCT gp.numero_pedido, gp.serie_pedido) as cnt
                FROM gestao_pedidos gp
                INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm') = pc.nome AND $visWhere
                WHERE gp.ano_referencia = :ano AND LOWER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor),''),'My Pharm'))) = LOWER(TRIM(:prescritor))
                  AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado','Cancelado','Orçamento') AND gp.status_financeiro NOT LIKE '%carrinho%'))
                  AND gp.paciente IS NOT NULL AND TRIM(gp.paciente) != ''
                GROUP BY TRIM(gp.paciente)
            ) sub";
        $stmtRec = $pdo->prepare($sqlRec);
        $stmtRec->execute($paramsBase);
        $rr = $stmtRec->fetch(PDO::FETCH_ASSOC);
        $recorrencia = [
            'total_pacientes' => (int)($rr['total'] ?? 0),
            'pacientes_recorrentes' => (int)($rr['recorrentes'] ?? 0),
            'taxa_recorrencia' => (int)($rr['total'] ?? 0) > 0 ? round((int)$rr['recorrentes'] / (int)$rr['total'] * 100, 1) : 0,
        ];

        // ── 12) Tendência (3 meses recentes vs 3 anteriores) ──
        $mesAtual = (int)date('n');
        $mesesRecentes = [];
        $mesesAnteriores = [];
        for ($i = 0; $i < 3; $i++) {
            $mr = $mesAtual - $i;
            $ma = $mesAtual - 3 - $i;
            if ($mr >= 1) $mesesRecentes[] = $mr;
            if ($ma >= 1) $mesesAnteriores[] = $ma;
        }
        $valRecente = 0;
        $valAnterior = 0;
        foreach ($vendas_mensal as $vm) {
            if (in_array($vm['mes'], $mesesRecentes)) $valRecente += $vm['valor_aprovado'];
            if (in_array($vm['mes'], $mesesAnteriores)) $valAnterior += $vm['valor_aprovado'];
        }
        $tendencia = [
            'valor_recente' => $valRecente,
            'valor_anterior' => $valAnterior,
            'variacao_pct' => $valAnterior > 0 ? round(($valRecente - $valAnterior) / $valAnterior * 100, 1) : ($valRecente > 0 ? 100 : 0),
        ];

        echo json_encode([
            'vendas_mensal' => $vendas_mensal,
            'pedidos_mensal' => $pedidos_mensal,
            'componentes_mensal' => $componentes_mensal,
            'kpis' => $kpis,
            'comparativo' => $comparativo,
            'top_formas' => $top_formas,
            'distribuicao_canal' => $dist_canal,
            'top_componentes' => $top_componentes,
            'top_componentes_reprovados' => $top_componentes_reprovados,
            'visitas' => $visitas,
            'recorrencia' => $recorrencia,
            'tendencia' => $tendencia,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar análise: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Detalhe de um pedido (numero + serie): dados de gestao_pedidos e itens_orcamentos_pedidos.
 * Parâmetros: numero, serie, ano (opcional).
 * Retorna: resumo (cabeçalho), itens_gestao (linhas da gestão), itens_orcamento (linhas do orçamento).
 */
function dashboardGetPedidoDetalhe(PDO $pdo): void
{
    $numero = (int)($_GET['numero'] ?? 0);
    $serie = (int)($_GET['serie'] ?? 0);
    $ano = isset($_GET['ano']) && $_GET['ano'] !== '' ? (int)$_GET['ano'] : null;
    if ($numero <= 0 || $serie < 0) {
        echo json_encode(['error' => 'Número e série são obrigatórios'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $params = ['numero' => $numero, 'serie' => $serie];
    $whereGp = "numero_pedido = :numero AND serie_pedido = :serie";
    $whereIo = "numero = :numero AND serie = :serie";
    if ($ano !== null) {
        $whereGp .= " AND ano_referencia = :ano";
        $whereIo .= " AND ano_referencia = :ano";
        $params['ano'] = $ano;
    }

    // 1) Gestão de Pedidos: todas as linhas (produto, valores, atendente, aprovador, etc.)
    $stmtGp = $pdo->prepare("
        SELECT id, data_aprovacao, data_orcamento, canal_atendimento, numero_pedido, serie_pedido,
               forma_farmaceutica, produto, quantidade,
               preco_bruto, valor_subsidio, preco_custo, desconto, acrescimo, preco_liquido,
               cliente, paciente, prescritor, atendente, venda_pdv, cortesia, aprovador, orcamentista,
               status_financeiro, origem_acrescimo_desconto, convenio, ano_referencia
        FROM gestao_pedidos
        WHERE $whereGp
        ORDER BY id ASC
    ");
    $stmtGp->execute($params);
    $itens_gestao = $stmtGp->fetchAll(PDO::FETCH_ASSOC);

    // 2) Itens de Orçamentos e Pedidos: todas as linhas (descrição, usuários, valores)
    $stmtIo = $pdo->prepare("
        SELECT id, filial, numero, serie, data, canal, forma_farmaceutica, descricao,
               quantidade, unidade, valor_bruto, valor_liquido, preco_custo, fator,
               status, usuario_inclusao, usuario_aprovador, paciente, prescritor, status_financeiro, ano_referencia
        FROM itens_orcamentos_pedidos
        WHERE $whereIo
        ORDER BY id ASC
    ");
    $stmtIo->execute($params);
    $itens_orcamento = $stmtIo->fetchAll(PDO::FETCH_ASSOC);

    // Resumo: primeiro registro da gestão (ou do orçamento se gestão vazia)
    $resumo = null;
    if (!empty($itens_gestao)) {
        $r = $itens_gestao[0];
        $total = 0;
        foreach ($itens_gestao as $i) {
            $total += (float)($i['preco_liquido'] ?? 0);
        }
        $resumo = [
            'numero_pedido' => $r['numero_pedido'],
            'serie_pedido' => $r['serie_pedido'],
            'data_aprovacao' => $r['data_aprovacao'],
            'data_orcamento' => $r['data_orcamento'],
            'canal_atendimento' => $r['canal_atendimento'],
            'cliente' => $r['cliente'],
            'paciente' => $r['paciente'],
            'prescritor' => $r['prescritor'],
            'atendente' => $r['atendente'],
            'aprovador' => $r['aprovador'],
            'orcamentista' => $r['orcamentista'],
            'status_financeiro' => $r['status_financeiro'],
            'convenio' => $r['convenio'],
            'venda_pdv' => $r['venda_pdv'],
            'cortesia' => $r['cortesia'],
            'total_gestao' => round($total, 2),
            'total_autorizado' => (isset($r['status_financeiro']) && stripos($r['status_financeiro'], 'Aprovad') !== false) ? round($total, 2) : null,
            'qtd_itens_gestao' => count($itens_gestao),
        ];
    }
    if (!$resumo && !empty($itens_orcamento)) {
        $r = $itens_orcamento[0];
        $resumo = [
            'numero_pedido' => $r['numero'],
            'serie_pedido' => $r['serie'],
            'data_aprovacao' => null,
            'data_orcamento' => $r['data'],
            'canal_atendimento' => $r['canal'],
            'cliente' => $r['paciente'],
            'paciente' => $r['paciente'],
            'prescritor' => $r['prescritor'],
            'atendente' => null,
            'aprovador' => $r['usuario_aprovador'],
            'orcamentista' => $r['usuario_inclusao'],
            'status_financeiro' => $r['status_financeiro'],
            'convenio' => null,
            'venda_pdv' => null,
            'cortesia' => null,
            'total_gestao' => null,
            'total_autorizado' => null,
            'qtd_itens_gestao' => 0,
        ];
    }
    if (!$resumo) {
        echo json_encode(['error' => 'Pedido não encontrado', 'pedido' => null, 'resumo' => null, 'itens_gestao' => [], 'itens_orcamento' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'pedido' => $resumo,
        'resumo' => $resumo,
        'itens_gestao' => $itens_gestao,
        'itens_orcamento' => $itens_orcamento,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Componentes de um pedido (numero + serie).
 * Fonte: pedidos_detalhado_componentes — cada linha é um componente; numero+serie se repete, o que não repete é a coluna componente.
 * Parâmetros: numero, serie, ano (opcional).
 */
function dashboardGetPedidoComponentes(PDO $pdo): void
{
    $numero = (int)($_GET['numero'] ?? 0);
    $serie = (int)($_GET['serie'] ?? 0);
    $ano = isset($_GET['ano']) && $_GET['ano'] !== '' ? (int)$_GET['ano'] : null;
    if ($numero <= 0 || $serie < 0) {
        echo json_encode(['error' => 'Número e série são obrigatórios', 'componentes' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $componentes = [];
    $params = ['numero' => $numero, 'serie' => $serie];

    try {
        $stmt = $pdo->prepare("
            SELECT componente, quantidade_componente as qtd_calculada, unidade_componente as unidade
            FROM pedidos_detalhado_componentes
            WHERE numero = :numero AND serie = :serie
            ORDER BY id ASC
        ");
        $stmt->execute($params);
        $componentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Se não achou e série não é 0, tenta com serie 0 (relatório às vezes grava só série 0)
        if (empty($componentes) && $serie !== 0) {
            $stmt->execute(['numero' => $numero, 'serie' => 0]);
            $componentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $componentes = [];
    }
    echo json_encode(['componentes' => $componentes], JSON_UNESCAPED_UNICODE);
}

/**
 * Visitas com GPS para o Roteiro de Visitas (período: data_de até data_ate).
 * Retorna pontos ordenados por data_visita e horário para desenhar rota e direção.
 */
function getRelatorioRotaCompleto(PDO $pdo): void
{
    $dataDe = trim($_GET['data_de'] ?? '');
    $dataAte = trim($_GET['data_ate'] ?? '');
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    $isPerfilVisitador = ($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false);
    // Mesmo critério de visitador_visitas_periodo: visitador logado sempre pela sessão (evita mapa com dados e tabela vazia quando localStorage/GET difere do user_nome).
    $nome = $isPerfilVisitador ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
    if (!$nome) {
        echo json_encode(['error' => 'Visitador não informado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!$dataDe || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) $dataDe = date('Y-m-d');
    if (!$dataAte || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) $dataAte = $dataDe;
    if ($dataAte < $dataDe) $dataAte = $dataDe;

    $haversineKm = function ($lat1, $lon1, $lat2, $lon2) {
        $lat1 = (float)$lat1; $lon1 = (float)$lon1; $lat2 = (float)$lat2; $lon2 = (float)$lon2;
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    };

    // 1) Rotas do período
    $pdo->exec("CREATE TABLE IF NOT EXISTS rotas_diarias (
        id INT AUTO_INCREMENT PRIMARY KEY, visitador_nome VARCHAR(255) NOT NULL,
        data_inicio DATETIME NOT NULL, data_fim DATETIME NULL, pausado_em DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'em_andamento', criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_visitador_status (visitador_nome, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rotas_pontos (
        id INT AUTO_INCREMENT PRIMARY KEY, rota_id INT NOT NULL,
        lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_rota (rota_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->prepare("
        SELECT id, visitador_nome, data_inicio, data_fim, pausado_em, status
        FROM rotas_diarias
        WHERE visitador_nome = :v AND DATE(data_inicio) >= :de AND DATE(data_inicio) <= :ate
        ORDER BY data_inicio DESC
        LIMIT 100
    ");
    $stmt->execute(['v' => $nome, 'de' => $dataDe, 'ate' => $dataAte]);
    $rotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];
    $totalKm = 0;
    $totalTempoRota = 0;
    $totalTempoVisita = 0;

    foreach ($rotas as $rota) {
        $rid = (int)$rota['id'];
        $dataInicio = $rota['data_inicio'];
        $dataFim = $rota['data_fim'];

        // Tempo de percurso
        $tempoRotaMin = 0;
        if ($dataInicio && $dataFim) {
            $tempoRotaMin = (int)((strtotime($dataFim) - strtotime($dataInicio)) / 60);
        } elseif ($dataInicio && $rota['status'] === 'em_andamento') {
            $tempoRotaMin = (int)((time() - strtotime($dataInicio)) / 60);
        }
        $totalTempoRota += $tempoRotaMin;

        // Pontos GPS: só contabilizar até o fechamento da rota (após finalizar, não conta mais pontos/km)
        if ($dataFim !== null) {
            $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid AND criado_em <= :data_fim ORDER BY criado_em ASC");
            $stmtP->execute(['rid' => $rid, 'data_fim' => $dataFim]);
        } else {
            $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
            $stmtP->execute(['rid' => $rid]);
        }
        $pontos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        $qtd = count($pontos);
        $primeiroPonto = $qtd > 0 ? $pontos[0] : null;
        $ultimoPonto = $qtd > 0 ? $pontos[$qtd - 1] : null;

        // Visitas desta rota (no mesmo dia do início); se rota foi finalizada, só contabilizar visitas até data_fim
        $diaRota = substr($dataInicio, 0, 10);
        $stmtV = $pdo->prepare("
            SELECT hv.prescritor, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00')) as fim_visita,
                   hv.status_visita, hv.local_visita, hv.resumo_visita, hv.amostra, hv.brinde, hv.artigo,
                   hv.data_visita, DATE_FORMAT(hv.horario, '%H:%i') as hora_fim,
                   TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_min,
                   vg.lat as geo_lat, vg.lng as geo_lng
            FROM historico_visitas hv
            LEFT JOIN visitas_geolocalizacao vg ON vg.historico_id = hv.id
            WHERE TRIM(hv.visitador) = TRIM(:v) AND hv.data_visita = :dia
              AND (hv.status_visita IS NULL OR UPPER(TRIM(hv.status_visita)) <> 'RECUSADA')
            ORDER BY hv.inicio_visita ASC
        ");
        $stmtV->execute(['v' => $nome, 'dia' => $diaRota]);
        $visitas = $stmtV->fetchAll(PDO::FETCH_ASSOC);
        if ($dataFim !== null) {
            $tsFim = strtotime($dataFim);
            $visitas = array_values(array_filter($visitas, function ($v) use ($tsFim) {
                $ts = $v['inicio_visita'] ? strtotime($v['inicio_visita']) : strtotime($v['fim_visita'] ?? '');
                return $ts <= $tsFim;
            }));
        }

        $tempoVisitaMin = 0;
        $maxGeocodeVisitas = 15; // Limite de geocoding por rota para não travar o relatório
        $visitaIdx = 0;
        foreach ($visitas as &$vis) {
            $d = (int)($vis['duracao_min'] ?? 0);
            if ($d > 0 && $d < 720) $tempoVisitaMin += $d;
            if (!empty($vis['geo_lat']) && !empty($vis['geo_lng'])) {
                $vis['geo_endereco'] = ($visitaIdx < $maxGeocodeVisitas)
                    ? reverseGeocode($pdo, (float)$vis['geo_lat'], (float)$vis['geo_lng'])
                    : (number_format((float)$vis['geo_lat'], 5) . ', ' . number_format((float)$vis['geo_lng'], 5));
                $visitaIdx++;
            }
        }
        unset($vis);
        $totalTempoVisita += $tempoVisitaMin;

        // Combinar pontos de rastreamento + pontos GPS das visitas em uma timeline unificada
        $allGps = [];
        foreach ($pontos as $p) {
            $allGps[] = ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'ts' => strtotime($p['criado_em'])];
        }
        foreach ($visitas as $vis) {
            if (!empty($vis['geo_lat']) && !empty($vis['geo_lng'])) {
                $ts = $vis['inicio_visita'] ? strtotime($vis['inicio_visita']) : strtotime($vis['fim_visita']);
                $allGps[] = ['lat' => (float)$vis['geo_lat'], 'lng' => (float)$vis['geo_lng'], 'ts' => $ts];
            }
        }
        usort($allGps, function ($a, $b) { return $a['ts'] - $b['ts']; });

        $km = 0;
        $allCount = count($allGps);
        for ($i = 1; $i < $allCount; $i++) {
            $segKm = $haversineKm($allGps[$i - 1]['lat'], $allGps[$i - 1]['lng'], $allGps[$i]['lat'], $allGps[$i]['lng']);
            if ($segKm < 50) {
                $km += $segKm;
            }
        }
        $totalKm += $km;

        $resultado[] = [
            'rota_id' => $rid,
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
            'status' => $rota['status'],
            'pausado_em' => $rota['pausado_em'],
            'km' => round($km, 2),
            'qtd_pontos' => $qtd,
            'tempo_rota_min' => $tempoRotaMin,
            'tempo_visita_min' => $tempoVisitaMin,
            'local_inicio_lat' => $primeiroPonto ? (float)$primeiroPonto['lat'] : null,
            'local_inicio_lng' => $primeiroPonto ? (float)$primeiroPonto['lng'] : null,
            'local_inicio_hora' => $primeiroPonto ? $primeiroPonto['criado_em'] : null,
            'local_inicio_endereco' => $primeiroPonto ? reverseGeocode($pdo, (float)$primeiroPonto['lat'], (float)$primeiroPonto['lng']) : null,
            'local_fim_lat' => $ultimoPonto ? (float)$ultimoPonto['lat'] : null,
            'local_fim_lng' => $ultimoPonto ? (float)$ultimoPonto['lng'] : null,
            'local_fim_hora' => $ultimoPonto ? $ultimoPonto['criado_em'] : null,
            'local_fim_endereco' => $ultimoPonto ? reverseGeocode($pdo, (float)$ultimoPonto['lat'], (float)$ultimoPonto['lng']) : null,
            'visitas' => $visitas,
            'pontos' => array_map(function ($p) { return ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng']]; }, $pontos),
        ];
    }

    echo json_encode([
        'success' => true,
        'visitador' => $nome,
        'data_de' => $dataDe,
        'data_ate' => $dataAte,
        'total_rotas' => count($rotas),
        'total_km' => round($totalKm, 2),
        'total_tempo_rota_min' => $totalTempoRota,
        'total_tempo_visita_min' => $totalTempoVisita,
        'total_visitas' => array_sum(array_map(function ($r) { return count($r['visitas']); }, $resultado)),
        'rotas' => $resultado,
    ], JSON_UNESCAPED_UNICODE);
}

function getVisitasMapaPeriodo(PDO $pdo): void
{
    $dataDe = trim($_GET['data_de'] ?? '');
    $dataAte = trim($_GET['data_ate'] ?? '');
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    $isPerfilVisitador = ($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false);
    $nome = $isPerfilVisitador ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
    if (!$nome) {
        echo json_encode(['error' => 'Visitador não informado.', 'visitas' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!$dataDe || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) {
        $dataDe = date('Y-m-d');
    }
    if (!$dataAte || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) {
        $dataAte = $dataDe;
    }
    if ($dataAte < $dataDe) {
        $dataAte = $dataDe;
    }
    $isMyPharm = (strcasecmp($nome, 'My Pharm') === 0 || $nome === '');
    $visitadorWhereGeo = $isMyPharm
        ? "(vg.visitador IS NULL OR TRIM(vg.visitador) = '' OR LOWER(TRIM(vg.visitador)) = 'my pharm')"
        : "TRIM(COALESCE(vg.visitador, '')) = TRIM(:nome)";
    $params = ['data_de' => $dataDe, 'data_ate' => $dataAte];
    if (!$isMyPharm) {
        $params['nome'] = $nome;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT 
                vg.lat,
                vg.lng,
                vg.accuracy_m,
                vg.data_visita,
                DATE_FORMAT(vg.horario, '%H:%i') as horario,
                vg.prescritor,
                hv.status_visita,
                hv.local_visita,
                hv.resumo_visita,
                hv.id as historico_id
            FROM visitas_geolocalizacao vg
            LEFT JOIN historico_visitas hv ON hv.id = vg.historico_id
            WHERE vg.data_visita >= :data_de AND vg.data_visita <= :data_ate
              AND $visitadorWhereGeo
              AND (hv.status_visita IS NULL OR hv.status_visita = 'Realizada')
            ORDER BY vg.data_visita ASC, vg.horario ASC, vg.id ASC
            LIMIT 500
        ");
        $stmt->execute($params);
        $visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $visitas = [];
    }
    echo json_encode(['visitas' => $visitas, 'data_de' => $dataDe, 'data_ate' => $dataAte], JSON_UNESCAPED_UNICODE);
}

/**
 * Lista de visitas do historico_visitas (período data_de..data_ate).
 * - Perfil visitador: sempre filtra pela sessão (ignora GET visitador).
 * - Admin / demais perfis: exige GET visitador (mesmo uso que get_relatorio_rota_completo na pré-visualização).
 */
function visitadorVisitasPeriodo(PDO $pdo): void
{
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    $isVisitadorPerfil = ($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false);
    $nomeParam = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : '';

    if ($isVisitadorPerfil) {
        $nome = trim($_SESSION['user_nome'] ?? '');
        if ($nome === '') {
            echo json_encode(['success' => false, 'error' => 'Sessão inválida.', 'visitas' => []], JSON_UNESCAPED_UNICODE);
            return;
        }
    } else {
        $nome = $nomeParam;
        if ($nome === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Informe o visitador (parâmetro visitador).', 'visitas' => []], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
    if ($dataDe === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) {
        $dataDe = date('Y-m-d');
    }
    if ($dataAte === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) {
        $dataAte = $dataDe;
    }
    if ($dataAte < $dataDe) {
        $dataAte = $dataDe;
    }
    $isMyPharm = (strcasecmp($nome, 'My Pharm') === 0);
    try {
        $pdo->exec('ALTER TABLE historico_visitas ADD COLUMN motivo_recusa TEXT NULL');
    } catch (Throwable $e) { /* coluna já existe */ }
    if ($isMyPharm) {
        $whereHV = "WHERE hv.data_visita IS NOT NULL
            AND DATE(hv.data_visita) BETWEEN :data_de AND :data_ate
            AND (hv.visitador IS NULL OR TRIM(hv.visitador) = '' OR LOWER(TRIM(hv.visitador)) = 'my pharm')";
        $paramsHV = ['data_de' => $dataDe, 'data_ate' => $dataAte];
    } else {
        $whereHV = "WHERE hv.data_visita IS NOT NULL
            AND DATE(hv.data_visita) BETWEEN :data_de AND :data_ate
            AND TRIM(COALESCE(hv.visitador, '')) = TRIM(:visitador)";
        $paramsHV = ['data_de' => $dataDe, 'data_ate' => $dataAte, 'visitador' => $nome];
    }
    $stmt = $pdo->prepare("
        SELECT hv.id, hv.visitador, hv.prescritor, hv.data_visita, hv.horario, hv.inicio_visita,
            hv.status_visita, hv.local_visita, hv.resumo_visita, hv.reagendado_para, hv.motivo_recusa,
            TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_minutos
        FROM historico_visitas hv
        $whereHV
        ORDER BY hv.data_visita DESC, hv.horario DESC
        LIMIT 2000
    ");
    $stmt->execute($paramsHV);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'visitas' => $lista], JSON_UNESCAPED_UNICODE);
}

/**
 * Lista pedidos (aprovados + recusados/carrinho) para admin/gestão comercial no intervalo de datas,
 * no mesmo formato de list_pedidos_visitador (linhas por série/produto para agrupamento no front).
 *
 * @param  string|null  $visitadorCarteira  filtra pela carteira em prescritores_cadastro (TRIM); null ou '' = todos
 * @param  string|null  $prescritorLike     LIKE em prescritor (gestão / itens)
 * @param  string|null  $vendedorLike       LIKE em gp.atendente (apenas aprovados)
 * @return array{aprovados: list<array>, recusados_carrinho: list<array>, visitadores_opcao: list<string>}
 */
function dashboardListPedidosAdminPeriodo(
    PDO $pdo,
    string $ini,
    string $fim,
    ?string $visitadorCarteira,
    ?string $prescritorLike,
    ?string $vendedorLike,
    bool $expandRecusados = false
): array {
    $escLike = static function (string $literal): string {
        $s = str_replace('\\', '\\\\', $literal);

        return str_replace(['%', '_'], ['\\%', '\\_'], $s);
    };

    $visitadorCarteira = $visitadorCarteira !== null ? trim($visitadorCarteira) : '';
    $prescritorLike = $prescritorLike !== null ? trim($prescritorLike) : '';
    $vendedorLike = $vendedorLike !== null ? trim($vendedorLike) : '';

    $paramsA = ['ini' => $ini, 'fim' => $fim];
    $orcEncoded = "CONCAT('Or', CHAR(231), 'amento')";
    $whereA = "DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        AND gp.data_aprovacao IS NOT NULL
        AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', $orcEncoded) AND (gp.status_financeiro NOT LIKE '%carrinho%')))";

    $joinPc = 'LEFT JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(gp.prescritor),\'\'), \'My Pharm\') = pc.nome';
    if ($visitadorCarteira !== '') {
        $whereA .= ' AND TRIM(COALESCE(pc.visitador, \'\')) = TRIM(:viscar) ';
        $paramsA['viscar'] = $visitadorCarteira;
    }
    if ($prescritorLike !== '') {
        $whereA .= ' AND COALESCE(NULLIF(TRIM(gp.prescritor),\'\'), \'My Pharm\') LIKE :pfa ';
        $paramsA['pfa'] = '%' . $escLike($prescritorLike) . '%';
    }
    if ($vendedorLike !== '') {
        $whereA .= ' AND COALESCE(NULLIF(TRIM(gp.atendente),\'\'), \'(Sem vendedor)\') LIKE :vfa ';
        $paramsA['vfa'] = '%' . $escLike($vendedorLike) . '%';
    }

    $sqlA = "
        SELECT gp.numero_pedido, gp.serie_pedido, gp.data_aprovacao, gp.data_orcamento,
               COALESCE(NULLIF(TRIM(gp.prescritor),''), 'My Pharm') as prescritor,
               COALESCE(NULLIF(TRIM(gp.cliente),''), gp.paciente, '-') as cliente,
               gp.preco_liquido as valor
        FROM gestao_pedidos gp
        $joinPc
        WHERE $whereA
        ORDER BY gp.data_aprovacao DESC, gp.numero_pedido DESC, gp.serie_pedido DESC
        LIMIT 15000
    ";
    $aprovados = [];
    try {
        $st = $pdo->prepare($sqlA);
        $st->execute($paramsA);
        $aprovados = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $aprovados = [];
    }

    $subDataItem = "(SELECT MAX(x.data) FROM itens_orcamentos_pedidos x
        WHERE x.numero = i.numero AND x.ano_referencia = i.ano_referencia)";
    $joinGpad = '
        LEFT JOIN (
            SELECT numero_pedido, ano_referencia,
                MAX(data_orcamento) AS dt_orc,
                MAX(data_aprovacao) AS dt_apr
            FROM gestao_pedidos
            GROUP BY numero_pedido, ano_referencia
        ) gpad ON gpad.numero_pedido = i.numero AND gpad.ano_referencia = i.ano_referencia
    ';
    // CSV/import pode preencher `status` ou `status_financeiro`; aceitar variantes (trim/LIKE).
    $statusRecusadoSql = "(
        LOWER(TRIM(COALESCE(i.status,''))) IN ('recusado', 'no carrinho')
        OR TRIM(COALESCE(i.status,'')) IN ('Recusado', 'No carrinho')
        OR LOWER(TRIM(COALESCE(i.status_financeiro,''))) IN ('recusado', 'no carrinho')
        OR TRIM(COALESCE(i.status_financeiro,'')) IN ('Recusado', 'No carrinho')
        OR LOWER(TRIM(COALESCE(i.status,''))) LIKE 'recusad%'
        OR LOWER(TRIM(COALESCE(i.status_financeiro,''))) LIKE 'recusad%'
        OR LOWER(TRIM(COALESCE(i.status,''))) LIKE '%carrinho%'
        OR LOWER(TRIM(COALESCE(i.status_financeiro,''))) LIKE '%carrinho%'
    )";
    $dataRefExpr = "COALESCE(
        NULLIF(NULLIF(i.data, ''), '0000-00-00'),
        DATE($subDataItem),
        DATE(gpad.dt_orc),
        DATE(gpad.dt_apr)
    )";

    $paramsR = ['ini' => $ini, 'fim' => $fim];
    // Se expandRecusados, busca recusados/carrinho sem restrição de data rigorosa
    if ($expandRecusados) {
        $whereR = "i.ano_referencia >= YEAR(:ini) - 1
            AND $statusRecusadoSql";
    } else {
        // Alinhado ao vendedor_pedidos_lista: período por data do item OU por fallback (gestão/subquery).
        $paramsR['ini_item'] = $ini;
        $paramsR['fim_item'] = $fim;
        $whereR = "i.ano_referencia BETWEEN YEAR(:ini) AND YEAR(:fim)
            AND $statusRecusadoSql
            AND (
                DATE($dataRefExpr) BETWEEN :ini AND :fim
                OR (
                    NULLIF(NULLIF(i.data, ''), '0000-00-00') IS NOT NULL
                    AND DATE(i.data) BETWEEN :ini_item AND :fim_item
                )
            ) ";
    }
    $joinVisit = '';
    if ($visitadorCarteira !== '') {
        $joinVisit = ' INNER JOIN prescritores_cadastro pc2
            ON UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor),\'\'), \'My Pharm\'))) = UPPER(TRIM(pc2.nome))
            AND TRIM(COALESCE(pc2.visitador, \'\')) = TRIM(:viscar2) ';
        $paramsR['viscar2'] = $visitadorCarteira;
    }
    if ($prescritorLike !== '') {
        $whereR .= ' AND COALESCE(NULLIF(TRIM(i.prescritor),\'\'), \'My Pharm\') LIKE :pfr ';
        $paramsR['pfr'] = '%' . $escLike($prescritorLike) . '%';
    }
    if ($vendedorLike !== '') {
        $paramsR['vfa_rec'] = '%' . $escLike($vendedorLike) . '%';
        $whereR .= " AND (
            COALESCE(NULLIF(TRIM(i.usuario_inclusao),''), '(Sem vendedor)') LIKE :vfa_rec
            OR EXISTS (
                SELECT 1 FROM gestao_pedidos gpv
                WHERE gpv.numero_pedido = i.numero
                  AND gpv.serie_pedido = i.serie
                  AND gpv.ano_referencia = i.ano_referencia
                  AND COALESCE(NULLIF(TRIM(gpv.atendente),''), '(Sem vendedor)') LIKE :vfa_rec
            )
        ) ";
    }

    $sqlR = "
        SELECT i.numero as numero_pedido, i.serie as serie_pedido,
               MAX(i.ano_referencia) AS ano_referencia,
               NULL as data_aprovacao,
               COALESCE(DATE($subDataItem), DATE(MAX(gpad.dt_orc)), DATE(MAX(gpad.dt_apr))) as data_orcamento,
               COALESCE(NULLIF(TRIM(MAX(i.prescritor)),''), 'My Pharm') as prescritor,
               COALESCE(MAX(NULLIF(TRIM(i.paciente),'')), '-') as cliente,
               SUM(i.valor_liquido) as valor,
               MAX(CASE
                   WHEN LOWER(TRIM(COALESCE(i.status,''))) LIKE 'recusad%'
                        OR LOWER(TRIM(COALESCE(i.status_financeiro,''))) LIKE 'recusad%' THEN 'Recusado'
                   WHEN LOWER(TRIM(COALESCE(i.status,''))) LIKE '%carrinho%'
                        OR LOWER(TRIM(COALESCE(i.status_financeiro,''))) LIKE '%carrinho%' THEN 'No carrinho'
                   ELSE 'No carrinho'
               END) as status_financeiro
        FROM itens_orcamentos_pedidos i
        $joinGpad
        $joinVisit
        WHERE $whereR
        GROUP BY i.numero, i.serie, COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm')
        ORDER BY i.numero DESC, i.serie DESC
        LIMIT 8000
    ";
    $recRows = [];
    try {
        $stR = $pdo->prepare($sqlR);
        $stR->execute($paramsR);
        $recRows = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $recRows = [];
    }

    $recusadosMap = [];
    foreach ($recRows as $row) {
        $k = (int)($row['numero_pedido'] ?? 0) . '_' . (int)($row['serie_pedido'] ?? 0);
        $dNew = trim((string)($row['data_orcamento'] ?? ''));
        $okNew = ($dNew !== '' && strpos($dNew, '0000-00-00') !== 0);
        if (!isset($recusadosMap[$k])) {
            $recusadosMap[$k] = $row;
        } else {
            $dOld = trim((string)($recusadosMap[$k]['data_orcamento'] ?? ''));
            $okOld = ($dOld !== '' && strpos($dOld, '0000-00-00') !== 0);
            if (!$okOld && $okNew) {
                $recusadosMap[$k] = $row;
            }
        }
    }

    // Fonte adicional: reprovados frequentemente só em gestao_pedidos (CSV gestão), não em itens_orcamentos_pedidos.
    $paramsRG = $paramsA;
    $whereRG = "(gp.status_financeiro IN ('Recusado', 'Cancelado', $orcEncoded) OR gp.status_financeiro LIKE '%carrinho%')";
    if ($expandRecusados) {
        $whereRG .= ' AND gp.ano_referencia >= YEAR(:ini) - 1';
    } else {
        $whereRG .= ' AND DATE(COALESCE(gp.data_aprovacao, gp.data_orcamento)) BETWEEN :ini AND :fim';
    }
    if ($visitadorCarteira !== '') {
        $whereRG .= ' AND TRIM(COALESCE(pc.visitador, \'\')) = TRIM(:viscar) ';
    }
    if ($prescritorLike !== '') {
        $whereRG .= ' AND COALESCE(NULLIF(TRIM(gp.prescritor),\'\'), \'My Pharm\') LIKE :pfa ';
    }
    if ($vendedorLike !== '') {
        $whereRG .= ' AND COALESCE(NULLIF(TRIM(gp.atendente),\'\'), \'(Sem vendedor)\') LIKE :vfa ';
    }
    $sqlRG = "
        SELECT gp.numero_pedido, gp.serie_pedido,
            MAX(gp.ano_referencia) AS ano_referencia,
            NULL AS data_aprovacao,
            DATE(MAX(COALESCE(gp.data_orcamento, gp.data_aprovacao))) AS data_orcamento,
            COALESCE(NULLIF(TRIM(MAX(gp.prescritor)),''), 'My Pharm') AS prescritor,
            COALESCE(MAX(NULLIF(TRIM(gp.cliente),'')), MAX(gp.paciente), '-') AS cliente,
            SUM(gp.preco_liquido) AS valor,
            MAX(CASE
                WHEN LOWER(TRIM(COALESCE(gp.status_financeiro,''))) LIKE '%carrinho%' THEN 'No carrinho'
                ELSE 'Recusado'
            END) AS status_financeiro
        FROM gestao_pedidos gp
        $joinPc
        WHERE $whereRG
        GROUP BY gp.numero_pedido, gp.serie_pedido, COALESCE(NULLIF(TRIM(gp.prescritor),''), 'My Pharm')
        ORDER BY gp.numero_pedido DESC, gp.serie_pedido DESC
        LIMIT 15000
    ";
    try {
        $stG = $pdo->prepare($sqlRG);
        $stG->execute($paramsRG);
        foreach ($stG->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $k = (int) ($row['numero_pedido'] ?? 0) . '_' . (int) ($row['serie_pedido'] ?? 0);
            if (isset($recusadosMap[$k])) {
                continue;
            }
            $recusadosMap[$k] = $row;
        }
    } catch (Throwable $e) {
        /* mantém só itens */
    }

    $recusados_carrinho = array_values($recusadosMap);

    // Mesma ideia do list_pedidos_visitador: preencher data_orcamento a partir de orçamentos/itens/gestão
    // (evita NULL no front — o filtro por data da aba eliminava todos os recusados/carrinho).
    $yIni = (int) substr($ini, 0, 4);
    $yFim = (int) substr($fim, 0, 4);
    if ($yIni < 2000) {
        $yIni = (int) date('Y');
    }
    if ($yFim < 2000) {
        $yFim = $yIni;
    }
    $yLo = min($yIni, $yFim);
    $yHi = max($yIni, $yFim);
    if ($expandRecusados) {
        $yLo = min($yLo, $yLo - 1);
    }
    try {
        $mapDataItem = [];
        $stIt = $pdo->prepare(
            'SELECT numero, serie, ano_referencia, MAX(`data`) AS dt
            FROM itens_orcamentos_pedidos
            WHERE ano_referencia BETWEEN :ylo AND :yhi
            GROUP BY numero, serie, ano_referencia'
        );
        $stIt->execute(['ylo' => $yLo, 'yhi' => $yHi]);
        while ($z = $stIt->fetch(PDO::FETCH_ASSOC)) {
            $dt = $z['dt'] ?? null;
            if ($dt === null || $dt === '' || strpos((string) $dt, '0000-00-00') === 0) {
                continue;
            }
            $kk3 = (int) ($z['numero'] ?? 0) . '_' . (int) ($z['serie'] ?? 0) . '_' . (int) ($z['ano_referencia'] ?? 0);
            $mapDataItem[$kk3] = substr((string) $dt, 0, 10);
        }
        $mapOrcOp = [];
        try {
            $stOp = $pdo->query(
                'SELECT numero, serie, MAX(`data`) AS dt FROM orcamentos_pedidos GROUP BY numero, serie'
            );
            if ($stOp) {
                while ($z = $stOp->fetch(PDO::FETCH_ASSOC)) {
                    $dt = $z['dt'] ?? null;
                    if ($dt === null || $dt === '' || strpos((string) $dt, '0000-00-00') === 0) {
                        continue;
                    }
                    $mapOrcOp[(int) ($z['numero'] ?? 0) . '_' . (int) ($z['serie'] ?? 0)] = substr((string) $dt, 0, 10);
                }
            }
        } catch (Throwable $e) {
            /* tabela pode não existir */
        }
        $mapDataGp = [];
        $stGp = $pdo->prepare(
            'SELECT numero_pedido, serie_pedido, ano_referencia,
                MAX(data_orcamento) AS dorc, MAX(data_aprovacao) AS dapr
            FROM gestao_pedidos
            WHERE ano_referencia BETWEEN :ylo AND :yhi
            GROUP BY numero_pedido, serie_pedido, ano_referencia'
        );
        $stGp->execute(['ylo' => $yLo, 'yhi' => $yHi]);
        while ($z = $stGp->fetch(PDO::FETCH_ASSOC)) {
            $tOrc = 0;
            $tApr = 0;
            foreach (['dorc' => 0, 'dapr' => 1] as $col => $_idx) {
                $v = $z[$col] ?? null;
                if ($v && strpos((string) $v, '0000-00-00') !== 0) {
                    $ts = @strtotime((string) $v);
                    if ($ts > 0) {
                        if ($col === 'dorc') {
                            $tOrc = max($tOrc, $ts);
                        } else {
                            $tApr = max($tApr, $ts);
                        }
                    }
                }
            }
            $kk3 = (int) ($z['numero_pedido'] ?? 0) . '_' . (int) ($z['serie_pedido'] ?? 0) . '_' . (int) ($z['ano_referencia'] ?? 0);
            if ($tOrc > 0) {
                $mapDataGp[$kk3] = date('Y-m-d', $tOrc);
            } elseif ($tApr > 0) {
                $mapDataGp[$kk3] = date('Y-m-d', $tApr);
            }
        }
        foreach ($recusados_carrinho as &$rw) {
            $ano = (int) ($rw['ano_referencia'] ?? 0);
            if ($ano < 2000) {
                $ano = $yHi;
            }
            $kk = (int) ($rw['numero_pedido'] ?? 0) . '_' . (int) ($rw['serie_pedido'] ?? 0);
            $kk3 = $kk . '_' . $ano;
            if (isset($mapOrcOp[$kk])) {
                $rw['data_orcamento'] = $mapOrcOp[$kk];
            } else {
                $dorc = isset($rw['data_orcamento']) ? trim((string) $rw['data_orcamento']) : '';
                if ($dorc === '' || $dorc === '0000-00-00' || strpos($dorc, '0000-00-00') === 0) {
                    if (isset($mapDataItem[$kk3])) {
                        $rw['data_orcamento'] = $mapDataItem[$kk3];
                    } elseif (isset($mapDataGp[$kk3])) {
                        $rw['data_orcamento'] = $mapDataGp[$kk3];
                    }
                }
            }
        }
        unset($rw);
    } catch (Throwable $e) {
        /* mantém datas vindas da query principal */
    }

    $normalizarData = static function (array &$row): void {
        foreach (['data_aprovacao', 'data_orcamento'] as $campo) {
            if (!empty($row[$campo]) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$row[$campo])) {
                $row[$campo] = substr((string)$row[$campo], 0, 19);
            }
        }
    };
    foreach ($aprovados as &$r) {
        $normalizarData($r);
    }
    unset($r);
    foreach ($recusados_carrinho as &$r) {
        $normalizarData($r);
        $st = strtolower(trim((string)($r['status_financeiro'] ?? '')));
        $r['tipo_listagem'] = (strpos($st, 'recusad') !== false) ? 'Recusado' : 'No carrinho';
    }
    unset($r);

    $visitadores_opcao = [];
    try {
        $stV = $pdo->query("
            SELECT DISTINCT TRIM(pc.visitador) AS nome
            FROM prescritores_cadastro pc
            WHERE TRIM(COALESCE(pc.visitador, '')) <> ''
            ORDER BY nome ASC
            LIMIT 400
        ");
        if ($stV) {
            while ($z = $stV->fetch(PDO::FETCH_ASSOC)) {
                $n = trim((string)($z['nome'] ?? ''));
                if ($n !== '') {
                    $visitadores_opcao[] = $n;
                }
            }
        }
    } catch (Throwable $e) {
        $visitadores_opcao = [];
    }

    return [
        'aprovados' => $aprovados,
        'recusados_carrinho' => $recusados_carrinho,
        'visitadores_opcao' => $visitadores_opcao,
    ];
}
