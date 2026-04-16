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
  const isTop3 = rank <= 3;

  const rankColor = {
    1: 'from-yellow-500 to-amber-400',
    2: 'from-gray-400 to-slate-300',
    3: 'from-orange-500 to-orange-300',
  }[rank] || 'from-blue-600 to-cyan-400';

  return (
    <motion.li
      layout
      initial={{ opacity: 0, x: 50 }}
      animate={{ opacity: 1, x: 0 }}
      exit={{ opacity: 0, scale: 0.95 }}
      transition={{ duration: 0.4, type: 'spring', bounce: 0.2 }}
      onClick={onClick}
      whileHover={{ scale: 1.02, y: -4 }}
      className={`
        relative group cursor-pointer
        bg-gradient-to-br from-slate-800/50 to-slate-900/50
        border rounded-2xl overflow-hidden
        shadow-lg hover:shadow-xl transition-all duration-300
        backdrop-blur-md
        ${isGoalReached ? 'border-primary/60 shadow-neon-primary' : 'border-gray-700/60 hover:border-gray-600/80'}
        ${isTop3 ? 'ring-1 ring-accent/30' : ''}
      `}
    >
      {/* Animated Background Gradient */}
      <div className="absolute inset-0 bg-gradient-premium opacity-0 group-hover:opacity-100 transition-opacity duration-500" />

      {/* Conteúdo */}
      <div className="relative p-4 lg:p-5 flex items-center gap-3 lg:gap-4">

        {/* Rank Badge */}
        <motion.div
          animate={isTop3 ? { scale: [1, 1.1, 1] } : {}}
          transition={{ duration: 2, repeat: Infinity }}
          className={`
            flex items-center justify-center min-w-[3rem] min-h-[3rem]
            rounded-xl font-black text-white text-lg
            ${isTop3 ? `bg-gradient-to-br ${rankColor} shadow-lg` : 'bg-slate-700/50 border border-gray-600/50'}
            relative
          `}
        >
          {rank}
          {isTop3 && (
            <motion.div
              animate={{ scale: [0.8, 1.2, 0.8] }}
              transition={{ duration: 2, repeat: Infinity }}
              className="absolute inset-0 rounded-xl border-2 border-white/30"
            />
          )}
        </motion.div>

        {/* Avatar com Glow */}
        <div className="relative flex-shrink-0">
          <div className={`
            w-12 h-12 lg:w-14 lg:h-14 rounded-full
            border-2 overflow-hidden
            ${isGoalReached ? 'border-primary/60 shadow-neon-primary' : 'border-gray-600/60'}
            transition-all duration-300
          `}>
            <img
              src={seller.foto || `https://ui-avatars.com/api/?name=${encodeURIComponent(seller.nome)}&background=${isGoalReached ? '10b981' : 'random'}&color=fff&bold=true&size=128`}
              alt={seller.nome}
              className="w-full h-full object-cover"
            />
          </div>
          {isGoalReached && (
            <motion.div
              animate={{ rotate: 360 }}
              transition={{ duration: 3, repeat: Infinity, ease: 'linear' }}
              className="absolute inset-0 rounded-full border-2 border-transparent border-t-success border-r-success opacity-60"
            />
          )}
        </div>

        {/* Info e Stats */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 mb-1">
            <h4 className="font-bold text-sm lg:text-base text-white truncate">
              {seller.nome}
            </h4>
            {isGoalReached && (
              <motion.div
                animate={{ scale: [1, 1.2, 1] }}
                transition={{ duration: 2, repeat: Infinity }}
              >
                <span className="text-xs px-2 py-0.5 rounded-full bg-success/20 text-success font-bold">
                  ✓ Meta
                </span>
              </motion.div>
            )}
          </div>
          <p className="text-xs text-gray-400 truncate">{seller.equipe}</p>

          {/* Progress Bar */}
          <div className="hidden sm:block mt-2">
            <div className="flex justify-between text-xs text-gray-400 mb-1.5">
              <span className="font-medium">{seller.vendas_qtd} vendas</span>
              <span>{seller.meta_valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 })}</span>
            </div>
            <div className="w-full h-2 bg-slate-700/60 rounded-full overflow-hidden ring-1 ring-white/5 relative">
              <motion.div
                className={`
                  h-full transition-all duration-500
                  ${isGoalReached
                    ? 'bg-gradient-to-r from-success to-emerald-400 shadow-neon-success'
                    : 'bg-gradient-to-r from-primary to-cyan-400'
                  }
                `}
                initial={{ width: 0 }}
                animate={{ width: `${Math.min(seller.percentual_meta, 100)}%` }}
                transition={{ duration: 1, ease: 'easeOut' }}
              />
            </div>
          </div>
        </div>

        {/* Movimento */}
        <div className="flex-shrink-0">
          <MovementIndicator
            posicaoAtual={seller.posicao_atual}
            posicaoAnterior={seller.posicao_anterior}
          />
        </div>

        {/* Percentual com Destaque */}
        <motion.div
          className="flex flex-col items-end gap-1"
          whileHover={{ scale: 1.05 }}
        >
          <div className={`
            text-xl lg:text-2xl font-black tabular-nums
            ${isGoalReached ? 'text-success drop-shadow-lg' : 'text-white'}
          `}>
            <AnimatedCounter
              value={seller.percentual_meta}
              format="percentage"
            />
          </div>
          <span className="text-xs font-semibold uppercase tracking-wider text-gray-500">
            da meta
          </span>
        </motion.div>
      </div>

      {/* Shine Effect */}
      {isGoalReached && (
        <motion.div
          animate={{ opacity: [0.3, 0.6, 0.3] }}
          transition={{ duration: 2, repeat: Infinity }}
          className="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 -skew-x-12"
        />
      )}
    </motion.li>
  );
}
