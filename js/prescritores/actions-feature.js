// Feature: ações de prescritor no painel administrativo
function openTransferModal(prescritor, visitadorAtual) {
    document.getElementById('transferPrescritorNome').textContent = prescritor;
    document.getElementById('transferPrescritorInput').value = prescritor;

    var select = document.getElementById('newVisitadorSelect');
    var filter = document.getElementById('visitadorFilter');

    select.innerHTML = '';
    Array.from(filter.options).forEach(function (opt) {
        if (opt.value !== '') {
            var newOpt = document.createElement('option');
            newOpt.value = opt.value;
            newOpt.textContent = opt.textContent;
            if (opt.value === visitadorAtual) {
                newOpt.selected = true;
            }
            select.appendChild(newOpt);
        }
    });

    document.getElementById('modalTransfer').style.display = 'flex';
}

function closeTransferModal() {
    document.getElementById('modalTransfer').style.display = 'none';
}

async function confirmTransfer() {
    var nome = document.getElementById('transferPrescritorInput').value;
    var novo = document.getElementById('newVisitadorSelect').value;
    var btn = document.getElementById('btnConfirmTransfer');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';

    try {
        var csrfH = {};
        if (typeof __csrfToken !== 'undefined' && __csrfToken) {
            csrfH['X-CSRF-Token'] = __csrfToken;
        }
        var response = await fetch('api.php?action=transfer_prescritor', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, csrfH),
            body: JSON.stringify({
                nome_prescritor: nome,
                novo_visitador: novo
            })
        });
        var result = await response.json();

        if (result.success) {
            closeTransferModal();
            if (typeof loadData === 'function') {
                loadData();
            }
        } else {
            alert('Erro ao transferir: ' + result.error);
        }
    } catch (err) {
        console.error(err);
        alert('Erro de conexão ao transferir prescritor');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Confirmar Transferência';
    }
}

function apiGetPrescritores(action, params) {
    if (window.MyPharmApiClient && typeof window.MyPharmApiClient.get === 'function') {
        return window.MyPharmApiClient.get(action, params || {});
    }
    var p = new URLSearchParams({ action: action });
    if (params) {
        Object.keys(params).forEach(function (k) {
            if (params[k] !== undefined && params[k] !== '') {
                p.set(k, params[k]);
            }
        });
    }
    return fetch('api.php?' + p.toString()).then(function (r) { return r.json(); });
}

function apiPostPrescritores(action, body) {
    if (window.MyPharmApiClient && typeof window.MyPharmApiClient.post === 'function') {
        return window.MyPharmApiClient.post(action, body || {});
    }
    var csrfH = {};
    if (typeof __csrfToken !== 'undefined' && __csrfToken) {
        csrfH['X-CSRF-Token'] = __csrfToken;
    }
    return fetch('api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, csrfH),
        body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
}

function openNovoPrescritorModal() {
    document.getElementById('novoPrescritorNome').value = '';
    var sel = document.getElementById('novoPrescritorVisitador');
    var filter = document.getElementById('visitadorFilter');
    sel.innerHTML = '<option value="">My Pharm</option>';
    Array.from(filter.options).forEach(function (opt) {
        if (opt.value !== '') {
            var o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.textContent;
            sel.appendChild(o);
        }
    });
    document.getElementById('modalNovoPrescritor').style.display = 'flex';
}

function closeNovoPrescritorModal() {
    document.getElementById('modalNovoPrescritor').style.display = 'none';
}

async function confirmNovoPrescritor() {
    var nome = (document.getElementById('novoPrescritorNome').value || '').trim();
    var visitador = (document.getElementById('novoPrescritorVisitador').value || '').trim();
    if (!nome) {
        alert('Informe o nome do prescritor.');
        return;
    }
    var btn = document.getElementById('btnConfirmNovoPrescritor');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
    try {
        var result = await apiPostPrescritores('add_prescritor', { nome_prescritor: nome, visitador: visitador || 'My Pharm' });
        if (result.success) {
            closeNovoPrescritorModal();
            if (typeof loadData === 'function') {
                loadData();
            }
        } else {
            alert(result.error || 'Erro ao cadastrar.');
        }
    } catch (err) {
        alert('Erro de conexão.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Cadastrar';
    }
}

function fillSelectFromListPrescritores(selectEl, items, currentValue) {
    if (!selectEl) return;
    var options = [].slice.call(selectEl.options);
    for (var i = options.length - 1; i >= 1; i--) { options[i].remove(); }
    (items || []).forEach(function (item) {
        var nome = (item.nome || item).toString().trim();
        if (!nome) return;
        var opt = document.createElement('option');
        opt.value = nome;
        opt.textContent = nome;
        selectEl.appendChild(opt);
    });
    if (currentValue && typeof currentValue === 'string' && currentValue.trim() !== '') {
        var hasMatch = [].some.call(selectEl.options, function (o) { return o.value === currentValue.trim(); });
        if (!hasMatch) {
            var opt = document.createElement('option');
            opt.value = currentValue.trim();
            opt.textContent = currentValue.trim();
            selectEl.appendChild(opt);
        }
        selectEl.value = currentValue.trim();
    } else {
        selectEl.value = '';
    }
}

async function openEditarPrescritorModal(nome, visitador) {
    var elNome = document.getElementById('edPrescritorNome');
    var elNomeLabel = document.getElementById('edPrescritorNomeLabel');
    if (!elNome || !elNomeLabel) {
        return;
    }
    elNome.value = nome;
    elNomeLabel.textContent = nome;
    ['edPrescritorProfissao', 'edPrescritorEspecialidade', 'edPrescritorRegistro', 'edPrescritorUfRegistro', 'edPrescritorDataNascimento', 'edPrescritorCep', 'edPrescritorRua', 'edPrescritorNumero', 'edPrescritorBairro', 'edPrescritorCidadeUf', 'edPrescritorCidade', 'edPrescritorUf', 'edPrescritorLocalAtendimento', 'edPrescritorWhatsapp', 'edPrescritorEmail'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.value = '';
        }
    });
    try {
        var params = { nome_prescritor: nome };
        if (visitador !== undefined && visitador !== '') {
            params.visitador = visitador;
        }
        var res = await apiGetPrescritores('get_prescritor_dados', params);
        var resP = await apiGetPrescritores('list_profissoes', {});
        var resE = await apiGetPrescritores('list_especialidades', {});
        var d = (res && res.dados) ? res.dados : {};
        var profissoes = (resP && resP.items) ? resP.items : [];
        var especialidades = (resE && resE.items) ? resE.items : [];
        fillSelectFromListPrescritores(document.getElementById('edPrescritorProfissao'), profissoes, d.profissao);
        fillSelectFromListPrescritores(document.getElementById('edPrescritorEspecialidade'), especialidades, d.especialidade);
        document.getElementById('edPrescritorRegistro').value = d.registro || '';
        document.getElementById('edPrescritorUfRegistro').value = d.uf_registro || '';
        document.getElementById('edPrescritorDataNascimento').value = d.data_nascimento || '';
        document.getElementById('edPrescritorCep').value = d.endereco_cep || '';
        document.getElementById('edPrescritorRua').value = d.endereco_rua || '';
        document.getElementById('edPrescritorNumero').value = d.endereco_numero || '';
        document.getElementById('edPrescritorBairro').value = d.endereco_bairro || '';
        document.getElementById('edPrescritorCidade').value = d.endereco_cidade || '';
        document.getElementById('edPrescritorUf').value = d.endereco_uf || '';
        document.getElementById('edPrescritorCidadeUf').value = [d.endereco_cidade, d.endereco_uf].filter(Boolean).join(' - ') || '';
        document.getElementById('edPrescritorLocalAtendimento').value = d.local_atendimento || '';
        document.getElementById('edPrescritorWhatsapp').value = (d.whatsapp || '').replace(/\D/g, '');
        document.getElementById('edPrescritorEmail').value = d.email || '';
    } catch (e) {
        console.warn('get_prescritor_dados', e);
    }
    document.getElementById('modalEditarPrescritor').style.display = 'flex';
}

