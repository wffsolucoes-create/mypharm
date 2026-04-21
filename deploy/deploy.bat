@echo off
REM Raiz do projeto = pasta acima de deploy\
cd /d "%~dp0.."

git add -A
if errorlevel 1 (
    echo ERRO: Falha ao adicionar arquivos.
    pause
    exit /b 1
)
echo Arquivos adicionados ao staging.

set /p MSG="Mensagem do commit (Enter para 'atualizacao'): "
if "%MSG%"=="" set MSG=atualizacao

git commit -m "%MSG%"
if errorlevel 1 (
    echo Nenhuma alteracao para commitar.
    pause
    exit /b 0
)
echo Commit criado: %MSG%

git push
if errorlevel 1 (
    echo ERRO: Falha ao enviar para o GitHub.
    pause
    exit /b 1
)

echo.
echo Deploy para o GitHub concluido com sucesso!
echo.
echo Salvando informacoes do deploy no arquivo de log...

:: Criar diretório de logs se não existir
if not exist "deploy-logs" mkdir deploy-logs

:: Log com timestamp
for /f "tokens=2-4 delims=/ " %%a in ('date /t') do (set mydate=%%c-%%a-%%b)
for /f "tokens=1-2 delims=/:" %%a in ('time /t') do (set mytime=%%a-%%b)
set logfile=deploy-logs\deploy_%mydate%_%mytime%.log

echo [%mydate% %mytime%] Commit: %MSG% >> "%logfile%"
echo [%mydate% %mytime%] Branch: >> "%logfile%"

for /f %%i in ('git rev-parse --abbrev-ref HEAD') do echo %%i >> "%logfile%"
echo [%mydate% %mytime%] Ultimo hash: >> "%logfile%"

for /f %%i in ('git rev-parse HEAD') do echo %%i >> "%logfile%"
echo. >> "%logfile%"

echo Log salvo em: %logfile%
echo.
echo Se no hPanel a Hostinger tiver "Deploy por Git" ligado a ESTE repo e ao
echo branch correto (ex.: master), o servidor costuma atualizar sozinho em
echo cerca de 1-2 minutos. Confira em hPanel ^> Git ^> ultimo deploy / logs.
echo Se nada mudar no site, veja se o auto-deploy ao push esta ativo ou rode
echo deploy manual no painel.
echo.
echo IMPORTANTE (Hostinger): apos FTP ou Git deploy, abra o hPanel e use
echo "Purge cache" / "Limpar cache" (LiteSpeed). Sem isso, paginas e APIs
echo podem continuar antigas — parece que nada "salvou" no online.
echo Confirme tambem que os arquivos foram para a pasta correta (ex. public_html).
echo.
timeout /t 8
