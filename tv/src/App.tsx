import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { RankingBoard } from './components/Ranking/RankingBoard';
import { SettingsModal } from './components/Settings/SettingsModal';
import { Trophy } from 'lucide-react';
import { SoundToggle } from './components/SoundToggle/SoundToggle';

function Dashboard() {
  return (
    <div className="h-screen overflow-hidden bg-background text-white font-sans selection:bg-primary/30">
      <div className="fixed inset-0 pointer-events-none">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0c1445] via-background to-background" />
        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] bg-primary/5 blur-[120px] rounded-full" />
      </div>

      <main className="relative z-10 w-full h-full flex flex-col">
        <header className="p-4 lg:px-8 lg:py-5 border-b border-gray-800/30 bg-[#020617]/60 backdrop-blur-xl sticky top-0 z-20 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-primary to-accent flex items-center justify-center shadow-lg shadow-primary/20">
              <Trophy size={22} className="text-white" />
            </div>
            <div>
              <h1 className="text-xl lg:text-2xl font-black tracking-tight text-white">
                Corrida de Vendas
              </h1>
              <p className="text-[11px] tracking-widest uppercase text-gray-500 font-medium -mt-0.5">Ranking em tempo real</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <SoundToggle />
            <SettingsModal />
          </div>
        </header>
        <div className="flex-1 overflow-y-auto overflow-x-hidden">
          <RankingBoard />
        </div>
      </main>
    </div>
  );
}

function TVMode() {
  const now = new Date();
  const monthNames = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

  return (
    <div className="min-h-screen bg-background text-white font-sans overflow-hidden">
      <div className="fixed inset-0 pointer-events-none">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0c1445] via-background to-background" />
        <div className="absolute top-0 left-1/4 w-[600px] h-[300px] bg-primary/8 blur-[100px] rounded-full" />
        <div className="absolute bottom-0 right-1/4 w-[500px] h-[250px] bg-accent/5 blur-[100px] rounded-full" />
      </div>

      <main className="relative z-10 w-full h-screen p-6 lg:p-8 flex flex-col">
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center gap-4">
            <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary to-accent flex items-center justify-center shadow-xl shadow-primary/30">
              <Trophy size={30} className="text-white" fill="currentColor" />
            </div>
            <div>
              <h1 className="text-4xl lg:text-5xl font-black tracking-tight text-white">
                Corrida de Vendas
              </h1>
              <p className="text-sm lg:text-base tracking-widest uppercase text-gray-400 font-medium">
                {monthNames[now.getMonth()]} {now.getFullYear()}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-4">
            <SoundToggle />
            <div className="flex items-center gap-3 bg-[#020617]/60 px-5 py-3 rounded-2xl border border-gray-800/30">
              <div className="w-3 h-3 rounded-full bg-green-500 animate-pulse shadow-lg shadow-green-500/50" />
              <span className="text-xl text-gray-300 font-bold tracking-wide">AO VIVO</span>
            </div>
          </div>
        </div>
        <div className="flex-1 overflow-hidden w-full">
          <RankingBoard />
        </div>
      </main>
    </div>
  );
}

function App() {
  const basename =
    import.meta.env.BASE_URL.replace(/\/$/, '') || undefined;

  return (
    <BrowserRouter basename={basename}>
      <Routes>
        <Route path="/" element={<Dashboard />} />
        <Route path="/tv" element={<TVMode />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
