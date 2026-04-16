import { ArrowUp, ArrowDown, Minus } from 'lucide-react';
import { motion } from 'framer-motion';

interface MovementIndicatorProps {
  posicaoAtual: number;
  posicaoAnterior: number;
}

export function MovementIndicator({ posicaoAtual, posicaoAnterior }: MovementIndicatorProps) {
  const movimento = posicaoAnterior - posicaoAtual; // positivo = subiu, negativo = desceu

  if (movimento === 0) {
    return <Minus className="w-5 h-5 text-gray-500" />;
  }

  if (movimento > 0) {
    // Subiu
    return (
      <motion.div
        initial={{ scale: 0, rotate: -180 }}
        animate={{ scale: 1, rotate: 0 }}
        transition={{ type: 'spring', stiffness: 200 }}
      >
        <div className="flex items-center gap-1 text-success">
          <ArrowUp className="w-5 h-5" />
          <span className="text-xs font-bold">{movimento}</span>
        </div>
      </motion.div>
    );
  }

  // Desceu
  return (
    <motion.div
      initial={{ scale: 0, rotate: 180 }}
      animate={{ scale: 1, rotate: 0 }}
      transition={{ type: 'spring', stiffness: 200 }}
    >
      <div className="flex items-center gap-1 text-danger">
        <ArrowDown className="w-5 h-5" />
        <span className="text-xs font-bold">{Math.abs(movimento)}</span>
      </div>
    </motion.div>
  );
}