function closeEditarPrescritorModal() {
    document.getElementById('modalEditarPrescritor').style.display = 'none';
}

async function saveEditarPrescritorModal() {
    var nome = (document.getElementById('edPrescritorNome') || {}).value;
    if (!nome) {
        return;
    }
    var payload = {
        nome_prescritor: nome,
        profissao: (document.getElementById('edPrescritorProfissao') || {}).value,
        especialidade: (document.getElementById('edPrescritorEspecialidade') || {}).value,
        registro: (document.getElementById('edPrescritorRegistro') || {}).value,
        uf_registro: (document.getElementById('edPrescritorUfRegistro') || {}).value,
        data_nascimento: (document.getElementById('edPrescritorDataNascimento') || {}).value,
        endereco_rua: (document.getElementById('edPrescritorRua') || {}).value,
        endereco_numero: (document.getElementById('edPrescritorNumero') || {}).value,
        endereco_bairro: (document.getElementById('edPrescritorBairro') || {}).value,
        endereco_cep: (document.getElementById('edPrescritorCep') || {}).value.replace(/\D/g, ''),
        endereco_cidade: (document.getElementById('edPrescritorCidade') || {}).value,
        endereco_uf: (document.getElementById('edPrescritorUf') || {}).value,
        local_atendimento: (document.getElementById('edPrescritorLocalAtendimento') || {}).value,
        whatsapp: (document.getElementById('edPrescritorWhatsapp') || {}).value,
        email: (document.getElementById('edPrescritorEmail') || {}).value
    };
    var btn = document.getElementById('btnSaveEditarPrescritor');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    try {
        var result = await apiPostPrescritores('update_prescritor_dados', payload);
        if (result && result.success) {
            closeEditarPrescritorModal();
        } else {
            alert('Erro: ' + (result && result.error ? result.error : 'Não foi possível salvar.'));
        }
    } catch (err) {
        alert('Erro de conexão.');
    }
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save" style="margin-right:6px;"></i>Salvar';
    }
}

async function openAprovadosRecusadosModal(nome, tipo, visitador) {
    var modal = document.getElementById('modalAprovadosRecusados');
    var titleEl = document.getElementById('modalAprovadosRecusadosTitle');
    var bodyEl = document.getElementById('modalAprovadosRecusadosBody');
    if (!modal || !titleEl || !bodyEl) {
        return;
    }
    var ano = __getAnoAtualDoFiltro();
    var label = tipo === 'aprovados' ? 'Aprovados' : 'Reprovados';
    titleEl.textContent = 'Lista de ' + label.toLowerCase() + ' — ' + (nome || '') + ' (' + __periodoSelecionadoLabel() + ')';
    bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin" style="color:var(--primary);"></i> Carregando pedidos...</div>';
    modal.style.display = 'flex';
    try {
        var nomeVisitador = (visitador !== undefined && visitador !== '') ? visitador : ((localStorage.getItem('userName') || '').trim() || 'My Pharm');
        var params = { nome: nomeVisitador, ano: ano, prescritor: nome || '' };
        var res = await apiGetPrescritores('list_pedidos_visitador', params);
        var baseRaw = tipo === 'aprovados' ? ((res && res.aprovados) || []) : ((res && res.recusados_carrinho) || []);
        var base = (baseRaw || []).filter(function (p) {
            return __inPeriodoSelecionado(p.data_aprovacao || p.data_orcamento || p.data);
        });

        var esc = function (s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        };
        var fmtMoney = function (v) {
            var n = parseFloat(v) || 0;
            return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        };
        var fmtDate = function (v) {
            if (!v) return '—';
            var s = String(v);
            if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
                var d = new Date(s.slice(0, 10) + 'T12:00:00');
                if (!isNaN(d.getTime())) return d.toLocaleDateString('pt-BR');
            }
            return s;
        };
        var statusBadge = function (tp) {
            var ok = tp === 'aprovados';
            var bg = ok ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)';
            var cor = ok ? '#10B981' : '#EF4444';
            var txt = ok ? 'Aprovado' : 'Reprovado';
            return '<span style="display:inline-block; padding:4px 8px; border-radius:8px; font-size:0.74rem; font-weight:700; background:' + bg + '; color:' + cor + ';">' + txt + '</span>';
        };

        if (!Array.isArray(base) || base.length === 0) {
            bodyEl.innerHTML = '<div style="text-align:center; padding:26px; color:var(--text-secondary);">Nenhum pedido ' + esc(label.toLowerCase()) + ' para este prescritor no período selecionado.</div>';
            return;
        }

        var renderRows = function (list) {
            return list.map(function (p, idx) {
                var n = parseInt(p.numero_pedido || 0, 10) || 0;
                var sNorm = __normalizeSeriePedido(p.serie_pedido);
                var sNum = parseInt(sNorm, 10);
                if (isNaN(sNum)) sNum = 0;
                return '<tr style="border-bottom:1px solid var(--border); cursor:pointer;" title="Ver detalhes e componentes" onclick="openModalDetalhePedidoAdmin(' + n + ',' + sNum + ',\'' + String(ano).replace(/'/g, "\\'") + '\')">' +
                    '<td style="padding:10px 12px;">' + (idx + 1) + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.numero_pedido || '—') + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(__normalizeSeriePedido(p.serie_pedido)) + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(fmtDate(p.data_aprovacao || p.data_orcamento || '')) + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.prescritor || '—') + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.cliente || '—') + '</td>' +
                    '<td style="padding:10px 12px; text-align:right; font-weight:700; color:' + (tipo === 'aprovados' ? 'var(--success,#10B981)' : 'var(--danger,#EF4444)') + ';">' + esc(fmtMoney(p.valor)) + '</td>' +
                    '<td style="padding:10px 12px;">' + statusBadge(tipo) + '</td>' +
                '</tr>';
            }).join('');
        };

        bodyEl.innerHTML =
            '<div style="display:flex; flex-direction:column; gap:10px;">' +
                '<input type="text" id="aprovRecSearchInputAdmin" placeholder="Buscar por prescritor, cliente ou número do pedido..." ' +
                    'style="width:100%; box-sizing:border-box; padding:10px 12px; border-radius:8px; border:1px solid var(--border); background:var(--bg-body); color:var(--text-primary);">' +
                '<div style="max-height:62vh; overflow:auto; border:1px solid var(--border); border-radius:10px;">' +
                    '<table style="width:100%; border-collapse:collapse; min-width:920px;">' +
                        '<thead style="position:sticky; top:0; z-index:2; background:var(--bg-card);">' +
                            '<tr>' +
                                '<th style="padding:10px 12px; text-align:left;">#</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Nº Pedido</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Série</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Data</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Prescritor</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Cliente</th>' +
                                '<th style="padding:10px 12px; text-align:right;">Valor</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Status</th>' +
                            '</tr>' +
                        '</thead>' +
                        '<tbody id="aprovRecTableBodyAdmin">' + renderRows(base) + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';

        var inp = document.getElementById('aprovRecSearchInputAdmin');
        var tbody = document.getElementById('aprovRecTableBodyAdmin');
        if (inp && tbody) {
            inp.addEventListener('input', function () {
                var q = (inp.value || '').toLowerCase().trim();
                var fil = q ? base.filter(function (p) {
                    return String(p.prescritor || '').toLowerCase().indexOf(q) !== -1
                        || String(p.cliente || '').toLowerCase().indexOf(q) !== -1
                        || String(p.numero_pedido || '').indexOf(q) !== -1;
                }) : base.slice();
                tbody.innerHTML = fil.length ? renderRows(fil) : '<tr><td colspan="8" style="padding:18px; text-align:center; color:var(--text-secondary);">Nenhum pedido encontrado.</td></tr>';
            });
        }
    } catch (e) {
        bodyEl.innerHTML = '<span style="color:var(--danger);">Erro ao carregar pedidos.</span>';
    }
}

