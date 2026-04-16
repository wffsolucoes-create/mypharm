import { motion, AnimatePresence } from "framer-motion";
import type { SellerRecord } from "@/types/ranking";
import { Trophy } from "lucide-react";

interface PodiumProps {
  top3: SellerRecord[];
  onClickSeller: (seller: SellerRecord) => void;
  prizeImage?: string;
}

export function Podium({ top3, onClickSeller, prizeImage }: PodiumProps) {
  const first = top3[0];
  const second = top3[1];
  const third = top3[2];

  const PrizeIcon = () => prizeImage ? (
    <img src={prizeImage} alt="Premio" className="w-10 h-10 object-contain drop-shadow-[0_0_15px_rgba(250,204,21,0.8)]" />
  ) : (
    <Trophy size={32} fill="currentColor" className="drop-shadow-[0_0_10px_rgba(250,204,21,0.8)]" />
  );

  return (
    <div className="flex items-end justify-center w-full gap-3 px-2">
      <AnimatePresence mode="popLayout">
        {/* SEGUNDO LUGAR */}
        {second && (
          <motion.div
            key={`second-${second.id}`}
            layout
            initial={{ opacity: 0, y: 50 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.9 }}
            className="flex flex-col items-center relative w-[30%] cursor-pointer group"
            onClick={() => onClickSeller(second)}
          >
            <div className="flex flex-col items-center text-center mb-3">
              <img
                src={second.foto || `https://ui-avatars.com/api/?name=${encodeURIComponent(second.nome)}&background=random&size=128`}
                alt={second.nome}
                className="w-16 h-16 rounded-full border-3 border-gray-400 object-cover shadow-[0_0_15px_rgba(156,163,175,0.5)]"
              />
              <p className="text-white font-bold text-sm mt-2 truncate w-full max-w-[120px]">{second.nome}</p>
              <p className="text-gray-400 text-[10px] truncate max-w-[120px]">{second.equipe}</p>
            </div>
            <div className="w-full bg-gradient-to-t from-gray-700 via-gray-600 to-gray-500/40 border-t-3 border-gray-400 rounded-t-xl h-28 flex justify-center items-start pt-4 relative overflow-hidden">
              <div className="absolute inset-0 bg-gradient-to-b from-white/5 to-transparent rounded-t-xl" />
              <span className="text-4xl font-black text-gray-300 drop-shadow-md relative z-10">2</span>
            </div>
          </motion.div>
        )}

        {/* PRIMEIRO LUGAR */}
        {first && (
          <motion.div
            key={`first-${first.id}`}
            layout
            initial={{ opacity: 0, y: 50 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.9 }}
            className="flex flex-col items-center relative z-10 w-[40%] cursor-pointer group"
            onClick={() => onClickSeller(first)}
          >
            {/* Prêmio em cima */}
            <motion.div
              className="text-yellow-400 mb-1"
              animate={{ y: [0, -6, 0] }}
              transition={{ duration: 2.5, repeat: Infinity }}
            >
              <PrizeIcon />
            </motion.div>

            {/* Avatar */}
            <div className="flex flex-col items-center text-center mb-3">
              <img
                src={first.foto || `https://ui-avatars.com/api/?name=${encodeURIComponent(first.nome)}&background=random&size=128`}
                alt={first.nome}
                className="w-24 h-24 rounded-full border-4 border-primary object-cover shadow-[0_0_25px_rgba(59,130,246,0.6)]"
              />
              <p className="text-white font-black text-base mt-2 truncate w-full max-w-[150px] drop-shadow-md">{first.nome}</p>
              <p className="text-primary font-semibold text-xs truncate max-w-[150px]">{first.equipe}</p>
            </div>

            {/* Pódio - O MAIOR */}
            <div className="w-full bg-gradient-to-t from-primary/90 via-primary/50 to-primary/30 border-t-4 border-primary rounded-t-2xl h-44 flex justify-center items-start pt-6 shadow-[0_-10px_30px_rgba(59,130,246,0.3)] relative overflow-hidden">
              <div className="absolute inset-0 bg-gradient-to-b from-white/10 to-transparent rounded-t-2xl" />
              <span className="text-6xl font-black text-white drop-shadow-[0_0_15px_rgba(59,130,246,0.8)] relative z-10">1</span>
            </div>
          </motion.div>
        )}

        {/* TERCEIRO LUGAR */}
        {third && (
          <motion.div
            key={`third-${third.id}`}
            layout
            initial={{ opacity: 0, y: 50 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.9 }}
            className="flex flex-col items-center relative w-[30%] cursor-pointer group"
            onClick={() => onClickSeller(third)}
          >
            <div className="flex flex-col items-center text-center mb-3">
              <img
                src={third.foto || `https://ui-avatars.com/api/?name=${encodeURIComponent(third.nome)}&background=random&size=128`}
                alt={third.nome}
                className="w-14 h-14 rounded-full border-3 border-accent object-cover shadow-[0_0_15px_rgba(249,115,22,0.5)]"
              />
              <p className="text-white font-bold text-sm mt-2 truncate w-full max-w-[120px]">{third.nome}</p>
              <p className="text-gray-400 text-[10px] truncate max-w-[120px]">{third.equipe}</p>
            </div>
            <div className="w-full bg-gradient-to-t from-accent/80 via-accent/50 to-accent/30 border-t-3 border-accent rounded-t-xl h-20 flex justify-center items-start pt-3 relative overflow-hidden">
              <div className="absolute inset-0 bg-gradient-to-b from-white/5 to-transparent rounded-t-xl" />
              <span className="text-3xl font-black text-accent drop-shadow-md relative z-10">3</span>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
