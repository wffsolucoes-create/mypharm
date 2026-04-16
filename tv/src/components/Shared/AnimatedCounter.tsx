import { useEffect, useState } from "react";
import { motion, useSpring } from "framer-motion";

interface AnimatedCounterProps {
  value: number;
  format?: "currency" | "number" | "percentage";
  className?: string;
}

export function AnimatedCounter({ value, format = "number", className = "" }: AnimatedCounterProps) {
  // Configuração suave para o movimento dos números
  const spring = useSpring(value, { mass: 1, stiffness: 60, damping: 15 });
  const [displayValue, setDisplayValue] = useState("");

  // Sempre que o valor original mudar, disparamos a mola
  useEffect(() => {
    spring.set(value);
  }, [value, spring]);

  // Função para formatar de acordo com a prop
  const formatValue = (v: number) => {
    if (format === "currency") {
      return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);
    }
    if (format === "percentage") {
      return `${Math.round(v)}%`;
    }
    return new Intl.NumberFormat('pt-BR').format(Math.round(v));
  };

  // Observa mudanças do framer-motion a cada tick
  useEffect(() => {
    return spring.on("change", (latest) => {
      setDisplayValue(formatValue(latest));
    });
  }, [spring, format]);

  return (
    <motion.span className={className}>
      {displayValue || formatValue(value)}
    </motion.span>
  );
}