function closeAprovadosRecusadosModal() {
    var modal = document.getElementById('modalAprovadosRecusados');
    if (modal) {
        modal.style.display = 'none';
    }
}

async function openRelatorioVisitasPrescritorModal(nome, visitador) {
    var modal = document.getElementById('modalRelatorioVisitasPrescritor');
    var titleEl = document.getElementById('modalRelatorioVisitasPrescritorTitle');
    var bodyEl = document.getElementById('modalRelatorioVisitasPrescritorBody');
    if (!modal || !bodyEl) {
        return;
    }
    if (titleEl) {
        titleEl.innerHTML = '<i class="fas fa-clipboard-list" style="margin-right:8px; color:#0ea5e9;"></i>Relatório de visitas – ' + (nome ? String(nome).replace(/</g, '&lt;') : '');
    }
    modal.style.display = 'flex';
    bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    try {
        var per = __getPeriodoFiltro();
        var params = { prescritor: nome || '', data_de: per.data_de, data_ate: per.data_ate };
        if (visitador !== undefined && visitador !== '') {
            params.visitador = visitador;
        }
        var res = await apiGetPrescritores('get_visitas_prescritor', params);
        var visitas = (res && res.visitas) ? res.visitas : [];
        if (visitas.length === 0) {
            bodyEl.innerHTML = '<p style="text-align:center; padding:24px; color:var(--text-secondary); margin:0;">Nenhuma visita registrada para este prescritor.</p>';
            return;
        }
        var html = '';
        visitas.forEach(function (v) {
            var dataStr = (v.data_visita || '').toString().slice(0, 10);
            var horaStr = (v.horario || '').toString().slice(0, 5);
            var status = (v.status_visita || 'Realizada').trim();
            html += '<div style="padding:12px 14px; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; background:var(--bg-body);"><span style="font-weight:600; color:var(--text-primary);">' + (dataStr ? new Date(dataStr + 'T12:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }) : '—') + (horaStr ? ' às ' + horaStr : '') + '</span><span style="margin-left:8px; font-size:0.85rem; color:var(--text-secondary);">' + (status || '') + '</span></div>';
        });
        bodyEl.innerHTML = html;
    } catch (e) {
        bodyEl.innerHTML = '<p style="text-align:center; padding:24px; color:var(--danger); margin:0;">Erro ao carregar visitas.</p>';
    }
}

function closeRelatorioVisitasPrescritorModal() {
    var modal = document.getElementById('modalRelatorioVisitasPrescritor');
    if (modal) {
        modal.style.display = 'none';
    }
}

function __ensureAdminActionModal() {
    var modal = document.getElementById('modalAdminPrescritorAcoes');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'modalAdminPrescritorAcoes';
    modal.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(3px); z-index:4000; justify-content:center; align-items:center; padding:20px; box-sizing:border-box;';
    modal.innerHTML = '' +
        '<div style="background:var(--bg-card); border-radius:12px; width:100%; max-width:1200px; max-height:88vh; border:1px solid var(--border); box-shadow:var(--shadow-lg); overflow:hidden; display:flex; flex-direction:column;">' +
            '<div style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">' +
                '<h3 id="modalAdminPrescritorAcoesTitle" style="margin:0; font-size:1.05rem; font-weight:700; color:var(--text-primary);">Detalhes</h3>' +
                '<button type="button" onclick="closeAdminActionModal()" style="background:none; border:none; font-size:1.4rem; color:var(--text-secondary); cursor:pointer;">&times;</button>' +
            '</div>' +
            '<div id="modalAdminPrescritorAcoesBody" style="padding:16px 20px; overflow:auto; flex:1;"></div>' +
            '<div style="padding:14px 20px; border-top:1px solid var(--border); text-align:right;">' +
                '<button type="button" onclick="closeAdminActionModal()" style="padding:10px 18px; border-radius:8px; border:1px solid var(--border); background:var(--bg-body); color:var(--text-primary); font-weight:600; cursor:pointer;">Fechar</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(modal);
    return modal;
}

function closeAdminActionModal() {
    if (typeof __destroyAdminAnaliseCharts === 'function') __destroyAdminAnaliseCharts();
    var modal = document.getElementById('modalAdminPrescritorAcoes');
    if (modal) modal.style.display = 'none';
}

var __adminAnaliseCharts = {};
var __adminAnalisePrescritorNome = '';

function __destroyAdminAnaliseCharts() {
    Object.keys(__adminAnaliseCharts).forEach(function (k) {
        if (__adminAnaliseCharts[k]) {
            try { __adminAnaliseCharts[k].destroy(); } catch (_) {}
            __adminAnaliseCharts[k] = null;
        }
    });
    __adminAnaliseCharts = {};
}

function __getAnoAtualDoFiltro() {
    var deEl = document.getElementById('dataDeFilter');
    var ateEl = document.getElementById('dataAteFilter');
    var ate = (ateEl && ateEl.value) ? ateEl.value : '';
    var de = (deEl && deEl.value) ? deEl.value : '';
    var ref = ate || de;
    if (ref && /^\d{4}-\d{2}-\d{2}$/.test(ref)) return parseInt(ref.slice(0, 4), 10);
    return new Date().getFullYear();
}

function __getPeriodoFiltro() {
    var deEl = document.getElementById('dataDeFilter');
    var ateEl = document.getElementById('dataAteFilter');
    return {
        data_de: (deEl && deEl.value) ? deEl.value : undefined,
        data_ate: (ateEl && ateEl.value) ? ateEl.value : undefined
    };
}

function __dateToISOOnly(value) {
    if (!value) return '';
    var s = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(s)) {
        var p = s.split('/');
        return p[2] + '-' + p[1] + '-' + p[0];
    }
    var d = new Date(s);
    if (isNaN(d.getTime())) return '';
    return d.toISOString().slice(0, 10);
}

function __inPeriodoSelecionado(dataValue) {
    var per = __getPeriodoFiltro();
    var d = __dateToISOOnly(dataValue);
    if (!d) return false;
    if (per.data_de && d < per.data_de) return false;
    if (per.data_ate && d > per.data_ate) return false;
    return true;
}

function __periodoSelecionadoLabel() {
    var per = __getPeriodoFiltro();
    if (per.data_de && per.data_ate) return __formatDateBr(per.data_de) + ' até ' + __formatDateBr(per.data_ate);
    return 'Período atual';
}

function __normalizeSeriePedido(serie) {
    if (serie === null || serie === undefined) return '0';
    var s = String(serie).trim();
    return s === '' ? '0' : s;
}

function __formatDateBr(value) {
    var iso = __dateToISOOnly(value);
    if (!iso) return '—';
    var p = iso.split('-');
    if (p.length !== 3) return iso;
    return p[2] + '/' + p[1] + '/' + p[0];
}

async function openPedidosPrescritorModal(nome, visitador) {
    var modal = __ensureAdminActionModal();
    var title = document.getElementById('modalAdminPrescritorAcoesTitle');
    var body = document.getElementById('modalAdminPrescritorAcoesBody');
    if (!modal || !title || !body) return;
    title.innerHTML = '<i class="fas fa-list-alt" style="margin-right:8px; color:var(--primary);"></i>Pedidos do prescritor — ' + String(nome || '').replace(/</g, '&lt;') + ' (' + __periodoSelecionadoLabel() + ')';
    body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    modal.style.display = 'flex';
    try {
        var params = { nome: visitador || 'My Pharm', ano: __getAnoAtualDoFiltro(), prescritor: nome || '' };
        var res = await apiGetPrescritores('list_pedidos_visitador', params);
        var aprov = (res && Array.isArray(res.aprovados)) ? res.aprovados : [];
        var rec = (res && Array.isArray(res.recusados_carrinho)) ? res.recusados_carrinho : [];
        var rowsRaw = aprov.map(function (p) { return Object.assign({}, p, { __tipo: 'Aprovado' }); })
            .concat(rec.map(function (p) { return Object.assign({}, p, { __tipo: 'Recusado/No Carrinho' }); }));
        var rows = rowsRaw.filter(function (p) {
            return __inPeriodoSelecionado(p.data_aprovacao || p.data_orcamento || p.data);
        });
        if (!rows.length) {
            body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);">Nenhum pedido encontrado para este prescritor no período selecionado.</div>';
            return;
        }
        var fmtMoney = function (v) { return (parseFloat(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
        var esc = function (v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
        var html = '<div style="overflow:auto; border:1px solid var(--border); border-radius:10px;">' +
            '<table style="width:100%; border-collapse:collapse; min-width:900px;">' +
            '<thead><tr>' +
            '<th style="text-align:left; padding:10px 12px;">Nº</th>' +
            '<th style="text-align:left; padding:10px 12px;">Série</th>' +
            '<th style="text-align:left; padding:10px 12px;">Data</th>' +
            '<th style="text-align:left; padding:10px 12px;">Cliente</th>' +
            '<th style="text-align:right; padding:10px 12px;">Valor</th>' +
            '<th style="text-align:left; padding:10px 12px;">Status</th>' +
            '</tr></thead><tbody>';
        rows.forEach(function (r) {
            var n = parseInt(r.numero_pedido || 0, 10) || 0;
            var sNorm = __normalizeSeriePedido(r.serie_pedido);
            var sNum = parseInt(sNorm, 10);
            if (isNaN(sNum)) sNum = 0;
            html += '<tr style="border-top:1px solid var(--border); cursor:pointer;" title="Ver detalhes e componentes" onclick="openModalDetalhePedidoAdmin(' + n + ',' + sNum + ',\'' + String(__getAnoAtualDoFiltro()).replace(/'/g, "\\'") + '\')">' +
                '<td style="padding:10px 12px;">' + esc(r.numero_pedido || '-') + '</td>' +
                '<td style="padding:10px 12px;">' + esc(__normalizeSeriePedido(r.serie_pedido)) + '</td>' +
                '<td style="padding:10px 12px;">' + esc(__formatDateBr(r.data_aprovacao || r.data_orcamento || '')) + '</td>' +
                '<td style="padding:10px 12px;">' + esc(r.cliente || '-') + '</td>' +
                '<td style="padding:10px 12px; text-align:right; font-weight:600; color:' + (r.__tipo === 'Aprovado' ? 'var(--success)' : 'var(--danger)') + ';">' + esc(fmtMoney(r.valor)) + '</td>' +
                '<td style="padding:10px 12px;">' + esc(r.__tipo) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
    } catch (e) {
        body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">Erro ao carregar pedidos.</div>';
    }
}

async function openComponentesPrescritorModal(nome, tipo, visitador) {
    var modal = __ensureAdminActionModal();
    var title = document.getElementById('modalAdminPrescritorAcoesTitle');
    var body = document.getElementById('modalAdminPrescritorAcoesBody');
    if (!modal || !title || !body) return;
    var isRec = String(tipo || '').toLowerCase() === 'recusados';
    title.innerHTML = '<i class="fas fa-atom" style="margin-right:8px; color:' + (isRec ? '#EF4444' : '#10B981') + ';"></i>Componentes ' + (isRec ? 'recusados' : 'aprovados') + ' — ' + String(nome || '').replace(/</g, '&lt;');
    body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    modal.style.display = 'flex';
    try {
        var periodo = __getPeriodoFiltro();
        var params = {
            nome: visitador || 'My Pharm',
            ano: __getAnoAtualDoFiltro(),
            prescritor: nome || '',
            tipo: isRec ? 'recusados' : 'aprovados',
            data_de: periodo.data_de,
            data_ate: periodo.data_ate
        };
        var res = await apiGetPrescritores('list_componentes_prescritor', params);
        var list = (res && Array.isArray(res.componentes)) ? res.componentes : [];
        if (!list.length) {
            body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);">Nenhum componente encontrado.</div>';
            return;
        }
        var esc = function (v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
        var html = '<div style="overflow:auto; border:1px solid var(--border); border-radius:10px;">' +
            '<table style="width:100%; border-collapse:collapse; min-width:700px;">' +
            '<thead><tr>' +
            '<th style="text-align:left; padding:10px 12px;">Componente</th>' +
            '<th style="text-align:left; padding:10px 12px;">Unidade</th>' +
            '<th style="text-align:right; padding:10px 12px;">Qtd Total</th>' +
            '<th style="text-align:right; padding:10px 12px;">Pedidos</th>' +
            '</tr></thead><tbody>';
        list.forEach(function (c) {
            html += '<tr style="border-top:1px solid var(--border);">' +
                '<td style="padding:10px 12px;">' + esc(c.componente || '-') + '</td>' +
                '<td style="padding:10px 12px;">' + esc(c.unidade || '-') + '</td>' +
                '<td style="padding:10px 12px; text-align:right; font-weight:600;">' + esc(c.quantidade_total || 0) + '</td>' +
                '<td style="padding:10px 12px; text-align:right;">' + esc(c.qtd_pedidos || 0) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
    } catch (e) {
        body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">Erro ao carregar componentes.</div>';
    }
}

async function openAnalisePrescritorModal(nome, visitador) {
    var modal = __ensureAdminActionModal();
    var title = document.getElementById('modalAdminPrescritorAcoesTitle');
    var body = document.getElementById('modalAdminPrescritorAcoesBody');
    if (!modal || !title || !body) return;
    __adminAnalisePrescritorNome = nome || '';
    title.innerHTML = '<i class="fas fa-brain" style="margin-right:8px; color:#8B5CF6;"></i>Inteligência do Prescritor';

    var anoAtual = __getAnoAtualDoFiltro();
    var y = new Date().getFullYear();
    var options = '';
    for (var i = 0; i <= 3; i++) {
        var yOpt = y - i;
        options += '<option value="' + yOpt + '"' + (parseInt(anoAtual, 10) === yOpt ? ' selected' : '') + '>' + yOpt + '</option>';
    }
    body.innerHTML =
        '<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">' +
            '<div style="min-width:0;">' +
                '<div style="font-size:1rem; font-weight:700; color:var(--text-primary);">' + String(nome || '').replace(/</g, '&lt;') + '</div>' +
            '</div>' +
            '<div style="display:flex; align-items:center; gap:8px;">' +
                '<label for="analisePrescritorAnoAdmin" style="font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Ano</label>' +
                '<select id="analisePrescritorAnoAdmin" style="padding:8px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-body); color:var(--text-primary);">' + options + '</select>' +
            '</div>' +
        '</div>' +
        '<div id="analisePrescritorAdminContent"><div style="text-align:center; padding:40px 24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando análise completa...</div></div>';
    modal.style.display = 'flex';

    function calcScore(kpis, comparativo, tendencia) {
        var s = 0;
        var tk = kpis.ticket_medio || 0;
        var tkCart = comparativo.media_ticket_carteira || 1;
        var ratio = tk / tkCart;
        s += ratio >= 1.5 ? 25 : ratio >= 1 ? 20 : ratio >= 0.5 ? Math.round(ratio * 30) : Math.round(ratio * 20);
        var vp = tendencia.variacao_pct || 0;
        s += vp >= 30 ? 25 : vp >= 10 ? 20 : vp >= 0 ? 15 : vp >= -20 ? 8 : 0;
        s += Math.min(25, Math.round((kpis.taxa_aprovacao || 0) / 100 * 25));
        var d = kpis.dias_ultima_compra;
        s += d === null ? 0 : d <= 14 ? 25 : d <= 30 ? 20 : d <= 60 ? 15 : d <= 90 ? 10 : d <= 120 ? 5 : 0;
        return Math.min(100, Math.max(0, s));
    }
    function fmtR(v) { return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtPct(v) { return parseFloat(v || 0).toFixed(1) + '%'; }
    function fmtInt(v) { return parseInt(v || 0, 10).toLocaleString('pt-BR'); }
    function fmtData(d) {
        if (!d) return '—';
        var p = String(d).substring(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d;
    }
    function kpiCard(icon, label, value, sub) {
        return '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:12px 14px;min-width:0;flex:1;">' +
            '<div style="font-size:0.7rem;color:var(--text-secondary);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><i class="fas ' + icon + '" style="margin-right:4px;"></i>' + label + '</div>' +
            '<div style="font-size:1rem;font-weight:700;color:var(--text-primary);white-space:nowrap;">' + value + '</div>' +
            (sub ? '<div style="font-size:0.65rem;color:var(--text-secondary);margin-top:2px;">' + sub + '</div>' : '') +
            '</div>';
    }
    function compSub(val, media, fmt) {
        if (!media || media === 0) return '';
        var diff = val - media;
        var icon = diff >= 0 ? '▲' : '▼';
        var cor = diff >= 0 ? '#059669' : '#dc2626';
        return '<span style="color:' + cor + ';">' + icon + ' ' + (fmt === 'pct' ? Math.abs(diff).toFixed(1) + '%' : fmtR(Math.abs(diff))) + '</span> vs carteira';
    }
    function renderAnaliseCompleta(targetEl, data) {
        var kpis = data.kpis || {};
        var comp = data.comparativo || {};
        var tend = data.tendencia || {};
        var vis = data.visitas || {};
        var rec = data.recorrencia || {};
        var score = calcScore(kpis, comp, tend);
        var scoreCor = score >= 70 ? '#059669' : score >= 40 ? '#d97706' : '#dc2626';
        var scoreLabel = score >= 70 ? 'Saudável' : score >= 40 ? 'Atenção' : 'Crítico';
        var scoreIcon = score >= 70 ? 'fa-heart' : score >= 40 ? 'fa-exclamation-triangle' : 'fa-times-circle';
        var insights = [];
        if (kpis.concentracao_carteira > 0) insights.push('<i class="fas fa-chart-pie" style="color:#6366f1;margin-right:4px;"></i>Representa <b>' + fmtPct(kpis.concentracao_carteira) + '</b> do faturamento da carteira');
        if (tend.variacao_pct > 0) insights.push('<i class="fas fa-arrow-up" style="color:#059669;margin-right:4px;"></i>Tendência de <b>crescimento de ' + fmtPct(tend.variacao_pct) + '</b> (3 meses recentes vs anteriores)');
        else if (tend.variacao_pct < 0) insights.push('<i class="fas fa-arrow-down" style="color:#dc2626;margin-right:4px;"></i>Tendência de <b>queda de ' + fmtPct(Math.abs(tend.variacao_pct)) + '</b> (3 meses recentes vs anteriores)');
        if (rec.taxa_recorrencia > 0) insights.push('<i class="fas fa-redo" style="color:#0891b2;margin-right:4px;"></i><b>' + fmtPct(rec.taxa_recorrencia) + '</b> dos pacientes retornam para novas compras');
        var topFormas = data.top_formas || [];
        var distCanal = data.distribuicao_canal || [];
        var topCompAprov = data.top_componentes || [];
        var topCompReprov = data.top_componentes_reprovados || [];
        var html = '';
        html += '<div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;padding:14px 18px;background:var(--bg-body);border:1px solid var(--border);border-radius:12px;">';
        html += '<div style="position:relative;width:70px;height:70px;flex-shrink:0;"><svg viewBox="0 0 36 36" style="width:70px;height:70px;transform:rotate(-90deg);"><circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(128,128,128,0.15)" stroke-width="3"/><circle cx="18" cy="18" r="15.5" fill="none" stroke="' + scoreCor + '" stroke-width="3" stroke-dasharray="' + (score * 97.4 / 100) + ' 97.4" stroke-linecap="round"/></svg><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:1.1rem;font-weight:800;color:' + scoreCor + ';">' + score + '</div></div>';
        html += '<div style="flex:1;min-width:0;"><div style="font-size:0.95rem;font-weight:700;color:' + scoreCor + ';"><i class="fas ' + scoreIcon + '" style="margin-right:6px;"></i>Score de Saúde: ' + scoreLabel + '</div><div style="font-size:0.75rem;color:var(--text-secondary);margin-top:4px;">Volume + Tendência + Aprovação + Recência</div></div></div>';
        if (insights.length > 0) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">';
            insights.forEach(function (ins) {
                html += '<div style="flex:1;min-width:220px;padding:8px 12px;background:var(--bg-body);border:1px solid var(--border);border-radius:8px;font-size:0.75rem;color:var(--text-secondary);line-height:1.4;">' + ins + '</div>';
            });
            html += '</div>';
        }
        html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:18px;">' +
            kpiCard('fa-check-circle', 'Faturamento aprovado', fmtR(kpis.total_aprovado), '') +
            kpiCard('fa-times-circle', 'Total reprovado', fmtR(kpis.total_reprovado), '') +
            kpiCard('fa-percentage', 'Taxa aprovação', fmtPct(kpis.taxa_aprovacao), compSub(kpis.taxa_aprovacao, comp.media_taxa_aprovacao_carteira, 'pct')) +
            kpiCard('fa-receipt', 'Ticket médio', fmtR(kpis.ticket_medio), compSub(kpis.ticket_medio, comp.media_ticket_carteira, 'money')) +
            kpiCard('fa-coins', 'Margem média', fmtPct(kpis.margem_media), compSub(kpis.margem_media, comp.media_margem_carteira, 'pct')) +
            kpiCard('fa-users', 'Pacientes', fmtInt(kpis.total_pacientes), (rec.pacientes_recorrentes || 0) > 0 ? rec.pacientes_recorrentes + ' recorrentes' : '') +
            kpiCard('fa-shopping-bag', 'Pedidos', fmtInt(kpis.total_pedidos), (kpis.pedidos_aprovados || 0) + ' aprov. / ' + (kpis.pedidos_reprovados || 0) + ' reprov.') +
            kpiCard('fa-clock', 'Última compra', kpis.dias_ultima_compra !== null ? kpis.dias_ultima_compra + ' dias' : '—', '') +
        '</div>';
        html += '<div style="margin-bottom:18px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Evolução mensal — Valores aprovados x reprovados (R$)</h4><div style="height:200px;position:relative;"><canvas id="chartAnalVendasAdmin"></canvas></div></div>';
        html += '<div style="margin-bottom:18px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Evolução mensal — Pedidos aprovados x reprovados (qtd)</h4><div style="height:200px;position:relative;"><canvas id="chartAnalPedidosAdmin"></canvas></div></div>';
        html += '<div style="margin-bottom:18px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Evolução mensal — Componentes aprovados x reprovados (qtd)</h4><div style="height:200px;position:relative;"><canvas id="chartAnalComponentesAdmin"></canvas></div></div>';
        if (topFormas.length > 0 || distCanal.length > 0) {
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">';
            if (topFormas.length > 0) html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Top formas farmacêuticas</h4><div style="height:180px;position:relative;"><canvas id="chartAnalFormasAdmin"></canvas></div></div>';
            if (distCanal.length > 0) html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Canais de atendimento</h4><div style="height:180px;position:relative;"><canvas id="chartAnalCanaisAdmin"></canvas></div></div>';
            html += '</div>';
        }
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">';
        html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;"><i class="fas fa-check-circle" style="color:#059669;margin-right:6px;"></i>Top 10 componentes aprovados (por pedido)</h4><div style="height:' + Math.max(140, Math.min(10, topCompAprov.length) * 28) + 'px;position:relative;min-height:140px;"><canvas id="chartAnalTopCompAprovAdmin"></canvas></div>' + (topCompAprov.length === 0 ? '<p style="font-size:0.8rem;color:var(--text-secondary);margin:8px 0 0 0;">Nenhum componente aprovado no período.</p>' : '') + '</div>';
        html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;"><i class="fas fa-times-circle" style="color:#dc2626;margin-right:6px;"></i>Top 10 componentes reprovados (por pedido)</h4><div style="height:' + Math.max(140, Math.min(10, topCompReprov.length) * 28) + 'px;position:relative;min-height:140px;"><canvas id="chartAnalTopCompReprovAdmin"></canvas></div>' + (topCompReprov.length === 0 ? '<p style="font-size:0.8rem;color:var(--text-secondary);margin:8px 0 0 0;">Nenhum componente reprovado no período.</p>' : '') + '</div>';
        html += '</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:8px;">' +
            kpiCard('fa-map-marker-alt', 'Visitas realizadas', fmtInt(vis.total_visitas), '') +
            kpiCard('fa-calendar-check', 'Última visita', fmtData(vis.ultima_visita), vis.dias_sem_visita !== null ? vis.dias_sem_visita + ' dias atrás' : '') +
            kpiCard('fa-redo', 'Recorrência', fmtPct(rec.taxa_recorrencia), (rec.pacientes_recorrentes || 0) + ' de ' + (rec.total_pacientes || 0) + ' pacientes') +
            kpiCard('fa-calendar-alt', 'Meses ativos', fmtInt(kpis.meses_ativos) + ' de 12', '') +
        '</div>';
        targetEl.innerHTML = html;

        if (typeof Chart === 'undefined') return;
        __destroyAdminAnaliseCharts();
        var meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        var chartOpts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } }, scales: { y: { beginAtZero: true } } };
        var vendas = data.vendas_mensal || [];
        var pedidos = data.pedidos_mensal || [];
        var comps = data.componentes_mensal || [];
        var cvEl = document.getElementById('chartAnalVendasAdmin');
        if (cvEl) __adminAnaliseCharts.vendas = new Chart(cvEl.getContext('2d'), { type: 'line', data: { labels: meses, datasets: [{ label: 'Aprovadas (R$)', data: vendas.map(function (r) { return parseFloat(r.valor_aprovado) || 0; }), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.08)', fill: true, tension: 0.3, pointRadius: 3 }, { label: 'Reprovadas (R$)', data: vendas.map(function (r) { return parseFloat(r.valor_reprovado) || 0; }), borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.08)', fill: true, tension: 0.3, pointRadius: 3 }] }, options: chartOpts });
        var cpEl = document.getElementById('chartAnalPedidosAdmin');
        if (cpEl) __adminAnaliseCharts.pedidos = new Chart(cpEl.getContext('2d'), { type: 'line', data: { labels: meses, datasets: [{ label: 'Aprovados (qtd)', data: pedidos.map(function (r) { return parseInt(r.qtd_aprovado, 10) || 0; }), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)', fill: true, tension: 0.3, pointRadius: 3 }, { label: 'Reprovados (qtd)', data: pedidos.map(function (r) { return parseInt(r.qtd_reprovado, 10) || 0; }), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.08)', fill: true, tension: 0.3, pointRadius: 3 }] }, options: chartOpts });
        var ccEl = document.getElementById('chartAnalComponentesAdmin');
        if (ccEl) __adminAnaliseCharts.componentes = new Chart(ccEl.getContext('2d'), { type: 'line', data: { labels: meses, datasets: [{ label: 'Aprovados (qtd)', data: comps.map(function (r) { return parseFloat(r.qtd_aprovado) || 0; }), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.08)', fill: true, tension: 0.3, pointRadius: 3 }, { label: 'Reprovados (qtd)', data: comps.map(function (r) { return parseFloat(r.qtd_reprovado) || 0; }), borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.08)', fill: true, tension: 0.3, pointRadius: 3 }] }, options: chartOpts });
        var doughnutOpts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 10 }, boxWidth: 12 } } } };
        var cores = ['#6366f1', '#059669', '#f59e0b', '#dc2626', '#0891b2', '#8b5cf6', '#ec4899'];
        var fEl = document.getElementById('chartAnalFormasAdmin');
        if (fEl && topFormas.length > 0) __adminAnaliseCharts.formas = new Chart(fEl.getContext('2d'), { type: 'doughnut', data: { labels: topFormas.map(function (f) { return f.forma || '—'; }), datasets: [{ data: topFormas.map(function (f) { return parseFloat(f.total) || 0; }), backgroundColor: cores }] }, options: doughnutOpts });
        var cEl = document.getElementById('chartAnalCanaisAdmin');
        if (cEl && distCanal.length > 0) __adminAnaliseCharts.canais = new Chart(cEl.getContext('2d'), { type: 'doughnut', data: { labels: distCanal.map(function (c) { return c.canal || '—'; }), datasets: [{ data: distCanal.map(function (c) { return parseFloat(c.total) || 0; }), backgroundColor: cores }] }, options: doughnutOpts });
        var taEl = document.getElementById('chartAnalTopCompAprovAdmin');
        if (taEl && topCompAprov.length > 0) __adminAnaliseCharts.topCompAprov = new Chart(taEl.getContext('2d'), { type: 'bar', data: { labels: topCompAprov.map(function (c) { return c.componente || '—'; }), datasets: [{ label: 'Quantidade', data: topCompAprov.map(function (c) { return parseFloat(c.qtd) || 0; }), backgroundColor: '#059669', borderRadius: 4 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });
        var trEl = document.getElementById('chartAnalTopCompReprovAdmin');
        if (trEl && topCompReprov.length > 0) __adminAnaliseCharts.topCompReprov = new Chart(trEl.getContext('2d'), { type: 'bar', data: { labels: topCompReprov.map(function (c) { return c.componente || '—'; }), datasets: [{ label: 'Qtd', data: topCompReprov.map(function (c) { return parseFloat(c.qtd) || 0; }), backgroundColor: '#dc2626', borderRadius: 4 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });
    }

    async function loadByAno() {
        var contentEl = document.getElementById('analisePrescritorAdminContent');
        var anoEl = document.getElementById('analisePrescritorAnoAdmin');
        if (!contentEl || !anoEl) return;
        var ano = anoEl.value || String(__getAnoAtualDoFiltro());
        var nomeVisitador = (visitador || '').trim() || (localStorage.getItem('userName') || '').trim() || 'My Pharm';
        var jaTem = contentEl.children.length > 0 && contentEl.getAttribute('data-loaded') === '1';
        if (!jaTem) {
            contentEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando análise completa...</div>';
        } else {
            contentEl.style.position = 'relative';
            var ov = document.createElement('div');
            ov.id = 'analisePrescritorAdminOverlay';
            ov.style.cssText = 'position:absolute;inset:0;background:rgba(0,0,0,0.38);display:flex;align-items:center;justify-content:center;z-index:20;border-radius:10px;';
            ov.innerHTML = '<div style="background:var(--bg-card);padding:14px 20px;border-radius:10px;border:1px solid var(--border);"><i class="fas fa-spinner fa-spin" style="margin-right:8px;color:var(--primary);"></i>Atualizando...</div>';
            contentEl.appendChild(ov);
            anoEl.disabled = true;
        }
        try {
            var res = await apiGetPrescritores('analise_prescritor', { prescritor: __adminAnalisePrescritorNome, ano: ano, nome: nomeVisitador });
            var ov2 = document.getElementById('analisePrescritorAdminOverlay');
            if (ov2) ov2.remove();
            anoEl.disabled = false;
            if (res && res.error) {
                __destroyAdminAnaliseCharts();
                contentEl.setAttribute('data-loaded', '0');
                contentEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">' + String(res.error).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
                return;
            }
            renderAnaliseCompleta(contentEl, res || {});
            contentEl.setAttribute('data-loaded', '1');
        } catch (e) {
            var ov3 = document.getElementById('analisePrescritorAdminOverlay');
            if (ov3) ov3.remove();
            anoEl.disabled = false;
            __destroyAdminAnaliseCharts();
            contentEl.setAttribute('data-loaded', '0');
            contentEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">Erro ao carregar análises.</div>';
        }
    }

    var selectAno = document.getElementById('analisePrescritorAnoAdmin');
    if (selectAno) {
        selectAno.onchange = function () {
            if (__adminAnalisePrescritorNome) loadByAno();
        };
    }

    await loadByAno();
}

function __ensureModalDetalhePedidoAdmin() {
    var modal = document.getElementById('modalDetalhePedidoAdmin');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'modalDetalhePedidoAdmin';
    modal.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(3px); z-index:4300; padding:16px; box-sizing:border-box; align-items:center; justify-content:center;';
    modal.innerHTML =
        '<div style="background:var(--bg-card); border:1px solid var(--border); border-radius:14px; width:100%; max-width:1280px; max-height:94vh; overflow:hidden; display:flex; flex-direction:column;">' +
            '<div style="padding:14px 18px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:rgba(15,23,42,0.4);">' +
                '<h3 id="modalDetalhePedidoAdminTitle" style="margin:0; font-size:1.18rem; color:var(--text-primary);"><i class="fas fa-file-lines" style="margin-right:8px; color:var(--primary);"></i>Detalhe do Pedido</h3>' +
                '<button type="button" onclick="closeModalDetalhePedidoAdmin()" style="background:none; border:none; color:var(--text-secondary); font-size:1.4rem; cursor:pointer;">&times;</button>' +
            '</div>' +
            '<div id="modalDetalhePedidoAdminLoading" style="padding:30px 22px; text-align:center; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Carregando pedido...</div>' +
            '<div id="modalDetalhePedidoAdminError" style="display:none; padding:24px; color:var(--danger); text-align:center;"></div>' +
            '<div id="modalDetalhePedidoAdminContent" style="display:none; padding:16px 18px; overflow:auto; flex:1;">' +
                '<div style="font-size:0.78rem; letter-spacing:0.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px; font-weight:700;">Dados do Pedido</div>' +
                '<div id="modalDetalhePedidoAdminFields" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:16px;"></div>' +
                '<div id="modalDetalhePedidoAdminItensGestaoWrap" style="display:none; margin-bottom:16px;">' +
                    '<div style="font-size:0.78rem; letter-spacing:0.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px; font-weight:700;">Itens (Gestão de Pedidos)</div>' +
                    '<div style="overflow:auto; border:1px solid var(--border); border-radius:10px;">' +
                        '<table style="width:100%; border-collapse:collapse; min-width:740px;">' +
                            '<thead style="background:rgba(15,23,42,0.55);"><tr>' +
                                '<th style="padding:10px 12px; text-align:left;">#</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Produto</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Forma</th>' +
                                '<th style="padding:10px 12px; text-align:right;">Qtd</th>' +
                                '<th style="padding:10px 12px; text-align:right;">Preço líq.</th>' +
                            '</tr></thead>' +
                            '<tbody id="modalDetalhePedidoAdminItensGestao"></tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
                '<div id="modalDetalhePedidoAdminItensOrcamentoWrap" style="display:none; margin-bottom:16px;">' +
                    '<div style="font-size:0.78rem; letter-spacing:0.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px; font-weight:700;">Itens (Orçamento)</div>' +
                    '<div style="overflow:auto; border:1px solid var(--border); border-radius:10px;">' +
                        '<table style="width:100%; border-collapse:collapse; min-width:920px;">' +
                            '<thead style="background:rgba(15,23,42,0.55);"><tr>' +
                                '<th style="padding:10px 12px; text-align:left;">#</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Descrição</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Canal</th>' +
                                '<th style="padding:10px 12px; text-align:right;">Qtd</th>' +
                                '<th style="padding:10px 12px; text-align:right;">Valor líq.</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Inclusão</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Aprovador</th>' +
                            '</tr></thead>' +
                            '<tbody id="modalDetalhePedidoAdminItensOrcamento"></tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
                '<div>' +
                    '<div style="font-size:0.78rem; letter-spacing:0.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px; font-weight:700;">Componentes</div>' +
                    '<div style="overflow:auto; border:1px solid var(--border); border-radius:10px;">' +
                        '<table style="width:100%; border-collapse:collapse; min-width:740px;">' +
                            '<thead style="background:rgba(15,23,42,0.55);"><tr>' +
                                '<th style="padding:10px 12px; text-align:left;">#</th>' +
                                '<th style="padding:10px 12px; text-align:left;">Descrição</th>' +
                                '<th style="padding:10px 12px; text-align:right;">Qtd calc.</th>' +
                            '</tr></thead>' +
                            '<tbody id="modalDetalhePedidoAdminComponentes"></tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div id="modalDetalhePedidoAdminComponentesEmpty" style="display:none; margin-top:10px; color:var(--text-secondary); text-align:center;">Nenhum componente disponível para este pedido.</div>' +
                '</div>' +
            '</div>' +
            '<div style="padding:12px 18px; border-top:1px solid var(--border); text-align:right;">' +
                '<button type="button" onclick="closeModalDetalhePedidoAdmin()" style="padding:9px 16px; border-radius:8px; border:1px solid var(--border); background:var(--bg-body); color:var(--text-primary); font-weight:600; cursor:pointer;">Fechar</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(modal);
    return modal;
}

function closeModalDetalhePedidoAdmin() {
    var modal = document.getElementById('modalDetalhePedidoAdmin');
    if (modal) modal.style.display = 'none';
}

async function openModalDetalhePedidoAdmin(numero, serie, ano) {
    var modal = __ensureModalDetalhePedidoAdmin();
    var titleEl = document.getElementById('modalDetalhePedidoAdminTitle');
    var loadingEl = document.getElementById('modalDetalhePedidoAdminLoading');
    var errEl = document.getElementById('modalDetalhePedidoAdminError');
    var contentEl = document.getElementById('modalDetalhePedidoAdminContent');
    if (!modal || !titleEl || !loadingEl || !errEl || !contentEl) return;
    var serieNorm = __normalizeSeriePedido(serie);
    titleEl.innerHTML = '<i class="fas fa-file-lines" style="margin-right:8px; color:var(--primary);"></i>Detalhe do Pedido';
    loadingEl.style.display = 'block';
    errEl.style.display = 'none';
    errEl.textContent = '';
    contentEl.style.display = 'none';
    modal.style.display = 'flex';
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
    function fmtDate(v) { return __formatDateBr(v); }
    function fmtMoney(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }
    function fmtQtdCalculada(qtd, unidade) {
        if (qtd === null || qtd === undefined || qtd === '') return '—';
        var n = parseFloat(qtd);
        if (isNaN(n)) return String(qtd).trim() + (unidade ? ' ' + String(unidade).trim() : '');
        var s = Number.isInteger(n) ? String(Math.round(n)) : String(n);
        var u = (unidade && String(unidade).trim()) ? ' ' + String(unidade).trim() : '';
        return s + u;
    }
    try {
        var params = { numero: numero, serie: serieNorm };
        if (ano) params.ano = ano;
        var det = await apiGetPrescritores('get_pedido_detalhe', params);
        if (det && det.error && !det.pedido) {
            loadingEl.style.display = 'none';
            errEl.textContent = det.error || 'Pedido não encontrado.';
            errEl.style.display = 'block';
            return;
        }
        var comp = await apiGetPrescritores('get_pedido_componentes', params);
        var p = (det && (det.pedido || det.resumo)) ? (det.pedido || det.resumo) : {};
        var itensGestao = Array.isArray(det && det.itens_gestao) ? det.itens_gestao : [];
        var itensOrcamento = Array.isArray(det && det.itens_orcamento) ? det.itens_orcamento : [];
        var componentes = (comp && Array.isArray(comp.componentes)) ? comp.componentes : [];
        loadingEl.style.display = 'none';
        contentEl.style.display = 'block';

        var fieldsEl = document.getElementById('modalDetalhePedidoAdminFields');
        var seriePedido = __normalizeSeriePedido(p.serie_pedido || serieNorm);
        var fieldsHtml = '';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Nº / Série</span><div style="font-weight:700;">' + esc((p.numero_pedido || numero) + ' / ' + seriePedido) + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Data aprovação</span><div style="font-weight:700;">' + esc(fmtDate(p.data_aprovacao)) + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Data orçamento</span><div style="font-weight:700;">' + esc(fmtDate(p.data_orcamento || p.data)) + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Canal</span><div style="font-weight:700;">' + esc(p.canal_atendimento || p.canal || '—') + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Cliente / Paciente</span><div style="font-weight:700;">' + esc(p.cliente || p.paciente || '—') + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Prescritor</span><div style="font-weight:700;">' + esc(p.prescritor || '—') + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Status</span><div style="font-weight:700;">' + esc(p.status_financeiro || p.status || '—') + '</div></div>';
        fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Convênio</span><div style="font-weight:700;">' + esc(p.convenio || '—') + '</div></div>';
        if (p.total_gestao != null) fieldsHtml += '<div><span style="font-size:0.72rem; color:var(--text-secondary);">Total (gestão)</span><div style="font-weight:700; color:var(--success);">' + esc(fmtMoney(p.total_gestao)) + '</div></div>';
        if (fieldsEl) fieldsEl.innerHTML = fieldsHtml;

        var wrapGestao = document.getElementById('modalDetalhePedidoAdminItensGestaoWrap');
        var tbodyGestao = document.getElementById('modalDetalhePedidoAdminItensGestao');
        if (wrapGestao && tbodyGestao) {
            if (itensGestao.length) {
                wrapGestao.style.display = 'block';
                var rowsG = '';
                itensGestao.forEach(function (it, i) {
                    rowsG += '<tr style="border-top:1px solid var(--border);">' +
                        '<td style="padding:10px 12px;">' + (i + 1) + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(it.produto || '—') + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(it.forma_farmaceutica || '—') + '</td>' +
                        '<td style="padding:10px 12px; text-align:right;">' + esc(it.quantidade || '—') + '</td>' +
                        '<td style="padding:10px 12px; text-align:right;">' + esc(fmtMoney(it.preco_liquido || 0)) + '</td>' +
                    '</tr>';
                });
                tbodyGestao.innerHTML = rowsG;
            } else {
                wrapGestao.style.display = 'none';
                tbodyGestao.innerHTML = '';
            }
        }

        var wrapOrc = document.getElementById('modalDetalhePedidoAdminItensOrcamentoWrap');
        var tbodyOrc = document.getElementById('modalDetalhePedidoAdminItensOrcamento');
        if (wrapOrc && tbodyOrc) {
            if (itensOrcamento.length) {
                wrapOrc.style.display = 'block';
                var rowsO = '';
                itensOrcamento.forEach(function (it, i) {
                    rowsO += '<tr style="border-top:1px solid var(--border);">' +
                        '<td style="padding:10px 12px;">' + (i + 1) + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(it.descricao || '—') + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(it.canal || '—') + '</td>' +
                        '<td style="padding:10px 12px; text-align:right;">' + esc(it.quantidade || '—') + '</td>' +
                        '<td style="padding:10px 12px; text-align:right;">' + esc(fmtMoney(it.valor_liquido || 0)) + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(it.usuario_inclusao || '—') + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(it.usuario_aprovador || '—') + '</td>' +
                    '</tr>';
                });
                tbodyOrc.innerHTML = rowsO;
            } else {
                wrapOrc.style.display = 'none';
                tbodyOrc.innerHTML = '';
            }
        }

        var tbodyComp = document.getElementById('modalDetalhePedidoAdminComponentes');
        var compEmpty = document.getElementById('modalDetalhePedidoAdminComponentesEmpty');
        if (tbodyComp && compEmpty) {
            if (!componentes.length) {
                tbodyComp.innerHTML = '';
                compEmpty.style.display = 'block';
            } else {
                compEmpty.style.display = 'none';
                var rowsC = '';
                componentes.forEach(function (c, i) {
                    rowsC += '<tr style="border-top:1px solid var(--border);">' +
                        '<td style="padding:10px 12px;">' + (i + 1) + '</td>' +
                        '<td style="padding:10px 12px;">' + esc(c.descricao || c.componente || '—') + '</td>' +
                        '<td style="padding:10px 12px; text-align:right;">' + esc(fmtQtdCalculada(c.qtd_calculada, c.unidade)) + '</td>' +
                    '</tr>';
                });
                tbodyComp.innerHTML = rowsC;
            }
        }
    } catch (e) {
        loadingEl.style.display = 'none';
        errEl.textContent = e && e.message ? e.message : 'Erro ao carregar detalhe do pedido.';
        errEl.style.display = 'block';
    }
}
