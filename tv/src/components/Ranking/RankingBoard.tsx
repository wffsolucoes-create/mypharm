import { useEffect, useRef, useState } from "react";
import { useRankingData } from "@/hooks/useRankingData";
import { useSSE } from "@/hooks/useSSE";
import { RankingCard } from "./RankingCard";
import { AnimatePresence } from "framer-motion";
import { useAudio } from "@/hooks/useAudio";
import confetti from "canvas-confetti";
import type { SellerRecord } from "@/types/ranking";
import { SellerDetailsModal } from "./SellerDetailsModal";
import { Podium } from "./Podium";
import { OvertakeNotification } from "../Effects/OvertakeNotification";

export function RankingBoard() {
  const { data: ranking, isLoading, isError } = useRankingData();
  useSSE(); // conexão SSE — atualização instantânea via webhook
  const { playSound } = useAudio();
  const [selectedSeller, setSelectedSeller] = useState<SellerRecord | null>(null);
  const [notification, setNotification] = useState<{ seller: string; message: string; type: 'overtake' | 'goal' | 'champion' } | null>(null);

  // Carrega imagem de premiação do localStorage
  const prizeImage = localStorage.getItem('ranking_prize_image') || '';

  const prevRankingRef = useRef<SellerRecord[] | null>(null);

  useEffect(() => {
    if (!ranking || ranking.length === 0) return;

    if (prevRankingRef.current) {
      // Detecta alguém virou campeão (#1)
      const newChampion = ranking.find(seller => {
        const prevSeller = prevRankingRef.current?.find(s => s.id === seller.id);
        return seller.posicao_atual === 1 && prevSeller && prevSeller.posicao_atual !== 1;
      });

      if (newChampion) {
        playSound('champion');
        setNotification({ seller: newChampion.nome, message: '🏆 NOVO CAMPEÃO!', type: 'champion' });
        confetti({
          particleCount: 200,
          spread: 90,
          origin: { y: 0.5 },
          colors: ['#fbbf24', '#f59e0b', '#fcd34d', '#ffffff']
        });
      }

      // Detecta ultrapassagem (alguém subiu)
      const overtakers = ranking.filter(seller => {
        const prevSeller = prevRankingRef.current?.find(s => s.id === seller.id);
        return prevSeller && seller.posicao_atual < prevSeller.posicao_atual && seller.posicao_atual <= 10;
      });

      if (overtakers.length > 0 && !newChampion) {
        const overtaker = overtakers[0];
        playSound('overtake');
        setNotification({
          seller: overtaker.nome,
          message: '⚡ ULTRAPASSAGEM!',
          type: 'overtake'
        });
      }

      // Detecta meta atingida
      const goalReachers = ranking.filter(seller => {
        const prevSeller = prevRankingRef.current?.find(s => s.id === seller.id);
        return prevSeller && seller.percentual_meta >= 100 && prevSeller.percentual_meta < 100;
      });

      if (goalReachers.length > 0 && !newChampion && overtakers.length === 0) {
        const goaler = goalReachers[0];
        playSound('goal');
        setNotification({ seller: goaler.nome, message: '🎯 META ATINGIDA!', type: 'goal' });
        confetti({
          particleCount: 120,
          spread: 60,
          origin: { y: 0.6 },
          colors: ['#10b981', '#34d399', '#6ee7b7', '#ffffff']
        });
      }
    }

    prevRankingRef.current = ranking;
  }, [ranking, playSound]);

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
        <p className="mt-4 text-gray-400 text-lg">Carregando dados brilhantes...</p>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] text-red-500">
        <p className="text-lg">Ops! Falha ao carregar o ranking.</p>
        <p className="text-sm text-gray-400">Tentando conectar à API novamente...</p>
      </div>
    );
  }

  if (!ranking || ranking.length === 0) {
    return <div className="text-center text-gray-500 p-8">Nenhum dado encontrado.</div>;
  }

  const hiddenSellers: string[] = JSON.parse(localStorage.getItem('ranking_hidden_sellers') || '[]');

  const sortedRanking = [...ranking]
    .filter(s => !hiddenSellers.includes(s.nome))
    .sort((a, b) => a.posicao_atual - b.posicao_atual);

  const top3 = sortedRanking.slice(0, 3);
  const remaining = sortedRanking.slice(3, 100);

  return (
    <div className="w-full h-screen overflow-hidden py-3 px-4 flex flex-col">

      {/* ===== LAYOUT PRINCIPAL: 2 COLUNAS ===== */}
      <div className="flex-1 grid grid-cols-12 gap-4 min-h-0">

        {/* COLUNA ESQUERDA — Pódio */}
        <div className="col-span-5 flex flex-col items-center justify-center">
          <Podium top3={top3} onClickSeller={setSelectedSeller} prizeImage={prizeImage} />
        </div>

        {/* COLUNA DIREITA — Classificação Geral */}
        <div className="col-span-7 flex flex-col min-h-0">
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-gray-300 font-semibold text-sm tracking-wider uppercase">
              Classificação Geral
            </h3>
            <span className="text-xs bg-gradient-to-r from-primary/20 to-accent/20 px-3 py-1 rounded-full border border-primary/30 text-gray-300">
              {remaining.length} Vendedores
            </span>
          </div>

          {/* Grid 2 colunas para caber todos */}
          <div className="flex-1 min-h-0 overflow-hidden">
            <ul className="grid grid-cols-2 gap-2 h-full content-start auto-rows-min">
              <AnimatePresence mode="popLayout">
                {remaining.map((seller) => (
                  <RankingCard
                    key={seller.id}
                    seller={seller}
                    rank={seller.posicao_atual}
                    onClick={() => setSelectedSeller(seller)}
                  />
                ))}
              </AnimatePresence>
            </ul>
          </div>
        </div>
      </div>

      <SellerDetailsModal seller={selectedSeller} onClose={() => setSelectedSeller(null)} />

      {/* Notificação de Eventos */}
      {notification && (
        <OvertakeNotification
          seller={notification.seller}
          message={notification.message}
          type={notification.type}
        />
      )}
    </div>
  );
}
