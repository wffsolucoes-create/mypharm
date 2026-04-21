import { motion, AnimatePresence } from 'framer-motion';
import { useEffect, useState } from 'react';

interface OvertakeNotificationProps {
  seller: string;
  message: string;
  type?: 'overtake' | 'goal' | 'champion';
}

export function OvertakeNotification({ seller, message, type = 'overtake' }: OvertakeNotificationProps) {
  const [show, setShow] = useState(true);

  useEffect(() => {
    const timer = setTimeout(() => setShow(false), 3000);
    return () => clearTimeout(timer);
  }, []);

  const colors = {
    overtake: {
      bg: 'from-blue-600 to-cyan-400',
      border: 'border-blue-400',
      text: 'text-cyan-100'
    },
    goal: {
      bg: 'from-green-600 to-emerald-400',
      border: 'border-green-400',
      text: 'text-green-100'
    },
    champion: {
      bg: 'from-yellow-600 to-amber-400',
      border: 'border-yellow-400',
      text: 'text-yellow-100'
    }
  };

  const config = colors[type];

  return (
    <AnimatePresence>
      {show && (
        <motion.div
          initial={{ opacity: 0, scale: 0.8, y: -50 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.8, y: -50 }}
          className={`fixed top-1/4 left-1/2 transform -translate-x-1/2 z-50`}
        >
          <div className={`
            bg-gradient-to-r ${config.bg}
            border-2 ${config.border}
            rounded-xl px-8 py-6
            shadow-2xl backdrop-blur-md
            text-center
          `}>
            <motion.div
              animate={{ scale: [1, 1.1, 1] }}
              transition={{ duration: 0.6, repeat: 2 }}
            >
              <p className="text-2xl font-black text-white drop-shadow-lg">
                {message}
              </p>
              <p className={`text-lg font-bold mt-2 ${config.text}`}>
                {seller}
              </p>
            </motion.div>

            {/* Partículas de fundo */}
            {Array.from({ length: 8 }).map((_, i) => (
              <motion.div
                key={i}
                initial={{
                  opacity: 1,
                  x: Math.random() * 100 - 50,
                  y: 0
                }}
                animate={{
                  opacity: 0,
                  x: Math.random() * 200 - 100,
                  y: 150
                }}
                transition={{ duration: 2, delay: i * 0.1 }}
                className="absolute w-2 h-2 bg-white rounded-full pointer-events-none"
                style={{
                  left: '50%',
                  top: '50%'
                }}
              />
            ))}
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
