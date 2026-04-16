import { motion, AnimatePresence } from 'framer-motion';
import { X, Trophy, TrendingUp, DollarSign, Target } from 'lucide-react';
import type { SellerRecord } from '@/types/ranking';
import { AnimatedCounter } from '../Shared/AnimatedCounter';

interface SellerDetailsModalProps {
  seller: SellerRecord | null;
  onClose: () => void;
}

export function SellerDetailsModal({ seller, onClose }: SellerDetailsModalProps) {
  return (
    <AnimatePresence>
      {seller && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <motion.div 
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="absolute inset-0 bg-black/80 backdrop-blur-sm cursor-pointer"
          />
          
          <motion.div
            initial={{ opacity: 0, scale: 0.9, y: 20 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.9, y: 20 }}
            transition={{ type: 'spring', bounce: 0.2 }}
            className="relative bg-[#0a1035] border border-gray-800/50 rounded-2xl shadow-2xl p-6 w-full max-w-lg z-10 overflow-hidden ring-1 ring-white/5"
          >
            {/* Glow de fundo */}
            <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[300px] h-[150px] bg-primary/10 blur-[80px] rounded-full pointer-events-none" />

            <div className="relative flex justify-between items-start mb-6">
              <div className="flex gap-4 items-center">
                <div className="relative">
                  <img 
                    src={seller.foto || `https://ui-avatars.com/api/?name=${encodeURIComponent(seller.nome)}&background=random`} 
                    alt={seller.nome}
                    className="w-20 h-20 rounded-full border-4 border-primary/40 object-cover shadow-[0_0_20px_rgba(0,229,255,0.2)]"
                  />
                  {seller.posicao_atual <= 3 && (
                    <div className="absolute -top-2 -right-2 w-8 h-8 bg-gradient-to-br from-yellow-400 to-amber-600 rounded-full flex items-center justify-center shadow-lg">
                      <Trophy size={14} className="text-white" fill="currentColor" />
                    </div>
                  )}
                </div>
                <div>
                  <h2 className="text-2xl font-black text-white">{seller.nome}</h2>
                  <p className="text-primary font-medium text-sm">{seller.equipe}</p>
                  <p className="text-xs text-gray-500 mt-0.5">#{seller.posicao_atual} no ranking</p>
                </div>
              </div>
              <button onClick={onClose} className="p-2 bg-gray-800/60 hover:bg-gray-700 rounded-full text-gray-400 hover:text-white transition-colors">
                <X size={18} />
              </button>
            </div>

            <div className="relative grid grid-cols-2 gap-3 mb-5">
              <div className="bg-[#020617]/80 border border-gray-800/40 rounded-xl p-4 flex flex-col ring-1 ring-white/5">
                <span className="text-gray-400 text-xs flex items-center gap-1 mb-1"><Trophy size={12}/> Posição</span>
                <span className="text-3xl font-black text-white">{seller.posicao_atual}º</span>
                <span className={`text-xs mt-1 font-medium ${seller.posicao_atual < seller.posicao_anterior ? 'text-green-400' : seller.posicao_atual > seller.posicao_anterior ? 'text-red-400' : 'text-gray-500'}`}>
                  {seller.posicao_atual < seller.posicao_anterior 
                    ? `↑ Subiu de ${seller.posicao_anterior}º` 
                    : seller.posicao_atual > seller.posicao_anterior 
                      ? `↓ Desceu de ${seller.posicao_anterior}º`
                      : 'Manteve posição'}
                </span>
              </div>
              
              <div className="bg-[#020617]/80 border border-gray-800/40 rounded-xl p-4 flex flex-col ring-1 ring-white/5">
                <span className="text-gray-400 text-xs flex items-center gap-1 mb-1"><TrendingUp size={12}/> Vendas</span>
                <span className="text-3xl font-black text-white">{seller.vendas_qtd}</span>
                <span className="text-xs text-gray-500 mt-1">negociações ganhas</span>
              </div>

              <div className="bg-[#020617]/80 border border-gray-800/40 rounded-xl p-4 flex flex-col col-span-2 ring-1 ring-white/5">
                <div className="flex justify-between items-center mb-2">
                  <span className="text-gray-400 text-xs flex items-center gap-1"><DollarSign size={12}/> Receita Total</span>
                  <AnimatedCounter value={seller.percentual_meta} format="percentage" className={`text-sm font-black ${seller.percentual_meta >= 100 ? 'text-primary' : 'text-accent'}`} />
                </div>
                <AnimatedCounter value={seller.vendas_valor} format="currency" className="text-3xl font-black text-white mb-3" />
                
                <div>
                  <div className="flex justify-between text-[11px] text-gray-500 mb-1.5">
                    <span className="flex items-center gap-1"><Target size={10} /> Progresso da Meta</span>
                    <span>{new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(seller.meta_valor)}</span>
                  </div>
                  <div className="w-full bg-[#020617] rounded-full h-2.5 overflow-hidden ring-1 ring-white/5">
                    <motion.div 
                      className={`h-full rounded-full ${seller.percentual_meta >= 100 ? 'bg-primary shadow-[0_0_12px_#00e5ff]' : 'bg-accent shadow-[0_0_12px_#ff8c00]'}`}
                      initial={{ width: 0 }}
                      animate={{ width: `${Math.min(seller.percentual_meta, 100)}%` }}
                      transition={{ duration: 1.2, ease: 'easeOut' }}
                    />
                  </div>
                </div>
              </div>
            </div>

            <div className="text-center text-[10px] text-gray-600 tracking-wider uppercase">
              Última atualização: {seller.ultima_atualizacao}
            </div>
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
}
