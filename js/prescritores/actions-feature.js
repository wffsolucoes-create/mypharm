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
    var ano = document.getElementById('anoFilter').value || new Date().getFullYear();
    var mes = document.getElementById('mesFilter').value || '';
    var label = tipo === 'aprovados' ? 'Aprovados' : 'Recusados';
    var periodo = mes ? (document.getElementById('mesFilter').options[document.getElementById('mesFilter').selectedIndex].text + '/' + ano) : ('' + ano);
    titleEl.textContent = label + ' – ' + (nome || '') + ' (' + periodo + ')';
    bodyEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--primary);"></i> Carregando...';
    modal.style.display = 'flex';
    try {
        var params = { nome_prescritor: nome, ano: ano };
        if (mes) {
            params.mes = mes;
        }
        if (visitador !== undefined && visitador !== '') {
            params.visitador = visitador;
        }
        var res = await apiGetPrescritores('get_prescritor_dados', params);
        var d = (res && res.dados) ? res.dados : {};
        var valor = tipo === 'aprovados' ? (d.aprovados != null && d.aprovados !== '' ? d.aprovados : '0,00') : (d.recusados != null && d.recusados !== '' ? d.recusados : '0,00');
        bodyEl.innerHTML = '<span style="color:' + (tipo === 'aprovados' ? 'var(--success,#10B981)' : 'var(--danger,#EF4444)') + ';">R$ ' + valor + '</span>';
    } catch (e) {
        bodyEl.innerHTML = '<span style="color:var(--danger);">Erro ao carregar.</span>';
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
