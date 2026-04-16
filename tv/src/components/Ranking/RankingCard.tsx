import { motion } from 'framer-motion';
import type { SellerRecord } from '@/types/ranking';
import { AnimatedCounter } from '../Shared/AnimatedCounter';
import { MovementIndicator } from '../Shared/MovementIndicator';

interface RankingCardProps {
  seller: SellerRecord;
  rank: number;
  onClick?: () => void;
}

export function RankingCard({ seller, rank, onClick }: RankingCardProps) {
  const isGoalReached = seller.percentual_meta >= 100;

  const rankColor = {
    1: 'from-yellow-500 to-amber-400',
    2: 'from-gray-400 to-slate-300',
    3: 'from-orange-500 to-orange-300',
  }[rank] || 'from-blue-600 to-cyan-400';

  return (
    <motion.li
      layout
      initial={{ opacity: 0, x: 30 }}
      animate={{ opacity: 1, x: 0 }}
      exit={{ opacity: 0, scale: 0.95 }}
      transition={{ duration: 0.3, type: 'spring', bounce: 0.2 }}
      onClick={onClick}
      className={`
        relative group cursor-pointer
        bg-gradient-to-br from-slate-800/50 to-slate-900/50
        border rounded-xl overflow-hidden
        shadow-md backdrop-blur-md
        ${isGoalReached ? 'border-primary/60 shadow-neon-primary' : 'border-gray-700/60'}
      `}
    >
      {/* Conteúdo — compacto para TV */}
      <div className="relative px-3 py-2.5 flex items-center gap-2.5">

        {/* Rank Badge */}
        <div
          className={`
            flex items-center justify-center w-9 h-9 shrink-0
            rounded-lg font-black text-white text-sm
            ${rank <= 3 ? `bg-gradient-to-br ${rankColor} shadow-md` : 'bg-slate-700/50 border border-gray-600/50'}
          `}
        >
          {rank}
        </div>

        {/* Avatar */}
        <div className="shrink-0">
          <div className={`
            w-10 h-10 rounded-full border-2 overflow-hidden
            ${isGoalReached ? 'border-primary/60' : 'border-gray-600/60'}
          `}>
            <img
              src={seller.foto || `https://ui-avatars.com/api/?name=${encodeURIComponent(seller.nome)}&background=${isGoalReached ? '10b981' : 'random'}&color=fff&bold=true&size=96`}
              alt={seller.nome}
              className="w-full h-full object-cover"
            />
          </div>
        </div>

        {/* Info */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-1.5">
            <h4 className="font-bold text-xs text-white truncate">{seller.nome}</h4>
            {isGoalReached && (
              <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-success/20 text-success font-bold shrink-0">
                ✓ Meta
              </span>
            )}
          </div>
          <p className="text-[10px] text-gray-400 truncate">{seller.equipe} · {seller.vendas_qtd} vendas</p>

          {/* Progress Bar — compacta */}
          <div className="mt-1.5 w-full h-1.5 bg-slate-700/60 rounded-full overflow-hidden">
            <motion.div
              className={`h-full ${isGoalReached ? 'bg-gradient-to-r from-success to-emerald-400' : 'bg-gradient-to-r from-primary to-cyan-400'}`}
              initial={{ width: 0 }}
              animate={{ width: `${Math.min(seller.percentual_meta, 100)}%` }}
              transition={{ duration: 0.8, ease: 'easeOut' }}
            />
          </div>
        </div>

        {/* Movimento */}
        <div className="shrink-0">
          <MovementIndicator
            posicaoAtual={seller.posicao_atual}
            posicaoAnterior={seller.posicao_anterior}
          />
        </div>

        {/* Percentual */}
        <div className="flex flex-col items-end shrink-0 min-w-[50px]">
          <div className={`text-base font-black tabular-nums ${isGoalReached ? 'text-success' : 'text-white'}`}>
            <AnimatedCounter value={seller.percentual_meta} format="percentage" />
          </div>
          <span className="text-[9px] font-semibold uppercase tracking-wider text-gray-500">da meta</span>
        </div>
      </div>
    </motion.li>
  );
}
