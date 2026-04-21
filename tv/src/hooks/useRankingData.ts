import { useQuery } from '@tanstack/react-query';
import { fetchRanking } from '../services/api';
import type { SellerRecord } from '../types/ranking';

export function useRankingData(pollingInterval = 15000) {
  return useQuery<SellerRecord[], Error>({
    queryKey: ['ranking'],
    queryFn: fetchRanking,
    refetchInterval: pollingInterval, // Atualização automática
    staleTime: 5000,
  });
}
