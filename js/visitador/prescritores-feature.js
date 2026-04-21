// Feature: ações de prescritor no painel do visitador
var __edPrescritorIsNovoCadastro = false;
var __visitadorPrescritoresMetaCache = {
    profissoes: null,
    especialidades: null
};
function openWhatsAppModal(nome, numero) {
    document.getElementById('whatsPrescritorNome').value = nome;
    document.getElementById('whatsModalPrescritorName').textContent = 'Prescritor: ' + nome;
    document.getElementById('whatsPrescritorNumero').value = numero || '';
    document.getElementById('whatsModalTitle').textContent = numero ? 'Editar WhatsApp' : 'Adicionar WhatsApp';
    document.getElementById('whatsStatusMsg').style.display = 'none';
    document.getElementById('modalWhatsApp').style.display = 'flex';
    setTimeout(function () { document.getElementById('whatsPrescritorNumero').focus(); }, 100);
}

function closeWhatsAppModal() {
    document.getElementById('modalWhatsApp').style.display = 'none';
}

function formatWhatsApp(input) {
    var v = input.value.replace(/\D/g, '');
    if (v.length > 11) {
        v = v.slice(0, 11);
    }
    if (v.length > 7) {
        v = '(' + v.slice(0, 2) + ') ' + v.slice(2, 7) + '-' + v.slice(7);
    } else if (v.length > 2) {
        v = '(' + v.slice(0, 2) + ') ' + v.slice(2);
    } else if (v.length > 0) {
        v = '(' + v;
    }
    input.value = v;
}

async function savePrescritorWhatsApp() {
    var nome = document.getElementById('whatsPrescritorNome').value;
    var whatsapp = document.getElementById('whatsPrescritorNumero').value;
    var statusMsg = document.getElementById('whatsStatusMsg');
    var btn = document.getElementById('btnSaveWhats');

    if (!whatsapp || whatsapp.replace(/\D/g, '').length < 10) {
        statusMsg.textContent = '⚠️ Informe um número válido (DDD + número)';
        statusMsg.style.color = 'var(--warning)';
        statusMsg.style.display = 'block';
        return;
    }

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    btn.disabled = true;

    try {
        var result = await apiPost('save_prescritor_whatsapp', {
            nome_prescritor: nome,
            whatsapp: whatsapp
        });

        if (result.success) {
            statusMsg.textContent = '✅ WhatsApp salvo com sucesso!';
            statusMsg.style.color = 'var(--success)';
            statusMsg.style.display = 'block';
            prescritorContatos[nome] = whatsapp;
            var search = document.getElementById('searchPrescritor').value.toLowerCase();
            var filtered = search ? allPrescritores.filter(function (p) { return p.prescritor.toLowerCase().includes(search); }) : allPrescritores;
            renderModalPrescritores(filtered);
            var pagPresc = document.getElementById('paginaPrescritores');
            if (pagPresc && pagPresc.style.display !== 'none' && typeof __paginaPrescritoresList !== 'undefined') {
                __paginaPrescritoresList.forEach(function (p) {
                    if ((p.prescritor || '') === nome) {
                        p.whatsapp = whatsapp;
                    }
                });
                if (typeof renderPaginaPrescritores === 'function') {
                    renderPaginaPrescritores();
                }
            }
            setTimeout(function () { closeWhatsAppModal(); }, 1000);
        } else {
            statusMsg.textContent = '❌ ' + (result.error || 'Erro ao salvar');
            statusMsg.style.color = 'var(--danger)';
            statusMsg.style.display = 'block';
        }
    } catch (err) {
        statusMsg.textContent = '❌ Erro de conexão';
        statusMsg.style.color = 'var(--danger)';
        statusMsg.style.display = 'block';
    }

    btn.innerHTML = '<i class="fas fa-save"></i> Salvar';
    btn.disabled = false;
}

