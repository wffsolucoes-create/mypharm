import { useQuery } from '@tanstack/react-query';
import { fetchDeals } from '../services/api';
import type { DealsResponse } from '../types/ranking';

export function useDealsData(seller?: string, pollingInterval = 30000) {
  return useQuery<DealsResponse, Error>({
    queryKey: ['deals', seller],
    queryFn: () => fetchDeals(seller),
    refetchInterval: pollingInterval,
    staleTime: 10000,
  });
}
