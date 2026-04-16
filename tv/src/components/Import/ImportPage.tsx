import React, { useState, useRef } from 'react';
import { UploadCloud, CheckCircle, AlertCircle, FileText, ArrowLeft, Loader2 } from 'lucide-react';
import { Link } from 'react-router-dom';

export function ImportPage() {
  const [file, setFile] = useState<File | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [message, setMessage] = useState('');
  const [dragActive, setDragActive] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === "dragenter" || e.type === "dragover") {
      setDragActive(true);
    } else if (e.type === "dragleave") {
      setDragActive(false);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      checkAndSetFile(e.dataTransfer.files[0]);
    }
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    e.preventDefault();
    if (e.target.files && e.target.files[0]) {
      checkAndSetFile(e.target.files[0]);
    }
  };

  const checkAndSetFile = (selectedFile: File) => {
    if (selectedFile.name.endsWith('.csv')) {
      setFile(selectedFile);
      setUploadStatus('idle');
      setMessage('');
    } else {
      setUploadStatus('error');
      setMessage('Por favor, selecione um arquivo .csv válido.');
      setFile(null);
    }
  };

  const onButtonClick = () => {
    inputRef.current?.click();
  };

  const handleUpload = async () => {
    if (!file) return;

    setIsUploading(true);
    setUploadStatus('idle');
    setMessage('');

    const formData = new FormData();
    formData.append('file', file);

    const isDev = import.meta.env.DEV;
    const baseUrl = import.meta.env.VITE_API_URL ? import.meta.env.VITE_API_URL : (isDev ? 'http://localhost/ranking' : '');

    try {
      const response = await fetch(`${baseUrl}/api/upload_csv.php`, {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (response.ok && data.status === 'success') {
        setUploadStatus('success');
        setMessage(`Importação concluída com sucesso! ${data.novos || 0} novos registros atualizados.`);
        setFile(null);
      } else {
        setUploadStatus('error');
        setMessage(data.message || 'Ocorreu um erro desconhecido durante o upload.');
      }
    } catch (error) {
      setUploadStatus('error');
      setMessage('Falha ao conectar com o servidor. Verifique sua conexão.');
    } finally {
      setIsUploading(false);
    }
  };

  return (
    <div className="min-h-screen bg-background text-white font-sans flex flex-col selection:bg-primary/30 relative">
      {/* Background gradients */}
      <div className="fixed inset-0 pointer-events-none">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-[#0c1445] via-background to-background" />
        <div className="absolute top-0 right-1/4 w-[600px] h-[300px] bg-primary/10 blur-[120px] rounded-full" />
      </div>

      <header className="p-4 lg:px-8 border-b border-gray-800/30 bg-[#020617]/60 backdrop-blur-xl relative z-10 flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link to="/" className="w-10 h-10 rounded-xl bg-gray-800/50 hover:bg-gray-700/50 flex items-center justify-center transition-colors border border-gray-700/30">
             <ArrowLeft size={20} className="text-gray-300" />
          </Link>
          <div>
            <h1 className="text-xl lg:text-2xl font-black tracking-tight text-white">
              Importação de Dados
            </h1>
            <p className="text-[11px] tracking-widest uppercase text-gray-400 font-medium -mt-0.5">
              Atualização do ERP Phusion
            </p>
          </div>
        </div>
      </header>

      <main className="flex-1 overflow-y-auto relative z-10 p-6 flex flex-col items-center justify-center">
        <div className="w-full max-w-2xl">
          <div className="bg-[#0f172a]/80 backdrop-blur-md rounded-3xl border border-gray-800/50 p-8 shadow-2xl">
            
            <div className="mb-8 text-center">
               <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-indigo-500/20 to-purple-500/20 flex items-center justify-center border border-indigo-500/30 shadow-lg shadow-indigo-500/10">
                 <UploadCloud className="w-8 h-8 text-indigo-400" />
               </div>
               <h2 className="text-2xl font-bold text-white mb-2">Upload de Planilha CSV</h2>
               <p className="text-gray-400 text-sm">
                 Selecione o arquivo "Relatório de Gestão de Pedidos.csv" extraído do Phusion para atualizar a base de dados do ranking.
               </p>
            </div>

            <div 
              className={`relative border-2 border-dashed rounded-2xl p-10 text-center transition-all duration-200 ${
                dragActive ? 'border-primary bg-primary/5' : 'border-gray-700 hover:border-gray-500 hover:bg-gray-800/30'
              } ${file ? 'bg-gray-800/30 border-gray-600' : ''}`}
              onDragEnter={handleDrag}
              onDragLeave={handleDrag}
              onDragOver={handleDrag}
              onDrop={handleDrop}
            >
              <input 
                 ref={inputRef}
                 type="file" 
                 accept=".csv" 
                 onChange={handleChange} 
                 className="hidden" 
              />

              {file ? (
                <div className="flex flex-col items-center space-y-4">
                  <div className="p-3 bg-indigo-500/10 rounded-xl border border-indigo-500/20">
                    <FileText className="w-10 h-10 text-indigo-400" />
                  </div>
                  <div>
                    <p className="text-lg font-semibold text-gray-200">{file.name}</p>
                    <p className="text-sm text-gray-400">{(file.size / 1024).toFixed(1)} KB</p>
                  </div>
                  <button 
                    onClick={() => setFile(null)}
                    disabled={isUploading}
                    className="text-sm text-red-400 hover:text-red-300 transition-colors disabled:opacity-50"
                  >
                    Remover e escolher outro
                  </button>
                </div>
              ) : (
                <div className="flex flex-col items-center space-y-3 cursor-pointer" onClick={onButtonClick}>
                  <UploadCloud className="w-12 h-12 text-gray-500" />
                  <p className="text-gray-300 font-medium">Arraste e solte seu arquivo aqui</p>
                  <p className="text-gray-500 text-sm">ou</p>
                  <button className="px-5 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-white font-medium transition-colors border border-gray-700">
                    Procurar arquivo
                  </button>
                </div>
              )}
            </div>

            {/* Status Messages */}
            {uploadStatus === 'success' && (
              <div className="mt-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-start gap-3">
                <CheckCircle className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />
                <p className="text-emerald-200 text-sm">{message}</p>
              </div>
            )}

            {uploadStatus === 'error' && (
              <div className="mt-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 flex items-start gap-3">
                <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
                <p className="text-red-200 text-sm">{message}</p>
              </div>
            )}

            <div className="mt-8 flex justify-end gap-4">
              <Link to="/">
                <button 
                  disabled={isUploading}
                  className="px-6 py-3 rounded-xl bg-gray-800/80 hover:bg-gray-700 text-white font-medium transition-colors border border-gray-700 disabled:opacity-50"
                >
                  Cancelar
                </button>
              </Link>
              <button 
                onClick={handleUpload}
                disabled={!file || isUploading}
                className="flex items-center justify-center gap-2 px-8 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-semibold transition-all shadow-lg shadow-indigo-500/20 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isUploading ? (
                  <>
                    <Loader2 className="w-5 h-5 animate-spin" />
                    Enviando...
                  </>
                ) : (
                  'Importar Planilha'
                )}
              </button>
            </div>

          </div>
        </div>
      </main>
    </div>
  );
}