function fillSelectFromList(selectEl, items, currentValue) {
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

async function openEditarPrescritorModal(nome, opts) {
    var elNome = document.getElementById('edPrescritorNome');
    var elNomeInput = document.getElementById('edPrescritorNomeInput');
    var elNomeLabel = document.getElementById('edPrescritorNomeLabel');
    var titleEl = document.getElementById('edPrescritorModalTitle');
    var saveBtn = document.getElementById('btnSaveEditarPrescritor');
    __edPrescritorIsNovoCadastro = !!(opts && opts.isNovoCadastro);
    if (!elNome || !elNomeInput || !elNomeLabel) {
        return;
    }
    var nomeNormalizado = (nome || '').trim();
    elNome.value = nomeNormalizado;
    elNomeInput.value = nomeNormalizado;
    elNomeInput.readOnly = !__edPrescritorIsNovoCadastro;
    elNomeInput.style.opacity = __edPrescritorIsNovoCadastro ? '1' : '0.9';
    elNomeLabel.style.display = __edPrescritorIsNovoCadastro ? 'none' : 'block';
    if (titleEl) {
        titleEl.innerHTML = __edPrescritorIsNovoCadastro
            ? '<i class="fas fa-user-plus" style="margin-right:8px; color:#0EA5E9;"></i>Novo prescritor'
            : '<i class="fas fa-user-edit" style="margin-right:8px; color:var(--primary);"></i>Editar prescritor';
    }
    if (saveBtn) {
        saveBtn.innerHTML = __edPrescritorIsNovoCadastro
            ? '<i class="fas fa-check" style="margin-right:6px;"></i>Cadastrar'
            : '<i class="fas fa-save" style="margin-right:6px;"></i>Salvar';
    }
    ['edPrescritorProfissao', 'edPrescritorEspecialidade', 'edPrescritorRegistro', 'edPrescritorUfRegistro', 'edPrescritorDataNascimento', 'edPrescritorCep', 'edPrescritorRua', 'edPrescritorNumero', 'edPrescritorBairro', 'edPrescritorCidadeUf', 'edPrescritorCidade', 'edPrescritorUf', 'edPrescritorLocalAtendimento', 'edPrescritorWhatsapp', 'edPrescritorEmail'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.value = '';
        }
    });
    document.getElementById('modalEditarPrescritor').style.display = 'flex';
    bindEditarPrescritorCepBlur();
    try {
        var reqProf = __visitadorPrescritoresMetaCache.profissoes
            ? Promise.resolve({ items: __visitadorPrescritoresMetaCache.profissoes })
            : apiGet('list_profissoes', {});
        var reqEsp = __visitadorPrescritoresMetaCache.especialidades
            ? Promise.resolve({ items: __visitadorPrescritoresMetaCache.especialidades })
            : apiGet('list_especialidades', {});
        var reqDados = Promise.resolve({ dados: {} });
        if (!__edPrescritorIsNovoCadastro && nomeNormalizado) {
            reqDados = apiGet('get_prescritor_dados', { nome_prescritor: nomeNormalizado, include_kpi: 0 });
        }
        var settled = await Promise.all([reqDados, reqProf, reqEsp]);
        var res = settled[0];
        var resP = settled[1];
        var resE = settled[2];
        var d = (res && res.dados) ? res.dados : {};
        var profissoes = (resP && resP.items) ? resP.items : [];
        var especialidades = (resE && resE.items) ? resE.items : [];
        if (!__visitadorPrescritoresMetaCache.profissoes && profissoes.length) {
            __visitadorPrescritoresMetaCache.profissoes = profissoes.slice();
        }
        if (!__visitadorPrescritoresMetaCache.especialidades && especialidades.length) {
            __visitadorPrescritoresMetaCache.especialidades = especialidades.slice();
        }
        fillSelectFromList(document.getElementById('edPrescritorProfissao'), profissoes, d.profissao);
        fillSelectFromList(document.getElementById('edPrescritorEspecialidade'), especialidades, d.especialidade);
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
        var whatsEl = document.getElementById('edPrescritorWhatsapp');
        whatsEl.value = (d.whatsapp || '').replace(/\D/g, '');
        if (typeof formatWhatsApp === 'function') {
            formatWhatsApp(whatsEl);
        }
        document.getElementById('edPrescritorEmail').value = d.email || '';
    } catch (e) {
        console.warn('list_profissoes / list_especialidades / get_prescritor_dados', e);
    }
    var cepVal = (document.getElementById('edPrescritorCep') || {}).value || '';
    var cepDigits = cepVal.replace(/\D/g, '');
    if (cepDigits.length === 8) {
        formatCepEditarPrescritor(document.getElementById('edPrescritorCep'));
        fetchCepAndFillEditarPrescritor(cepDigits);
    }
}

function closeEditarPrescritorModal() {
    document.getElementById('modalEditarPrescritor').style.display = 'none';
    __edPrescritorIsNovoCadastro = false;
}

