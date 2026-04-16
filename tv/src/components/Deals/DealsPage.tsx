import { useState, useMemo } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useDealsData } from '@/hooks/useDealsData';
import {
  ShoppingBag,
  DollarSign,
  Star,
  Search,
  ChevronDown,
  ChevronUp,
  RefreshCw,
  User,
  Building2,
  Calendar,
  Filter,
  X,
  TrendingUp,
  ArrowLeft,
  Clock,
  AlertTriangle,
} from 'lucide-react';
import { fetchDivergencias } from '@/services/api';
import type { DealRecord } from '@/types/ranking';

interface TotalsAPI {
  divergencias: Array<{
    deal_id: string;
    cliente: string;
    vendedora: string;
    phusion_val: number;
    rd_val: number;
    diff: number;
    is_naming_error?: boolean;
    rd_cliente?: string;
    phusion_ids?: string[];
  }>;
  unmatched_phusion: Array<{
    cliente: string;
    vendedora: string;
    phusion_val: number;
    ids: string[];
  }>;
  unmatched_rd: Array<{
    deal_id: string;
    cliente: string;
    vendedora: string;
    rd_val: number;
    titulo: string;
  }>;
  total_phusion: number;
  total_rd: number;
  phusion_raw?: Array<{
    pedido_id: string;
    data_aprovacao: string;
    cliente: string;
    vendedora: string;
    valor: number;
  }>;
  rd_raw?: Array<DealRecord>;
  debug: any;
}

// ----------------------------------------------------------------
// Componente: Card de estatísticas do topo
// ----------------------------------------------------------------
function StatCard({
  icon: Icon,
  label,
  value,
  sub,
  color,
  delay = 0,
}: {
  icon: React.ComponentType<{ size?: number; className?: string }>;
  label: string;
  value: string;
  sub?: string;
  color: string;
  delay?: number;
}) {
  const colorMap: Record<string, { bg: string; border: string; text: string; glow: string }> = {
    cyan: {
      bg: 'bg-cyan-500/10',
      border: 'border-cyan-500/30',
      text: 'text-cyan-400',
      glow: 'shadow-cyan-500/10',
    },
    amber: {
      bg: 'bg-amber-500/10',
      border: 'border-amber-500/30',
      text: 'text-amber-400',
      glow: 'shadow-amber-500/10',
    },
    emerald: {
      bg: 'bg-emerald-500/10',
      border: 'border-emerald-500/30',
      text: 'text-emerald-400',
      glow: 'shadow-emerald-500/10',
    },
    violet: {
      bg: 'bg-violet-500/10',
      border: 'border-violet-500/30',
      text: 'text-violet-400',
      glow: 'shadow-violet-500/10',
    },
    rose: {
      bg: 'bg-rose-500/10',
      border: 'border-rose-500/30',
      text: 'text-rose-400',
      glow: 'shadow-rose-500/10',
    },
  };
  const c = colorMap[color] ?? colorMap.cyan;

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay }}
      className={`relative overflow-hidden rounded-2xl border ${c.border} ${c.bg} backdrop-blur-lg p-5 shadow-xl ${c.glow} ring-1 ring-white/5`}
    >
      <div className="absolute -top-6 -right-6 w-24 h-24 rounded-full bg-white/5 blur-2xl" />
      <div className="relative flex items-start justify-between">
        <div>
          <p className="text-xs text-gray-400 uppercase tracking-wider font-semibold mb-1">{label}</p>
          <p className={`text-2xl lg:text-3xl font-black ${c.text}`}>{value}</p>
          {sub && <p className="text-xs text-gray-500 mt-1">{sub}</p>}
        </div>
        <div className={`w-10 h-10 rounded-xl ${c.bg} border ${c.border} flex items-center justify-center`}>
          <Icon size={20} className={c.text} />
        </div>
      </div>
    </motion.div>
  );
}

