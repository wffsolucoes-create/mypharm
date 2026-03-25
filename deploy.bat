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
echo Push para o GitHub concluido com sucesso!
echo.
echo Se no hPanel a Hostinger tiver "Deploy por Git" ligado a ESTE repo e ao
echo branch correto (ex.: master), o servidor costuma atualizar sozinho em
echo cerca de 1-2 minutos. Confira em hPanel ^> Git ^> ultimo deploy / logs.
echo Se nada mudar no site, veja se o auto-deploy ao push esta ativo ou rode
echo deploy manual no painel.
echo.
timeout /t 8
