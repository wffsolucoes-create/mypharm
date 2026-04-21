import type { SellerRecord } from "../types/ranking";
import { mockRankingData } from "../mocks/rankingData";

const API_URL = import.meta.env.VITE_API_URL || `${import.meta.env.BASE_URL}api/`;
const USE_MOCKS = import.meta.env.VITE_USE_MOCKS === 'true';

export const fetchRanking = async (): Promise<SellerRecord[]> => {
  if (USE_MOCKS) {
    await new Promise(resolve => setTimeout(resolve, 600));
    return mockRankingData;
  }

  const response = await fetch(API_URL);
  if (!response.ok) {
    throw new Error('Falha ao buscar dados do ranking');
  }
  return response.json();
};
