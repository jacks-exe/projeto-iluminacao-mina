@echo off
cd /d C:\projeto-iluminacao-mina\iot
echo Encerrando instancias anteriores da ponte (se houver)...
taskkill /F /IM python.exe /T >nul 2>&1
timeout /t 1 /nobreak >nul

echo Iniciando Ponte IoT GEONEX (115200 bps)...
if exist "C:\Users\Evolabs\AppData\Local\Python\pythoncore-3.14-64\python.exe" (
    "C:\Users\Evolabs\AppData\Local\Python\pythoncore-3.14-64\python.exe" -u ponte_iot.py
) else (
    py -u ponte_iot.py
)
pause
