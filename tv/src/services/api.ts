import type { SellerRecord, DealsResponse } from "../types/ranking";
import { mockRankingData } from "../mocks/rankingData";

// BASE_URL é definido pelo Vite com base no `base` do vite.config.ts
// Ex: /mypharm/ranking/ no XAMPP local, / na Hostinger
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

export const fetchDeals = async (seller?: string): Promise<DealsResponse> => {
  const params = new URLSearchParams();
  if (seller) {
    params.set('seller', seller);
  }

  const baseUrl = `${API_URL}deals.php`;
  const url = params.toString() ? `${baseUrl}?${params}` : baseUrl;

  const response = await fetch(url);
  if (!response.ok) {
    throw new Error('Falha ao buscar dados de vendas');
  }
  return response.json();
};

export const fetchDivergencias = async (): Promise<any> => {
  const response = await fetch(`${API_URL}divergencias.php`);
  if (!response.ok) {
    throw new Error('Falha ao buscar auditoria de divergencias');
  }
  return response.json();
};
