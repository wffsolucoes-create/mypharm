import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';

const SSE_URL = `${import.meta.env.VITE_API_URL || `${import.meta.env.BASE_URL}api/`}sse.php`;

/**
 * Mantém uma conexão SSE aberta com o servidor.
 * Quando o webhook do RD Station invalida o cache, o servidor envia
 * "event: update" e o React Query busca os dados novos instantaneamente.
 *
 * Reconecta automaticamente em caso de erro ou timeout do servidor.
 */
export function useSSE() {
  const queryClient = useQueryClient();

  useEffect(() => {
    let es: EventSource;
    let retryTimeout: ReturnType<typeof setTimeout>;

    function connect() {
      es = new EventSource(SSE_URL);

      // Servidor enviou notificação de novo deal ganho
      es.addEventListener('update', () => {
        queryClient.invalidateQueries({ queryKey: ['ranking'] });
      });

      // Servidor pediu reconexão (ciclo normal de ~50s)
      es.addEventListener('reconnect', () => {
        es.close();
        retryTimeout = setTimeout(connect, 1000);
      });

      // Erro de rede → tenta reconectar após 3s
      es.onerror = () => {
        es.close();
        retryTimeout = setTimeout(connect, 3000);
      };
    }

    connect();

    return () => {
      clearTimeout(retryTimeout);
      es?.close();
    };
  }, [queryClient]);
}
