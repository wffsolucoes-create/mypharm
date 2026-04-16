export interface SellerRecord {
  id: number;
  nome: string;
  foto: string;
  equipe: string;
  vendas_qtd: number;
  vendas_valor: number;
  pontuacao: number;
  meta_valor: number;
  percentual_meta: number;
  posicao_atual: number;
  posicao_anterior: number;
  ultima_atualizacao: string;
}

export interface DealRecord {
  id: string;
  titulo: string;
  cliente: string;
  organizacao: string;
  vendedora: string;
  vendedora_crm: string;
  vendedora_foto: string;
  valor: number;
  data: string;
  data_iso: string;
  rating: number;
  status: string;
  stage: string;
}

export interface DealsResponse {
  deals: DealRecord[];
  total: number;
  valor_total: number;
  periodo: {
    inicio: string;
    fim: string;
  };
  atualizado_em: string;
}