async function openAprovadosRecusadosModal(nome, tipo) {
    var modal = document.getElementById('modalAprovadosRecusados');
    var titleEl = document.getElementById('modalAprovadosRecusadosTitle');
    var bodyEl = document.getElementById('modalAprovadosRecusadosBody');
    if (!modal || !titleEl || !bodyEl) {
        return;
    }
    var anoEl = document.getElementById('anoSelect');
    var mesEl = document.getElementById('mesSelect');
    var ano = anoEl ? anoEl.value : (new Date().getFullYear());
    var mes = mesEl && mesEl.value !== '' ? mesEl.value : '';
    var label = tipo === 'aprovados' ? 'Aprovados' : 'Reprovados';
    var periodo = mes ? (mes + '/' + ano) : ('' + ano);
    titleEl.textContent = 'Lista de ' + label.toLowerCase() + ' — ' + (nome || '') + ' (' + periodo + ')';
    bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin" style="color:var(--primary);"></i> Carregando pedidos...</div>';
    modal.style.display = 'flex';
    try {
        var visitadorNome = (typeof currentVisitadorName !== 'undefined' && currentVisitadorName) ? currentVisitadorName : (localStorage.getItem('userName') || '');
        var params = { nome: visitadorNome, ano: ano, prescritor: nome || '' };
        if (mes) params.mes = mes;
        var res = await apiGet('list_pedidos_visitador', params);
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

        if (!Array.isArray(base) || base.length === 0) {
            bodyEl.innerHTML = '<div style="text-align:center; padding:26px; color:var(--text-secondary);">Nenhum pedido ' + esc(label.toLowerCase()) + ' para este prescritor no período.</div>';
            return;
        }

        var statusBadge = function (tp) {
            var ok = tp === 'aprovados';
            var bg = ok ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)';
            var cor = ok ? '#10B981' : '#EF4444';
            var txt = ok ? 'Aprovado' : 'Reprovado';
            return '<span style="display:inline-block; padding:4px 8px; border-radius:8px; font-size:0.74rem; font-weight:700; background:' + bg + '; color:' + cor + ';">' + txt + '</span>';
        };

        var renderRows = function (list) {
            return list.map(function (p, idx) {
                var n = parseInt(p.numero_pedido || 0, 10);
                var s = parseInt(p.serie_pedido || 0, 10);
                return '<tr style="border-bottom:1px solid var(--border); cursor:pointer;" title="Ver detalhes e componentes" onclick="openModalDetalhePedido(' + n + ',' + s + ',\'' + esc(ano).replace(/'/g, "\\'") + '\')">' +
                    '<td style="padding:10px 12px;">' + (idx + 1) + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.numero_pedido || '—') + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.serie_pedido || '—') + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(fmtDate(p.data_aprovacao || p.data_orcamento || '')) + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.prescritor || '—') + '</td>' +
                    '<td style="padding:10px 12px;">' + esc(p.cliente || '—') + '</td>' +
                    '<td style="padding:10px 12px; text-align:right; font-weight:700; color:' + (tipo === 'aprovados' ? 'var(--success)' : 'var(--danger)') + ';">' + esc(fmtMoney(p.valor)) + '</td>' +
                    '<td style="padding:10px 12px;">' + statusBadge(tipo) + '</td>' +
                '</tr>';
            }).join('');
        };

        bodyEl.innerHTML =
            '<div style="display:flex; flex-direction:column; gap:10px;">' +
                '<input type="text" id="aprovRecSearchInput" placeholder="Buscar por prescritor, cliente ou número do pedido..." ' +
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
                        '<tbody id="aprovRecTableBody">' + renderRows(base) + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';

        var inp = document.getElementById('aprovRecSearchInput');
        var tbody = document.getElementById('aprovRecTableBody');
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