// ----------------------------------------------------------------
// Componente: Card de deal individual
// ----------------------------------------------------------------
function DealCard({ deal, index, divergencia, isPhusionOnly, isUnmatchedRD }: { deal: DealRecord; index: number; divergencia?: any; isPhusionOnly?: boolean; isUnmatchedRD?: boolean }) {
  const [expanded, setExpanded] = useState(false);

  const formatCurrency = (v: number) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);

  const isDivergent = !!divergencia;

  let rowClassName = 'bg-[#0a1035]/80 border-gray-800/40 hover:border-cyan-500/30 hover:shadow-cyan-500/5';
  let badgeClasses = 'bg-emerald-500/15 border-emerald-500/30 text-emerald-400';
  let badgeDot = 'bg-emerald-400';
  let badgeText = deal.status;
  let sideGradient = 'from-emerald-500 to-emerald-600';
  let titleColor = 'text-white group-hover:text-cyan-300';

  if (isPhusionOnly) {
    rowClassName = 'bg-yellow-900/40 border-yellow-500/40 hover:border-yellow-400/60 shadow-yellow-500/10';
    badgeClasses = 'bg-yellow-500/15 border-yellow-500/50 text-yellow-400';
    badgeDot = 'bg-yellow-400';
    badgeText = 'NÃO CADASTRADO NO RD';
    sideGradient = 'from-yellow-500 to-amber-600';
    titleColor = 'text-yellow-100 group-hover:text-white';
  } else if (isUnmatchedRD) {
    rowClassName = 'bg-orange-900/40 border-orange-500/40 hover:border-orange-400/60 shadow-orange-500/10';
    badgeClasses = 'bg-orange-500/15 border-orange-500/50 text-orange-400';
    badgeDot = 'bg-orange-400';
    badgeText = 'FANTASMA RD';
    sideGradient = 'from-orange-500 to-amber-600';
    titleColor = 'text-orange-100 group-hover:text-white';
  } else if (isDivergent) {
    if (divergencia.is_naming_error) {
      rowClassName = 'bg-blue-900/40 border-blue-500/40 hover:border-blue-400/60 shadow-blue-500/10';
      badgeClasses = 'bg-blue-500/15 border-blue-500/50 text-blue-400';
      badgeDot = 'bg-blue-400';
      badgeText = 'ERRO DE NOMENCLATURA';
      sideGradient = 'from-blue-500 to-cyan-600';
      titleColor = 'text-blue-100 group-hover:text-white';
    } else {
      rowClassName = 'bg-[#2a0815]/90 border-red-500/40 hover:border-red-400/60 shadow-red-500/10';
      badgeClasses = 'bg-red-500/15 border-red-500/50 text-red-400';
      badgeDot = 'bg-red-400';
      badgeText = 'COM DIVERGÊNCIA';
      sideGradient = 'from-red-500 to-rose-600';
      titleColor = 'text-red-100 group-hover:text-white';
    }
  }

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, scale: 0.95 }}
      transition={{ duration: 0.35, delay: Math.min(index * 0.03, 0.6) }}
      className={`group relative border rounded-xl overflow-hidden transition-all duration-300 shadow-lg ring-1 ring-white/5 ${rowClassName}`}
    >
      <div className={`absolute left-0 top-0 bottom-0 w-1 rounded-l-xl bg-gradient-to-b ${sideGradient}`} />

      <div
        className="flex items-center gap-4 p-4 pl-5 cursor-pointer select-none"
        onClick={() => setExpanded(!expanded)}
      >
        <div className={`flex items-center gap-1.5 border px-2.5 py-1 rounded-lg text-xs font-bold uppercase tracking-wider whitespace-nowrap ${badgeClasses}`}>
          <div className={`w-2 h-2 rounded-full flex-shrink-0 ${badgeDot}`} />
          {badgeText}
        </div>

        <div className="flex-1 min-w-0">
          <h3 className={`font-bold text-sm lg:text-base truncate transition-colors ${titleColor}`}>
            {deal.titulo}
          </h3>
          <p className="text-xs text-gray-500 truncate mt-0.5">
            {deal.organizacao || deal.cliente}
          </p>
        </div>

        {deal.rating > 0 && (
          <div className="hidden sm:flex items-center gap-0.5">
            {Array.from({ length: deal.rating }).map((_, i) => (
              <Star key={i} size={12} className="text-amber-400 fill-amber-400" />
            ))}
          </div>
        )}

        <div className="hidden md:flex items-center gap-2 min-w-[120px]">
          {deal.vendedora_foto ? (
            <img
              src={deal.vendedora_foto}
              alt={deal.vendedora}
              className="w-7 h-7 rounded-full border border-gray-700 object-cover"
            />
          ) : (
            <div className="w-7 h-7 rounded-full border border-gray-700 bg-gray-800 flex items-center justify-center">
              <User size={14} className="text-gray-500" />
            </div>
          )}
          <span className="text-xs text-gray-400 font-medium truncate">{deal.vendedora}</span>
        </div>

        <div className="text-right min-w-[120px]">
          {isDivergent && divergencia ? (
            <>
              <p className="font-bold text-gray-500 text-xs lg:text-sm line-through">
                RD: {formatCurrency(deal.valor)}
              </p>
              <p className="font-black text-red-400 text-sm lg:text-base">
                Real: {formatCurrency(divergencia.phusion_val)}
              </p>
            </>
          ) : (
            <p className="font-black text-white text-sm lg:text-base">{formatCurrency(deal.valor)}</p>
          )}
        </div>

        <button className="p-1 text-gray-600 hover:text-gray-300 transition-colors ml-1">
          {expanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
        </button>
      </div>

      <AnimatePresence>
        {expanded && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.25 }}
            className="overflow-hidden"
          >
            <div className="px-5 pb-4 pt-1 border-t border-gray-800/30 grid grid-cols-2 sm:grid-cols-4 gap-4">
              <div className="flex items-center gap-2">
                <User size={14} className="text-cyan-500" />
                <div>
                  <p className="text-[10px] text-gray-500 uppercase tracking-wider">Cliente</p>
                  <p className="text-xs text-gray-300 font-medium">{deal.cliente}</p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <Building2 size={14} className="text-violet-500" />
                <div>
                  <p className="text-[10px] text-gray-500 uppercase tracking-wider">Organização</p>
                  <p className="text-xs text-gray-300 font-medium">{deal.organizacao || '—'}</p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <Calendar size={14} className="text-amber-500" />
                <div>
                  <p className="text-[10px] text-gray-500 uppercase tracking-wider">Fechamento</p>
                  <p className="text-xs text-gray-300 font-medium">{deal.data || '—'}</p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <ShoppingBag size={14} className="text-emerald-500" />
                <div>
                  <p className="text-[10px] text-gray-500 uppercase tracking-wider">Etapa</p>
                  <p className="text-xs text-gray-300 font-medium">{deal.stage}</p>
                </div>
              </div>
            </div>
            
            {isDivergent && divergencia && (
              <div className={`px-5 pb-4 ${divergencia.is_naming_error ? 'bg-blue-500/10 border-t border-blue-500/20' : 'bg-red-500/10 border-t border-red-500/20'}`}>
                <div className="pt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                  {divergencia.is_naming_error ? (
                    <>
                      <div className="w-full mb-1">
                         <p className="text-sm text-blue-200">Encontramos o valor correto para a mesma vendedora! Mas os nomes cadastrados estão diferentes.</p>
                      </div>
                      <div>
                        <p className="text-[10px] text-blue-400 uppercase tracking-wider font-bold">Lançado no RD</p>
                        <p className="text-sm text-blue-200 font-bold">{divergencia.rd_cliente || 'Desconhecido'}</p>
                      </div>
                      <div>
                        <p className="text-[10px] text-blue-400 uppercase tracking-wider font-bold">Lançado no Phusion</p>
                        <p className="text-sm text-blue-200 font-bold">{divergencia.cliente}</p>
                      </div>
                    </>
                  ) : (
                    <>
                      <div>
                        <p className="text-[10px] text-red-400 uppercase tracking-wider font-bold">Valor Correto (Phusion)</p>
                        <p className="text-sm text-red-200 font-bold">{formatCurrency(divergencia.phusion_val)}</p>
                      </div>
                      <div>
                        <p className="text-[10px] text-red-400 uppercase tracking-wider font-bold">Valor Lançado (RD)</p>
                        <p className="text-sm text-red-200 font-bold line-through">{formatCurrency(divergencia.rd_val)}</p>
                      </div>
                      <div>
                        <p className="text-[10px] text-red-500 uppercase tracking-wider font-black">Diferença Encontrada</p>
                        <p className="text-sm text-red-500 font-black">{formatCurrency(divergencia.diff)}</p>
                      </div>
                      {divergencia.phusion_ids && divergencia.phusion_ids.length > 0 && (
                        <div className="w-full mt-2 pt-2 border-t border-red-500/10">
                          <p className="text-[10px] text-red-400 uppercase tracking-wider font-bold">Pedidos no Phusion (Planilha)</p>
                          <p className="text-xs text-red-200 mt-0.5">IDs: <span className="font-mono font-bold bg-red-900/40 px-2 py-0.5 rounded border border-red-500/20">{divergencia.phusion_ids.join(', ')}</span></p>
                        </div>
                      )}
                    </>
                  )}
                </div>
              </div>
            )}

            {isPhusionOnly && (
               <div className="px-5 pb-4 bg-yellow-500/10 border-t border-yellow-500/20">
                 <div className="pt-3">
                   <p className="text-sm text-yellow-200 font-medium">⚠️ Este pedido consta na planilha do Phusion (ERP), mas <strong>NÃO foi cadastrado no RD Station CRM</strong>. Precisa ser lançado no CRM para conciliar.</p>
                 </div>
               </div>
            )}

            {isUnmatchedRD && (
               <div className="px-5 pb-4 bg-orange-500/10 border-t border-orange-500/20">
                 <div className="pt-3">
                   <p className="text-sm text-orange-200 font-medium">Esta venda foi ganha no RD Station, mas o valor/paciente não foi localizado na linha da planilha Phusion correspondente.</p>
                 </div>
               </div>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </motion.div>
  );
}

