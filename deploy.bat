@echo off
cd /d "%~dp0"

git add .
if errorlevel 1 (
    echo ERRO: Falha ao adicionar arquivos.
    pause
    exit /b 1
)

set /p MSG="Mensagem do commit (Enter para 'atualizacao'): "
if "%MSG%"=="" set MSG=atualizacao

git commit -m "%MSG%"
if errorlevel 1 (
    echo Nenhuma alteracao para commitar.
    pause
    exit /b 0
)

git push
if errorlevel 1 (
    echo ERRO: Falha ao enviar para o GitHub.
    pause
    exit /b 1
)

echo.
echo Deploy enviado com sucesso!
echo A Hostinger sera atualizada automaticamente em segundos.
timeout /t 5
