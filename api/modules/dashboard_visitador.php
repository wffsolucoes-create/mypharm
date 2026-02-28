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
    
                // Especialidades: pr.profissao ou prescritor_dados.profissao; vazio = Não informada
                $naoInformada = 'Não informada';
                // Gráfico distribuição por especialidade (painel visitador)
                $sqlEsp = "
                    SELECT 
                        COALESCE(NULLIF(TRIM(pr.profissao), ''), NULLIF(TRIM(pd.profissao), ''), :nao_inf) as familia,
                        COUNT(*) as total
                    FROM prescritor_resumido pr
                    LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pr.nome
                    WHERE pr.ano_referencia = :ano
                ";
                if ($isMyPharm) {
                    $sqlEsp .= " AND (pr.visitador IS NULL OR TRIM(pr.visitador) = '' OR LOWER(TRIM(pr.visitador)) = 'my pharm')";
                } else {
                    $sqlEsp .= " AND TRIM(COALESCE(pr.visitador, '')) = TRIM(:nome)";
                }
                $sqlEsp .= " GROUP BY COALESCE(NULLIF(TRIM(pr.profissao), ''), NULLIF(TRIM(pd.profissao), ''), :nao_inf2) ORDER BY total DESC LIMIT 10";
                $stmtEsp = $pdo->prepare($sqlEsp);
                $paramsEsp = ['ano' => $ano, 'nao_inf' => $naoInformada, 'nao_inf2' => $naoInformada];
                if (!$isMyPharm) $paramsEsp['nome'] = $nome;
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
                $stmtVisitasMes = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas WHERE data_visita IS NOT NULL AND $visitadorWhereVis AND YEAR(data_visita) = :y AND MONTH(data_visita) = :m");
                $qVMes = ['y' => $anoRefVisitas, 'm' => $mesRefVisitas];
                if (!$isMyPharm)
                    $qVMes['nome'] = $nome;
                $stmtVisitasMes->execute($qVMes);
                $visitasMes = (int)$stmtVisitasMes->fetchColumn();
    
                // Fallback mes: prescritores distintos com vendas aprovadas no mes
                $sqlFallbackMes = "
                    SELECT COUNT(DISTINCT COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm'))
                    FROM gestao_pedidos gp
                    INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome
                    WHERE gp.ano_referencia = :ano2
                      AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', CONCAT('Or', CHAR(231), 'amento'))
                      AND gp.status_financeiro NOT LIKE '%carrinho%'
                      AND gp.data_aprovacao IS NOT NULL
                      AND MONTH(gp.data_aprovacao) = :m AND YEAR(gp.data_aprovacao) = :y
                ";
                if ($isMyPharm) {
                    $sqlFallbackMes .= " AND (pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')";
                } else {
                    $sqlFallbackMes .= " AND pc.visitador = :nome";
                }
                $stmtFallbackMes = $pdo->prepare($sqlFallbackMes);
                $qFallbackMes = ['ano2' => $anoRefVisitas, 'm' => $mesRefVisitas, 'y' => $anoRefVisitas];
                if (!$isMyPharm)
                    $qFallbackMes['nome'] = $nome;
                $stmtFallbackMes->execute($qFallbackMes);
                $fallbackMes = (int)$stmtFallbackMes->fetchColumn();
                $visitasMes = max($visitasMes, $fallbackMes);
    
                // Visitas na semana atual = quantidade de registros em historico_visitas (semana ISO: seg���dom)
                $stmtVisitasSemana = $pdo->prepare("
                    SELECT COUNT(*) FROM historico_visitas 
                    WHERE data_visita IS NOT NULL 
                      AND $visitadorWhereVis 
                      AND YEARWEEK(data_visita, 1) = YEARWEEK(CURDATE(), 1)
                ");
                $qVSem = [];
                if (!$isMyPharm)
                    $qVSem['nome'] = $nome;
                $stmtVisitasSemana->execute($qVSem);
                $visitasSemana = (int)$stmtVisitasSemana->fetchColumn();
    
                // Fallback semana: prescritores distintos com vendas aprovadas na semana atual
                $sqlFallbackSemana = "
                    SELECT COUNT(DISTINCT COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm'))
                    FROM gestao_pedidos gp
                    INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome
                    WHERE gp.ano_referencia = :ano2
                      AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', CONCAT('Or', CHAR(231), 'amento'))
                      AND gp.status_financeiro NOT LIKE '%carrinho%'
                      AND gp.data_aprovacao IS NOT NULL
                      AND YEARWEEK(gp.data_aprovacao, 1) = YEARWEEK(CURDATE(), 1)
                ";
                if ($isMyPharm) {
                    $sqlFallbackSemana .= " AND (pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')";
                } else {
                    $sqlFallbackSemana .= " AND pc.visitador = :nome";
                }
                $stmtFallback = $pdo->prepare($sqlFallbackSemana);
                $qFallbackSem = ['ano2' => $ano];
                if (!$isMyPharm) $qFallbackSem['nome'] = $nome;
                $stmtFallback->execute($qFallbackSem);
                $fallbackSemana = (int)$stmtFallback->fetchColumn();
                $visitasSemana = max($visitasSemana, $fallbackSemana);
    
                // - Sem filtro de m%�s, mostrar as %Q%ltimas 4 semanas (cont%�nuo)
                $stmtVps = $pdo->prepare("
                    SELECT COUNT(*) FROM historico_visitas 
                    WHERE data_visita IS NOT NULL AND $visitadorWhereVis
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
                        $stmtP = $pdo->prepare("SELECT lat, lng FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
                        $stmtP->execute(['rid' => $rid]);
                        $pts = $stmtP->fetchAll(PDO::FETCH_ASSOC);
                        $n = count($pts);
                        for ($i = 1; $i < $n; $i++) {
                            $km_rotas_periodo += $haversine(
                                $pts[$i - 1]['lat'], $pts[$i - 1]['lng'],
                                $pts[$i]['lat'], $pts[$i]['lng']
                            );
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
    if ($userSetor === 'visitador' && $sessionNome !== '') {
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

    // Recusados/No carrinho: itens_orcamentos_pedidos (mesma fonte do prescritor_resumido; gestao_pedidos não traz esses status)
    $filtroMesItens = $mes ? " AND MONTH(i.data) = :mes" : "";
    $filtroPrescritorItens = $prescritorFilter !== null ? " AND (COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm') = :prescritor)" : "";
    $sqlRecusados = "
        SELECT i.numero as numero_pedido, i.serie as serie_pedido,
               MAX(i.data) as data_aprovacao, MAX(i.data) as data_orcamento,
               COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm') as prescritor,
               COALESCE(MAX(NULLIF(TRIM(i.paciente),'')), '-') as cliente,
               SUM(i.valor_liquido) as valor,
               MAX(CASE WHEN i.status = 'Recusado' THEN 'Recusado' ELSE 'No carrinho' END) as status_financeiro
        FROM itens_orcamentos_pedidos i
        INNER JOIN prescritores_cadastro pc ON COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm') = pc.nome
           AND TRIM(COALESCE(pc.visitador, '')) = TRIM(:nome)
        WHERE i.ano_referencia = :ano
          AND (i.status = 'Recusado' OR i.status = 'No carrinho')
          $filtroMesItens
          $filtroPrescritorItens
        GROUP BY i.numero, i.serie, COALESCE(NULLIF(TRIM(i.prescritor),''), 'My Pharm')
        ORDER BY data_aprovacao DESC, i.numero DESC, i.serie DESC
    ";
    $stmtR = $pdo->prepare($sqlRecusados);
    $stmtR->execute($params);
    $recusados_carrinho = $stmtR->fetchAll(PDO::FETCH_ASSOC);

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
    if ($prescritor === '' || $prescritor === null) {
        echo json_encode(['error' => 'Prescritor não informado', 'componentes' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    $sessionNome = trim($_SESSION['user_nome'] ?? '');
    if ($userSetor === 'visitador' && $sessionNome !== '') {
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
    $nome = trim($_GET['visitador'] ?? '');
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    if ($userSetor === 'visitador' && $nome === '') {
        $nome = trim($_SESSION['user_nome'] ?? '');
    }
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

        // Pontos GPS da rota
        $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
        $stmtP->execute(['rid' => $rid]);
        $pontos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        $qtd = count($pontos);

        $km = 0;
        for ($i = 1; $i < $qtd; $i++) {
            $km += $haversineKm($pontos[$i - 1]['lat'], $pontos[$i - 1]['lng'], $pontos[$i]['lat'], $pontos[$i]['lng']);
        }
        $totalKm += $km;

        $primeiroPonto = $qtd > 0 ? $pontos[0] : null;
        $ultimoPonto = $qtd > 0 ? $pontos[$qtd - 1] : null;

        // Tempo de percurso
        $dataInicio = $rota['data_inicio'];
        $dataFim = $rota['data_fim'];
        $tempoRotaMin = 0;
        if ($dataInicio && $dataFim) {
            $tempoRotaMin = (int)((strtotime($dataFim) - strtotime($dataInicio)) / 60);
        } elseif ($dataInicio && $rota['status'] === 'em_andamento') {
            $tempoRotaMin = (int)((time() - strtotime($dataInicio)) / 60);
        }
        $totalTempoRota += $tempoRotaMin;

        // Visitas desta rota (no mesmo dia do início)
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
            ORDER BY hv.inicio_visita ASC
        ");
        $stmtV->execute(['v' => $nome, 'dia' => $diaRota]);
        $visitas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        $tempoVisitaMin = 0;
        foreach ($visitas as $vis) {
            $d = (int)($vis['duracao_min'] ?? 0);
            if ($d > 0 && $d < 720) $tempoVisitaMin += $d;
        }
        $totalTempoVisita += $tempoVisitaMin;

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
            'local_fim_lat' => $ultimoPonto ? (float)$ultimoPonto['lat'] : null,
            'local_fim_lng' => $ultimoPonto ? (float)$ultimoPonto['lng'] : null,
            'local_fim_hora' => $ultimoPonto ? $ultimoPonto['criado_em'] : null,
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
    $nome = trim($_GET['visitador'] ?? '');
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    if ($userSetor === 'visitador' && $nome === '') {
        $nome = trim($_SESSION['user_nome'] ?? '');
    }
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
