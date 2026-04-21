@echo off
REM Encaminha para o script em deploy\ (Git add/commit/push na raiz do projeto)
call "%~dp0deploy\deploy.bat"
