import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Settings, X, Volume2, VolumeX, Monitor, Upload, Trash2 } from 'lucide-react';

export function SettingsModal() {
  const [isOpen, setIsOpen] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [prizeImage, setPrizeImage] = useState<string>('');

  useEffect(() => {
    setIsMuted(localStorage.getItem('ranking_muted') === 'true');
    const saved = localStorage.getItem('ranking_prize_image') || '';
    setPrizeImage(saved);
  }, [isOpen]);

  const toggleMute = () => {
    const newState = !isMuted;
    setIsMuted(newState);
    localStorage.setItem('ranking_muted', String(newState));
  };

  const handlePrizeUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (event) => {
        const url = event.target?.result as string;
        setPrizeImage(url);
        localStorage.setItem('ranking_prize_image', url);
        window.location.reload();
      };
      reader.readAsDataURL(file);
    }
  };

  const handleClearPrize = () => {
    setPrizeImage('');
    localStorage.removeItem('ranking_prize_image');
    window.location.reload();
  };

  return (
    <>
      <button 
        onClick={() => setIsOpen(true)}
        className="p-2 rounded-full hover:bg-surface text-gray-400 hover:text-white transition-colors"
      >
        <Settings size={28} />
      </button>

      <AnimatePresence>
        {isOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.95 }}
              className="bg-surface border border-gray-800 rounded-xl shadow-2xl p-6 w-full max-w-md relative"
            >
              <button 
                onClick={() => setIsOpen(false)}
                className="absolute top-4 right-4 text-gray-400 hover:text-white"
              >
                <X size={24} />
              </button>
              
              <h2 className="text-2xl font-bold mb-8 text-white">Configurações</h2>
              
              <div className="space-y-6">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    {isMuted ? <VolumeX className="text-gray-400" size={24} /> : <Volume2 className="text-primary" size={24} />}
                    <div>
                      <h3 className="text-white font-medium text-lg">Efeitos Sonoros</h3>
                      <p className="text-sm text-gray-400">Tocar sons em subidas e metas</p>
                    </div>
                  </div>
                  <button 
                    onClick={toggleMute}
                    className={`relative inline-flex h-8 w-14 items-center rounded-full transition-colors ${!isMuted ? 'bg-primary' : 'bg-gray-600'}`}
                  >
                    <span className={`inline-block h-6 w-6 transform rounded-full bg-white transition ${!isMuted ? 'translate-x-7' : 'translate-x-1'}`} />
                  </button>
                </div>
                
                <hr className="border-gray-800 my-6" />

                <div className="space-y-4">
                  <h3 className="text-white font-medium text-lg">Imagem de Premiação</h3>

                  {prizeImage && (
                    <div className="flex justify-center p-3 bg-gray-800/50 rounded-lg">
                      <img src={prizeImage} alt="Premio" className="max-h-16 object-contain" />
                    </div>
                  )}

                  <label className="flex items-center justify-center px-4 py-3 border-2 border-dashed border-primary/40 rounded-lg cursor-pointer hover:border-primary/60 transition-colors bg-primary/5">
                    <div className="flex items-center gap-2 text-gray-300">
                      <Upload size={18} />
                      <span className="text-sm font-medium">Escolher Imagem</span>
                    </div>
                    <input
                      type="file"
                      accept="image/*"
                      onChange={handlePrizeUpload}
                      className="hidden"
                    />
                  </label>

                  {prizeImage && (
                    <button
                      onClick={handleClearPrize}
                      className="w-full flex items-center justify-center gap-2 bg-red-500/20 hover:bg-red-500/30 text-red-400 font-medium py-2 rounded-lg transition-colors border border-red-500/30 text-sm"
                    >
                      <Trash2 size={16} />
                      Limpar Imagem
                    </button>
                  )}
                </div>

                <hr className="border-gray-800 my-6" />

                <button
                  onClick={() => { window.open('/tv', '_blank'); setIsOpen(false); }}
                  className="w-full flex items-center justify-center gap-2 bg-gray-800 hover:bg-gray-700 text-white font-medium py-3 rounded-lg transition-colors border border-gray-700 text-lg"
                >
                  <Monitor size={24} />
                  Abrir Modo TV
                </button>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </>
  );
}
