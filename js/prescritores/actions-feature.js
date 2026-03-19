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
                return '<tr style="border-bottom:1px solid var(--border);">' +
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
    var modal = document.getElementById('modalAdminPrescritorAcoes');
    if (modal) modal.style.display = 'none';
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
            html += '<tr style="border-top:1px solid var(--border);">' +
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
    title.innerHTML = '<i class="fas fa-chart-line" style="margin-right:8px; color:#8B5CF6;"></i>Análise do prescritor — ' + String(nome || '').replace(/</g, '&lt;') + ' (' + __periodoSelecionadoLabel() + ')';
    body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    modal.style.display = 'flex';
    try {
        var per = __getPeriodoFiltro();
        var params = { nome: visitador || 'My Pharm', ano: __getAnoAtualDoFiltro(), prescritor: nome || '' };
        var res = await apiGetPrescritores('list_pedidos_visitador', params);
        if (!res || res.error) {
            body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">' + (res && res.error ? res.error : 'Erro ao carregar análise.') + '</div>';
            return;
        }
        var aprov = ((res && res.aprovados) || []).filter(function (p) { return __inPeriodoSelecionado(p.data_aprovacao || p.data_orcamento || p.data); });
        var rec = ((res && res.recusados_carrinho) || []).filter(function (p) { return __inPeriodoSelecionado(p.data_aprovacao || p.data_orcamento || p.data); });
        var totalAprovado = aprov.reduce(function (s, p) { return s + (parseFloat(p.valor) || 0); }, 0);
        var totalReprovado = rec.reduce(function (s, p) { return s + (parseFloat(p.valor) || 0); }, 0);
        var qtdAprov = aprov.length;
        var qtdReprov = rec.length;
        var qtdTotal = qtdAprov + qtdReprov;
        var taxaAprov = (totalAprovado + totalReprovado) > 0 ? ((totalAprovado / (totalAprovado + totalReprovado)) * 100) : 0;
        var ticketMedio = qtdAprov > 0 ? (totalAprovado / qtdAprov) : 0;
        var visitasRes = await apiGetPrescritores('get_visitas_prescritor', { prescritor: nome || '', visitador: visitador || 'My Pharm', data_de: per.data_de, data_ate: per.data_ate });
        var totalVisitas = (visitasRes && Array.isArray(visitasRes.visitas)) ? visitasRes.visitas.length : 0;
        var esc = function (v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
        var fmtMoney = function (v) { return (parseFloat(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
        var fmtPct = function (v) { return (parseFloat(v) || 0).toFixed(1) + '%'; };
        body.innerHTML =
            '<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px;">' +
                '<div style="padding:12px; border:1px solid var(--border); border-radius:10px; background:var(--bg-body);"><div style="font-size:0.75rem; color:var(--text-secondary);">Valor aprovado</div><div style="font-weight:700; color:var(--success);">' + esc(fmtMoney(totalAprovado)) + '</div></div>' +
                '<div style="padding:12px; border:1px solid var(--border); border-radius:10px; background:var(--bg-body);"><div style="font-size:0.75rem; color:var(--text-secondary);">Valor recusado</div><div style="font-weight:700; color:var(--danger);">' + esc(fmtMoney(totalReprovado)) + '</div></div>' +
                '<div style="padding:12px; border:1px solid var(--border); border-radius:10px; background:var(--bg-body);"><div style="font-size:0.75rem; color:var(--text-secondary);">Taxa aprovação</div><div style="font-weight:700;">' + esc(fmtPct(taxaAprov)) + '</div></div>' +
                '<div style="padding:12px; border:1px solid var(--border); border-radius:10px; background:var(--bg-body);"><div style="font-size:0.75rem; color:var(--text-secondary);">Ticket médio</div><div style="font-weight:700;">' + esc(fmtMoney(ticketMedio)) + '</div></div>' +
            '</div>' +
            '<div style="margin-top:12px; padding:12px; border:1px solid var(--border); border-radius:10px; background:var(--bg-body);">' +
                '<div style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:8px;">Resumo do período</div>' +
                '<div style="display:flex; gap:16px; flex-wrap:wrap; font-size:0.9rem;">' +
                    '<span><b>Qtd aprovados:</b> ' + esc(qtdAprov) + '</span>' +
                    '<span><b>Qtd recusados:</b> ' + esc(qtdReprov) + '</span>' +
                    '<span><b>Total pedidos:</b> ' + esc(qtdTotal) + '</span>' +
                    '<span><b>Visitas registradas:</b> ' + esc(totalVisitas) + '</span>' +
                '</div>' +
            '</div>';
    } catch (e) {
        body.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">Erro ao carregar análise.</div>';
    }
}