async function openRelatorioVisitasPrescritorModal(nome) {
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
        var res = await apiGet('get_visitas_prescritor', { prescritor: nome || '', visitador: typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '' });
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
            var hid = v.id ? parseInt(v.id, 10) : 0;
            html += '<div class="relatorio-visita-row" data-historicoid="' + hid + '" style="padding:12px 14px; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; cursor:pointer; background:var(--bg-body); transition:background 0.15s;" onmouseover="this.style.background=\'rgba(14,165,233,0.1)\'" onmouseout="this.style.background=\'var(--bg-body)\'"><span style="font-weight:600; color:var(--text-primary);">' + (dataStr ? new Date(dataStr + 'T12:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }) : '—') + (horaStr ? ' às ' + horaStr : '') + '</span><span style="margin-left:8px; font-size:0.85rem; color:var(--text-secondary);">' + (status || '') + '</span></div>';
        });
        bodyEl.innerHTML = html;
        bodyEl.querySelectorAll('.relatorio-visita-row').forEach(function (row) {
            row.onclick = async function () {
                var id = parseInt(row.getAttribute('data-historicoid'), 10);
                if (!id) {
                    return;
                }
                try {
                    var visitador = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
                    var det = await apiGet('get_detalhe_visita', { historico_id: id, visitador: visitador });
                    if (det && det.success && det.visita && typeof openDetalheVisitaModal === 'function') {
                        closeRelatorioVisitasPrescritorModal();
                        openDetalheVisitaModal(det.visita);
                    }
                } catch (e) {}
            };
        });
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

function formatCepEditarPrescritor(input) {
    if (!input) {
        return;
    }
    var v = (input.value || '').replace(/\D/g, '');
    if (v.length > 8) {
        v = v.slice(0, 8);
    }
    if (v.length > 5) {
        input.value = v.slice(0, 5) + '-' + v.slice(5);
    } else {
        input.value = v;
    }
}

function fetchCepAndFillEditarPrescritor(cepDigits) {
    cepDigits = String(cepDigits || '').replace(/\D/g, '');
    if (cepDigits.length !== 8) {
        return;
    }
    fetch('https://viacep.com.br/ws/' + cepDigits + '/json/')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.erro) {
                return;
            }
            var rua = document.getElementById('edPrescritorRua');
            var bairro = document.getElementById('edPrescritorBairro');
            var cidade = document.getElementById('edPrescritorCidade');
            var uf = document.getElementById('edPrescritorUf');
            var cidadeUf = document.getElementById('edPrescritorCidadeUf');
            if (rua) {
                rua.value = (data && data.logradouro) || '';
            }
            if (bairro) {
                bairro.value = (data && data.bairro) || '';
            }
            if (cidade) {
                cidade.value = (data && data.localidade) || '';
            }
            if (uf) {
                uf.value = (data && data.uf) || '';
            }
            if (cidadeUf) {
                cidadeUf.value = [data && data.localidade, data && data.uf].filter(Boolean).join(' - ') || '';
            }
        })
        .catch(function () {});
}

function bindEditarPrescritorCepBlur() {
    var cepEl = document.getElementById('edPrescritorCep');
    if (!cepEl) {
        return;
    }
    if (!cepEl._edPrescritorCepBound) {
        cepEl._edPrescritorCepBound = true;
        cepEl.addEventListener('input', function () {
            formatCepEditarPrescritor(cepEl);
            var cep = (cepEl.value || '').replace(/\D/g, '');
            if (cep.length === 8) {
                fetchCepAndFillEditarPrescritor(cep);
            }
        });
        cepEl.addEventListener('blur', function () {
            var cep = (cepEl.value || '').replace(/\D/g, '');
            if (cep.length === 8) {
                fetchCepAndFillEditarPrescritor(cep);
            }
        });
    }
}

