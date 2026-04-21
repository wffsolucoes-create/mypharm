import { useState } from "react";
import { Volume2, VolumeX } from "lucide-react";

export function SoundToggle() {
  const [isMuted, setIsMuted] = useState(() => {
    return localStorage.getItem("ranking_muted") === "true";
  });

  const toggleMute = () => {
    const newState = !isMuted;
    setIsMuted(newState);
    localStorage.setItem("ranking_muted", newState.toString());
    // Dispara um evento customizado para que outros hooks possam reagir sem re-renderizar todo o app
    window.dispatchEvent(new Event("soundSettingsChanged"));
  };

  return (
    <button
      onClick={toggleMute}
      className={`flex items-center gap-2 px-3 py-2 rounded-xl transition-all duration-300 border ${
        isMuted 
          ? "bg-red-500/10 border-red-500/30 text-red-400 hover:bg-red-500/20" 
          : "bg-primary/10 border-primary/30 text-primary-light hover:bg-primary/20"
      }`}
      title={isMuted ? "Som Mutado" : "Som Ativado"}
    >
      {isMuted ? <VolumeX size={18} /> : <Volume2 size={18} />}
    </button>
  );
}
