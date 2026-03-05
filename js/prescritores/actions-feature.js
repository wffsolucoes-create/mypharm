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
    var ano = new Date().getFullYear();
    var label = tipo === 'aprovados' ? 'Aprovados' : 'Reprovados';
    titleEl.textContent = 'Lista de ' + label.toLowerCase() + ' — ' + (nome || '') + ' (' + ano + ')';
    bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin" style="color:var(--primary);"></i> Carregando pedidos...</div>';
    modal.style.display = 'flex';
    try {
        var nomeVisitador = (visitador !== undefined && visitador !== '') ? visitador : ((localStorage.getItem('userName') || '').trim() || 'My Pharm');
        var params = { nome: nomeVisitador, ano: ano, prescritor: nome || '' };
        var res = await apiGetPrescritores('list_pedidos_visitador', params);
        var base = tipo === 'aprovados' ? ((res && res.aprovados) || []) : ((res && res.recusados_carrinho) || []);

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
            bodyEl.innerHTML = '<div style="text-align:center; padding:26px; color:var(--text-secondary);">Nenhum pedido ' + esc(label.toLowerCase()) + ' para este prescritor.</div>';
            return;
        }

        var renderRows = function (list) {
            return list.map(function (p, idx) {
                return '<tr style="border-bottom:1px solid var(--border);">' +
                    '<td style="padding:10px 12px;">' + (idx + 1) + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.numero_pedido || '—') + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.serie_pedido || '—') + '</td>' +
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
        var params = { prescritor: nome || '' };
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