async function saveEditarPrescritorModal() {
    var nomeInput = document.getElementById('edPrescritorNomeInput');
    var nomeHidden = document.getElementById('edPrescritorNome');
    var nome = ((nomeInput && nomeInput.value) || (nomeHidden && nomeHidden.value) || '').trim();
    if (!nome) {
        alert('Informe o nome do prescritor.');
        if (nomeInput) nomeInput.focus();
        return;
    }
    if (nomeHidden) nomeHidden.value = nome;
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
    if (__edPrescritorIsNovoCadastro) {
        var requiredFields = [
            { id: 'edPrescritorNomeInput', key: 'nome_prescritor', label: 'Nome do prescritor' },
            { id: 'edPrescritorProfissao', key: 'profissao', label: 'Profissão' },
            { id: 'edPrescritorEspecialidade', key: 'especialidade', label: 'Especialidade' },
            { id: 'edPrescritorRegistro', key: 'registro', label: 'Registro' },
            { id: 'edPrescritorUfRegistro', key: 'uf_registro', label: 'UF Registro' },
            { id: 'edPrescritorDataNascimento', key: 'data_nascimento', label: 'Data de nascimento' },
            { id: 'edPrescritorCep', key: 'endereco_cep', label: 'CEP' },
            { id: 'edPrescritorRua', key: 'endereco_rua', label: 'Rua' },
            { id: 'edPrescritorNumero', key: 'endereco_numero', label: 'Número' },
            { id: 'edPrescritorBairro', key: 'endereco_bairro', label: 'Bairro' },
            { id: 'edPrescritorCidade', key: 'endereco_cidade', label: 'Cidade' },
            { id: 'edPrescritorUf', key: 'endereco_uf', label: 'UF' },
            { id: 'edPrescritorLocalAtendimento', key: 'local_atendimento', label: 'Local de atendimento' },
            { id: 'edPrescritorWhatsapp', key: 'whatsapp', label: 'WhatsApp' },
            { id: 'edPrescritorEmail', key: 'email', label: 'E-mail' }
        ];
        requiredFields.forEach(function (f) {
            var el = document.getElementById(f.id);
            if (el) el.style.borderColor = 'var(--border)';
        });
        var missing = [];
        var firstMissingEl = null;
        requiredFields.forEach(function (f) {
            var raw = f.key === 'nome_prescritor' ? nome : payload[f.key];
            var value = (raw == null ? '' : String(raw)).trim();
            if (!value) {
                missing.push(f.label);
                var el = document.getElementById(f.id);
                if (el) {
                    el.style.borderColor = 'var(--danger)';
                    if (!firstMissingEl) firstMissingEl = el;
                }
            }
        });
        var whatsappDigits = String(payload.whatsapp || '').replace(/\D/g, '');
        if (payload.whatsapp && whatsappDigits.length < 10) {
            missing.push('WhatsApp válido');
            var wEl = document.getElementById('edPrescritorWhatsapp');
            if (wEl) {
                wEl.style.borderColor = 'var(--danger)';
                if (!firstMissingEl) firstMissingEl = wEl;
            }
        }
        if (payload.endereco_cep && String(payload.endereco_cep).replace(/\D/g, '').length !== 8) {
            missing.push('CEP válido');
            var cepEl = document.getElementById('edPrescritorCep');
            if (cepEl) {
                cepEl.style.borderColor = 'var(--danger)';
                if (!firstMissingEl) firstMissingEl = cepEl;
            }
        }
        if (payload.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(payload.email).trim())) {
            missing.push('E-mail válido');
            var emailEl = document.getElementById('edPrescritorEmail');
            if (emailEl) {
                emailEl.style.borderColor = 'var(--danger)';
                if (!firstMissingEl) firstMissingEl = emailEl;
            }
        }
        if (missing.length) {
            alert('Preencha todos os campos obrigatórios para cadastrar o prescritor.');
            if (firstMissingEl && typeof firstMissingEl.focus === 'function') firstMissingEl.focus();
            return;
        }
    }
    var btn = document.getElementById('btnSaveEditarPrescritor');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = __edPrescritorIsNovoCadastro
            ? '<i class="fas fa-spinner fa-spin"></i> Cadastrando...'
            : '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    try {
        if (__edPrescritorIsNovoCadastro) {
            var visitador = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
            var addRes = await apiPost('add_prescritor', { nome_prescritor: nome, visitador: String(visitador || '').trim() });
            if (!addRes || !addRes.success) {
                alert('Erro: ' + (addRes && addRes.error ? addRes.error : 'Não foi possível cadastrar o prescritor na carteira.'));
                return;
            }
        }
        var result = await apiPost('update_prescritor_dados', payload);
        if (result && result.success) {
            if (typeof __paginaPrescritoresList !== 'undefined') {
                var p = __paginaPrescritoresList.find(function (x) { return (x.prescritor || '') === nome; });
                if (p) {
                    p.whatsapp = payload.whatsapp || p.whatsapp;
                    p.profissao = payload.profissao || p.profissao;
                }
            }
            if (typeof loadAgendaMes === 'function' && typeof __agendaAno !== 'undefined' && typeof __agendaMes !== 'undefined') {
                await loadAgendaMes(__agendaAno, __agendaMes);
            }
            if (typeof loadPaginaPrescritores === 'function') {
                await loadPaginaPrescritores();
            }
            if (typeof renderPaginaPrescritores === 'function') {
                renderPaginaPrescritores();
            }
            closeEditarPrescritorModal();
        } else {
            alert('Erro: ' + (result && result.error ? result.error : 'Não foi possível salvar.'));
        }
    } catch (err) {
        alert('Erro de conexão.');
    }
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = __edPrescritorIsNovoCadastro
            ? '<i class="fas fa-check" style="margin-right:6px;"></i>Cadastrar'
            : '<i class="fas fa-save" style="margin-right:6px;"></i>Salvar';
    }
}
