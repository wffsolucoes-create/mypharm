import type { SellerRecord } from '../types/ranking';

export const mockRankingData: SellerRecord[] = [
  {
    id: 1,
    nome: "João Silva",
    foto: "https://i.pravatar.cc/150?u=joao",
    equipe: "Comercial Alpha",
    vendas_qtd: 25,
    vendas_valor: 18500.50,
    pontuacao: 250,
    meta_valor: 25000,
    percentual_meta: 74,
    posicao_atual: 2,
    posicao_anterior: 4,
    ultima_atualizacao: "2026-04-02 09:30:00"
  },
  {
    id: 2,
    nome: "Maria Oliveira",
    foto: "https://i.pravatar.cc/150?u=maria",
    equipe: "Comercial Beta",
    vendas_qtd: 32,
    vendas_valor: 24000.00,
    pontuacao: 320,
    meta_valor: 25000,
    percentual_meta: 96,
    posicao_atual: 1,
    posicao_anterior: 1,
    ultima_atualizacao: "2026-04-02 09:30:00"
  },
  {
    id: 3,
    nome: "Carlos Santos",
    foto: "https://i.pravatar.cc/150?u=carlos",
    equipe: "Comercial Alpha",
    vendas_qtd: 20,
    vendas_valor: 15000.00,
    pontuacao: 200,
    meta_valor: 25000,
    percentual_meta: 60,
    posicao_atual: 3,
    posicao_anterior: 2,
    ultima_atualizacao: "2026-04-02 09:30:00"
  },
  {
    id: 4,
    nome: "Ana Costa",
    foto: "https://i.pravatar.cc/150?u=ana",
    equipe: "Comercial Beta",
    vendas_qtd: 15,
    vendas_valor: 11000.00,
    pontuacao: 150,
    meta_valor: 25000,
    percentual_meta: 44,
    posicao_atual: 4,
    posicao_anterior: 3,
    ultima_atualizacao: "2026-04-02 09:30:00"
  }
];
