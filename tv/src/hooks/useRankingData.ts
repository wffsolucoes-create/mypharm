import { useQuery } from '@tanstack/react-query';
import { fetchRanking } from '../services/api';
import type { SellerRecord } from '../types/ranking';

export function useRankingData(pollingInterval = 30000) {
  return useQuery<SellerRecord[], Error>({
    queryKey: ['ranking'],
    queryFn: fetchRanking,
    refetchInterval: pollingInterval, // fallback caso SSE caia
    staleTime: 0,                     // invalida imediatamente quando SSE notifica
  });
}
