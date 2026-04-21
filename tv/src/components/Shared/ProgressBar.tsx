import { motion } from "framer-motion";

interface ProgressBarProps {
  percentage: number;
}

export function ProgressBar({ percentage }: ProgressBarProps) {
  // Garantir que a porcentagem fique entre 0 e 100
  const clampedPercentage = Math.min(Math.max(percentage, 0), 100);
  
  // Cor muda baseada no quão perto da meta (opcional, pode ser estático dependendo do tema)
  const isGoalReached = clampedPercentage >= 100;

  return (
    <div className="w-full bg-surface border border-gray-800 rounded-full h-2.5 overflow-hidden">
      <motion.div
        initial={{ width: 0 }}
        animate={{ width: `${clampedPercentage}%` }}
        transition={{ duration: 1, ease: "easeOut" }}
        className={`h-2.5 rounded-full ${isGoalReached ? 'bg-green-500' : 'bg-primary'}`}
      />
    </div>
  );
}