// ----------------------------------------------------------------
// Componente principal: Página de Vendas
// ----------------------------------------------------------------
export function DealsPage() {
  const [searchQuery, setSearchQuery] = useState('');
  const [sellerFilter, setSellerFilter] = useState('');
  const [showFilters, setShowFilters] = useState(false);
  const [sortBy, setSortBy] = useState<'data' | 'valor' | 'vendedora' | 'cliente'>('data');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [totalsAPI, setTotalsAPI] = useState<TotalsAPI | null>(null);
  const [onlyDivergences, setOnlyDivergences] = useState(false);
  const [activeTab, setActiveTab] = useState<'audit' | 'rd' | 'phusion'>('audit');

  const { data, isLoading, isError, refetch, dataUpdatedAt } = useDealsData();

  useMemo(() => {
    fetchDivergencias()
      .then((d) => {
        setTotalsAPI(d);
      })
      .catch((err) => console.log('Sem auditoria hoje', err));
  }, [dataUpdatedAt]);

  const sellers = useMemo(() => {
    if (!data) return [];
    const unique = [...new Set(data.deals.map((d) => d.vendedora))];
    return unique.sort();
  }, [data]);

  const filteredDeals = useMemo(() => {
    if (!data) return [];
    let deals = [...data.deals];

    if (onlyDivergences) {
      deals = deals.filter(d => 
        totalsAPI?.divergencias?.some(div => String(div.deal_id) === String(d.id)) ||
        totalsAPI?.unmatched_rd?.some(u => String(u.deal_id) === String(d.id))
      );
    }

    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      deals = deals.filter(
        (d) =>
          d.titulo.toLowerCase().includes(q) ||
          d.cliente.toLowerCase().includes(q) ||
          d.organizacao.toLowerCase().includes(q)
      );
    }

    if (sellerFilter) {
      deals = deals.filter((d) => d.vendedora === sellerFilter);
    }

    deals.sort((a, b) => {
      let cmp = 0;
      if (sortBy === 'valor') cmp = a.valor - b.valor;
      else if (sortBy === 'vendedora') cmp = a.vendedora.localeCompare(b.vendedora);
      else if (sortBy === 'cliente') cmp = a.cliente.localeCompare(b.cliente);
      else cmp = a.data_iso.localeCompare(b.data_iso);
      return sortDir === 'desc' ? -cmp : cmp;
    });

    return deals;
  }, [data, searchQuery, sellerFilter, sortBy, sortDir, onlyDivergences, totalsAPI]);

  const displayDeals = useMemo(() => {
    let virtualDeals: DealRecord[] = [];
    if (totalsAPI?.unmatched_phusion) {
      virtualDeals = totalsAPI.unmatched_phusion.map((p, idx) => ({
        id: `virtual-phusion-${idx}`,
        titulo: `[SÓ PLANILHA] ID(s): ${p.ids && p.ids.length > 0 ? p.ids.join(', ') : 'N/A'}`,
        cliente: p.cliente,
        organizacao: 'Fantasma no Phusion',
        vendedora: p.vendedora || 'Desconhecida',
        vendedora_crm: '',
        vendedora_foto: '',
        valor: p.phusion_val,
        data: 'Ver Planilha',
        data_iso: '9999-12-31',
        rating: 0,
        status: 'Phusion Only',
        stage: 'Planilha Phusion',
      }));
    }
    
    // Filtros visuais na virtual deals se tiver texto
    if (searchQuery.trim() && !onlyDivergences) {
       const q = searchQuery.toLowerCase();
       virtualDeals = virtualDeals.filter(d => d.cliente.toLowerCase().includes(q) || d.titulo.toLowerCase().includes(q));
    }
    if (sellerFilter) {
       // Normaliza acentos para comparação (CSV tem 'Clara Leticia', RD tem 'Clara Letícia')
       const normalize = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
       const filterNorm = normalize(sellerFilter);
       
       virtualDeals = virtualDeals.filter(d => {
         const vendNorm = normalize(d.vendedora);
         // Bidirectional match to handle 'Carla' vs 'Carla Pires' and vice-versa
         return vendNorm.includes(filterNorm) || filterNorm.includes(vendNorm);
       });
    }

    return [...filteredDeals, ...virtualDeals];
  }, [filteredDeals, totalsAPI, sellerFilter, searchQuery, onlyDivergences]);

  const formatCurrency = (v: number) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);

  const lastUpdated = dataUpdatedAt ? new Date(dataUpdatedAt).toLocaleTimeString('pt-BR') : '';


  return (
    <div className="min-h-screen bg-background text-white font-sans">
      <div className="fixed inset-0 pointer-events-none">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0c1445] via-background to-background" />
        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] bg-primary/5 blur-[120px] rounded-full" />
      </div>

      <main className="relative z-10">
        <header className="p-4 lg:px-8 lg:py-5 border-b border-gray-800/30 bg-[#020617]/60 backdrop-blur-xl sticky top-0 z-20">
          <div className="max-w-[1600px] mx-auto flex items-center justify-between">
            <div className="flex items-center gap-4">
              <a href="/" className="p-2 rounded-xl bg-gray-800/50 hover:bg-gray-700/50 text-gray-400 hover:text-white transition-colors border border-gray-700/30">
                <ArrowLeft size={18} />
              </a>
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-cyan-500 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                  <ShoppingBag size={20} className="text-white" />
                </div>
                <div>
                  <h1 className="text-xl lg:text-2xl font-black tracking-tight text-white">Pedidos Aprovados</h1>
                  <p className="text-[11px] tracking-widest uppercase text-gray-500 font-medium -mt-0.5">Vendas do RD Station CRM</p>
                </div>
              </div>
            </div>
            <div className="flex items-center gap-3">
              {lastUpdated && <div className="hidden sm:flex items-center gap-2 text-xs text-gray-500"><Clock size={12} /> Atualizado às {lastUpdated}</div>}
              <button onClick={() => refetch()} className="p-2.5 rounded-xl bg-gray-800/50 hover:bg-gray-700/50 text-gray-400 hover:text-white transition-all border border-gray-700/30 hover:border-gray-600/50">
                <RefreshCw size={16} className={isLoading ? 'animate-spin' : ''} />
              </button>
            </div>
          </div>
        </header>

        <div className="max-w-[1600px] mx-auto px-4 lg:px-8 py-6">
          {isLoading && !data && (
            <div className="flex flex-col items-center justify-center min-h-[400px]">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
              <p className="mt-4 text-gray-400 text-lg">Buscando vendas do CRM...</p>
            </div>
          )}

          {isError && (
             <div className="flex flex-col items-center justify-center min-h-[400px] text-red-500">
               <p className="text-lg">Ops! Falha ao carregar as vendas.</p>
               <p className="text-sm text-gray-400 mt-1">Verifique a conexão com a API.</p>
               <button onClick={() => refetch()} className="mt-4 px-4 py-2 bg-cyan-500/20 border border-cyan-500/30 text-cyan-400 rounded-lg hover:bg-cyan-500/30 transition-colors text-sm font-medium">Tentar novamente</button>
             </div>
          )}

          {data && (
            <>
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                <StatCard icon={ShoppingBag} label="Vendas Filtradas" value={String(displayDeals.length)} color="cyan" />
                <StatCard 
                  icon={DollarSign} 
                  label="Receita RD" 
                  value={formatCurrency(
                    displayDeals.reduce((acc, deal) => acc + (deal.status !== 'Phusion Only' ? deal.valor : 0), 0)
                  )} 
                  color="emerald" 
                />
                <StatCard 
                  icon={DollarSign} 
                  label="Receita Phusion" 
                  value={formatCurrency(
                    displayDeals.reduce((acc, deal) => {
                      if (deal.status === 'Phusion Only') return acc + deal.valor;
                      const divInfo = totalsAPI?.divergencias?.find((d) => String(d.deal_id) === String(deal.id));
                      if (divInfo) return acc + divInfo.phusion_val;
                      const isUnmatchedRD = totalsAPI?.unmatched_rd?.some((u) => String(u.deal_id) === String(deal.id));
                      if (isUnmatchedRD) return acc + 0;
                      return acc + deal.valor; // Matched exactly
                    }, 0)
                  )} 
                  color="emerald" 
                />
                <StatCard 
                  icon={TrendingUp} 
                  label="Ticket Médio RD" 
                  value={formatCurrency(
                    displayDeals.filter(d => d.status !== 'Phusion Only').length > 0 
                      ? displayDeals.reduce((acc, deal) => acc + (deal.status !== 'Phusion Only' ? deal.valor : 0), 0) / displayDeals.filter(d => d.status !== 'Phusion Only').length 
                      : 0
                  )} 
                  color="amber" 
                />
                {totalsAPI && (
                  <StatCard 
                    icon={AlertTriangle} 
                    label="Divergência Total" 
                    value={formatCurrency(
                      displayDeals.reduce((acc, deal) => {
                        const divInfo = totalsAPI?.divergencias?.find((d) => String(d.deal_id) === String(deal.id));
                        if (divInfo) return acc + divInfo.diff;
                        if (deal.status === 'Phusion Only') return acc + deal.valor;
                        const isUnmatchedRD = totalsAPI?.unmatched_rd?.some((u) => String(u.deal_id) === String(deal.id));
                        if (isUnmatchedRD) return acc + deal.valor;
                        return acc;
                      }, 0)
                    )} 
                    color="rose" 
                  />
                )}
              </div>

              {/* Informações Visuais */}
              {totalsAPI && (
                <div className="flex flex-wrap gap-4 mb-4 p-4 border border-gray-700/50 bg-gray-900/50 rounded-xl text-sm">
                  <span className="flex items-center gap-2 text-red-400 font-medium">
                    <div className="w-3 h-3 rounded-full bg-red-400 shadow-[0_0_8px_rgba(239,68,68,0.6)]"></div> Erro de Valor (RD ⇄ Phusion)
                  </span>
                  <span className="flex items-center gap-2 text-blue-400 font-medium">
                    <div className="w-3 h-3 rounded-full bg-blue-400 shadow-[0_0_8px_rgba(56,189,248,0.6)]"></div> Erro de Nomenclatura (Nomes diferentes, valores iguais)
                  </span>
                  <span className="flex items-center gap-2 text-orange-400 font-medium">
                    <div className="w-3 h-3 rounded-full bg-orange-400 shadow-[0_0_8px_rgba(249,115,22,0.6)]"></div> Fantasma no RD (Sumiu no CRM)
                  </span>
                  <span className="flex items-center gap-2 text-yellow-400 font-medium">
                    <div className="w-3 h-3 rounded-full bg-yellow-400 shadow-[0_0_8px_rgba(234,179,8,0.6)]"></div> Não cadastrado no RD (Só na Planilha)
                  </span>
                </div>
              )}

              <div className="flex flex-col sm:flex-row gap-3 mb-6">
                <div className="relative flex-1">
                  <Search size={16} className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500" />
                  <input type="text" placeholder="Buscar por deal, cliente ou organização..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} className="w-full bg-[#0a1035]/80 border border-gray-800/40 rounded-xl pl-11 pr-4 py-3 text-sm text-gray-200 placeholder:text-gray-600 focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/20 transition-colors ring-1 ring-white/5" />
                  {searchQuery && (
                    <button onClick={() => setSearchQuery('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300"><X size={14} /></button>
                  )}
                </div>
                <button onClick={() => setShowFilters(!showFilters)} className={`flex items-center gap-2 px-4 py-3 rounded-xl text-sm font-medium border transition-colors ${showFilters || sellerFilter ? 'bg-cyan-500/15 border-cyan-500/40 text-cyan-400' : 'bg-[#0a1035]/80 border-gray-800/40 text-gray-400 hover:text-gray-200'} ring-1 ring-white/5`}>
                  <Filter size={16} /> Filtros
                  {sellerFilter && <span className="w-2 h-2 rounded-full bg-cyan-400" />}
                </button>
              </div>

              <AnimatePresence>
                {showFilters && (
                  <motion.div initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} transition={{ duration: 0.2 }} className="overflow-hidden mb-6">
                    <div className="bg-[#0a1035]/60 border border-gray-800/40 rounded-xl p-4 flex flex-wrap gap-4 items-center ring-1 ring-white/5">
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-500 uppercase tracking-wider font-semibold">Vendedora:</span>
                        <button onClick={() => setSellerFilter('')} className={`px-3 py-1.5 rounded-lg text-xs font-medium border ${!sellerFilter ? 'bg-cyan-500/20 border-cyan-500/40 text-cyan-400' : 'bg-gray-800/30 border-gray-700/30 text-gray-400 hover:text-gray-200'}`}>Todas</button>
                        {sellers.map((s) => (
                           <button key={s} onClick={() => setSellerFilter(sellerFilter === s ? '' : s)} className={`px-3 py-1.5 rounded-lg text-xs font-medium border ${sellerFilter === s ? 'bg-cyan-500/20 border-cyan-500/40 text-cyan-400' : 'bg-gray-800/30 border-gray-700/30 text-gray-400 hover:text-gray-200'}`}>{s}</button>
                        ))}
                      </div>

                      {totalsAPI && totalsAPI.divergencias.length > 0 && (
                        <div className="flex items-center gap-2 border-l border-gray-800/50 pl-4 ml-2">
                          <span className="text-xs text-gray-500 uppercase tracking-wider font-semibold">Auditoria:</span>
                          <button onClick={() => setOnlyDivergences(!onlyDivergences)} className={`px-3 py-1.5 rounded-lg text-xs font-medium border flex items-center gap-1.5 ${onlyDivergences ? 'bg-rose-500/20 border-rose-500/40 text-rose-400' : 'bg-gray-800/30 border-gray-700/30 text-gray-400 hover:text-gray-200'}`}>
                            <AlertTriangle size={12} className={onlyDivergences ? 'text-rose-400' : 'text-gray-500'} />
                            Erros / Órfãos
                          </button>
                        </div>
                      )}

                      <div className="ml-auto flex items-center gap-2 border-l border-gray-800/50 pl-4">
                        <span className="text-xs text-gray-500 uppercase tracking-wider font-semibold">Ordenar:</span>
                        {(['data', 'valor', 'vendedora', 'cliente'] as const).map((field) => (
                          <button key={field} onClick={() => { if (sortBy === field) { setSortDir((d) => (d === 'asc' ? 'desc' : 'asc')); } else { setSortBy(field); setSortDir('desc'); } }} className={`px-3 py-1.5 rounded-lg text-xs font-medium border flex items-center gap-1 ${sortBy === field ? 'bg-cyan-500/20 border-cyan-500/40 text-cyan-400' : 'bg-gray-800/30 border-gray-700/30 text-gray-400 hover:text-gray-200'}`}>
                            {{ data: 'Data', valor: 'Valor', vendedora: 'Vendedora', cliente: 'Cliente' }[field]}
                            {sortBy === field && (<span className="text-[10px]">{sortDir === 'desc' ? '↓' : '↑'}</span>)}
                          </button>
                        ))}
                      </div>
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>

              {/* Tabs */}
              <div className="flex items-center gap-1 p-1 bg-gray-900/50 border border-gray-800/50 rounded-xl mb-6">
                 <button 
                   onClick={() => setActiveTab('audit')}
                   className={`flex-1 py-2.5 px-4 rounded-lg text-sm font-bold transition-all flex items-center justify-center gap-2 ${activeTab === 'audit' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30' : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800/30 border border-transparent'}`}
                 >
                   <AlertTriangle size={14} /> Auditoria Geral
                 </button>
                 <button 
                   onClick={() => setActiveTab('rd')}
                   className={`flex-1 py-2.5 px-4 rounded-lg text-sm font-bold transition-all flex items-center justify-center gap-2 ${activeTab === 'rd' ? 'bg-indigo-500/20 text-indigo-400 border border-indigo-500/30' : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800/30 border border-transparent'}`}
                 >
                   <ShoppingBag size={14} /> Dados RD Station
                 </button>
                 <button 
                   onClick={() => setActiveTab('phusion')}
                   className={`flex-1 py-2.5 px-4 rounded-lg text-sm font-bold transition-all flex items-center justify-center gap-2 ${activeTab === 'phusion' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800/30 border border-transparent'}`}
                 >
                   <Building2 size={14} /> Dados Phusion
                 </button>
              </div>

              {activeTab === 'audit' && (
                <>
                  <div className="flex items-center justify-between mb-4">
                    <p className="text-sm text-gray-500">
                      Exibindo <span className="text-gray-300 font-semibold">{displayDeals.length}</span> {displayDeals.length === 1 ? 'venda' : 'vendas'} {(searchQuery || sellerFilter) && <span className="text-gray-600"> de {data.total + (totalsAPI?.unmatched_phusion.length || 0)} total</span>}
                    </p>
                  </div>

                  <div className="flex flex-col gap-2">
                    <AnimatePresence mode="popLayout">
                      {displayDeals.map((deal, i) => {
                        const divInfo = totalsAPI?.divergencias?.find((d) => String(d.deal_id) === String(deal.id));
                        const isUnmatchedRD = totalsAPI?.unmatched_rd?.some((u) => String(u.deal_id) === String(deal.id));
                        const isPhusionOnly = deal.status === 'Phusion Only';

                        return <DealCard 
                          key={deal.id} 
                          deal={deal} 
                          index={i} 
                          divergencia={divInfo} 
                          isPhusionOnly={isPhusionOnly} 
                          isUnmatchedRD={isUnmatchedRD} 
                        />;
                      })}
                    </AnimatePresence>
                  </div>

                  {displayDeals.length === 0 && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="text-center py-16">
                      <Search size={40} className="text-gray-700 mx-auto mb-4" />
                      <p className="text-gray-500 text-lg">Nenhuma venda encontrada na auditoria</p>
                    </motion.div>
                  )}
                </>
              )}

              {activeTab === 'rd' && (
                <div className="overflow-x-auto rounded-xl border border-gray-800/40 bg-[#0a1035]/40 backdrop-blur-md">
                   <table className="w-full text-left text-sm">
                      <thead className="text-[10px] text-gray-500 uppercase tracking-widest bg-gray-900/50">
                         <tr>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Deal ID / Título</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Vendedora</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Cliente / Organização</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Data</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50 text-right">Valor RD</th>
                         </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-800/30">
                         {totalsAPI?.rd_raw
                           ?.filter(d => {
                              const q = searchQuery.toLowerCase();
                              const matchesSearch = !q || d.titulo.toLowerCase().includes(q) || d.cliente.toLowerCase().includes(q) || d.vendedora.toLowerCase().includes(q);
                              
                              const normalize = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                              const filterNorm = normalize(sellerFilter);
                              const vendedoraNorm = normalize(d.vendedora);
                              const matchesSeller = !sellerFilter || vendedoraNorm.includes(filterNorm) || filterNorm.includes(vendedoraNorm);

                              return matchesSearch && matchesSeller;
                           })
                           .sort((a, b) => {
                              let cmp = 0;
                              if (sortBy === 'valor') cmp = a.valor - b.valor;
                              else if (sortBy === 'vendedora') cmp = a.vendedora.localeCompare(b.vendedora);
                              else if (sortBy === 'cliente') cmp = a.cliente.localeCompare(b.cliente);
                              else cmp = (a.data_iso || '').localeCompare(b.data_iso || '');
                              return sortDir === 'desc' ? -cmp : cmp;
                           })
                           .map((rd, i) => (
                           <tr key={i} className="hover:bg-cyan-500/5 transition-colors group">
                              <td className="px-5 py-3">
                                 <div className="font-bold text-gray-200 group-hover:text-cyan-400 transition-colors">{rd.titulo}</div>
                                 <div className="text-[10px] text-gray-600 mt-0.5">#{rd.id}</div>
                              </td>
                              <td className="px-5 py-3">
                                 <span className="inline-flex items-center gap-1.5 text-xs text-gray-400">
                                    <User size={10} className="text-gray-600" /> {rd.vendedora}
                                 </span>
                              </td>
                              <td className="px-5 py-3">
                                 <div className="text-xs text-gray-300">{rd.cliente}</div>
                                 <div className="text-[10px] text-gray-600">{rd.organizacao || '—'}</div>
                              </td>
                              <td className="px-5 py-3 text-xs text-gray-500">
                                 {rd.data}
                              </td>
                              <td className="px-5 py-3 text-right">
                                 <span className="font-black text-indigo-400">{formatCurrency(rd.valor)}</span>
                              </td>
                           </tr>
                         ))}
                      </tbody>
                   </table>
                   {(!totalsAPI?.rd_raw || totalsAPI.rd_raw.length === 0) && (
                      <div className="p-10 text-center text-gray-600">Nenhum dado do RD encontrado.</div>
                   )}
                </div>
              )}

              {activeTab === 'phusion' && (
                <div className="overflow-x-auto rounded-xl border border-gray-800/40 bg-[#0a1035]/40 backdrop-blur-md">
                   <table className="w-full text-left text-sm">
                      <thead className="text-[10px] text-gray-500 uppercase tracking-widest bg-gray-900/50">
                         <tr>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Pedido ID</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Vendedora</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Cliente (Planilha)</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50">Data Orçamento</th>
                            <th className="px-5 py-4 font-bold border-b border-gray-800/50 text-right">Valor Phusion</th>
                         </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-800/30">
                         {totalsAPI?.phusion_raw
                           ?.filter(p => {
                              const q = searchQuery.toLowerCase();
                              const matchesSearch = !q || p.cliente.toLowerCase().includes(q) || p.vendedora.toLowerCase().includes(q) || p.pedido_id.toString().includes(q);
                              
                              const normalize = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                              const filterNorm = normalize(sellerFilter);
                              const vendedoraNorm = normalize(p.vendedora);
                              const matchesSeller = !sellerFilter || vendedoraNorm.includes(filterNorm) || filterNorm.includes(vendedoraNorm);
                              
                              return matchesSearch && matchesSeller;
                           })
                           .sort((a, b) => {
                              let cmp = 0;
                              if (sortBy === 'valor') cmp = a.valor - b.valor;
                              else if (sortBy === 'vendedora') cmp = a.vendedora.localeCompare(b.vendedora);
                              else if (sortBy === 'cliente') cmp = a.cliente.localeCompare(b.cliente);
                              else cmp = (a.data_aprovacao || '').localeCompare(b.data_aprovacao || '');
                              return sortDir === 'desc' ? -cmp : cmp;
                           })
                           .map((ph, i) => (
                           <tr key={i} className="hover:bg-amber-500/5 transition-colors group">
                              <td className="px-5 py-3">
                                 <div className="font-bold text-gray-200 group-hover:text-amber-400 transition-colors">{ph.pedido_id}</div>
                              </td>
                              <td className="px-5 py-3">
                                 <span className="inline-flex items-center gap-1.5 text-xs text-gray-400">
                                    <User size={10} className="text-gray-600" /> {ph.vendedora}
                                 </span>
                              </td>
                              <td className="px-5 py-3">
                                 <div className="text-xs text-gray-300 uppercase">{ph.cliente}</div>
                              </td>
                              <td className="px-5 py-3 text-xs text-gray-500 whitespace-nowrap">
                                 {ph.data_aprovacao ? new Date(ph.data_aprovacao).toLocaleDateString('pt-BR') : '—'}
                              </td>
                              <td className="px-5 py-3 text-right">
                                 <span className="font-black text-amber-400">{formatCurrency(ph.valor)}</span>
                              </td>
                           </tr>
                         ))}
                      </tbody>
                   </table>
                   {(!totalsAPI?.phusion_raw || totalsAPI.phusion_raw.length === 0) && (
                      <div className="p-10 text-center text-gray-600">Nenhum dado do Phusion encontrado.</div>
                   )}
                </div>
              )}
            </>
          )}
        </div>
      </main>
    </div>
  );
}
